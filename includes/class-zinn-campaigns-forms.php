<?php
/**
 * Double opt-in sign-up forms: the `[zinn_campaigns_form]` shortcode, the
 * `zinn-campaigns/form` block, and the handler that hands one sign-up to Zinn® Campaigns.
 *
 * The form is the account's own Zinn® Campaigns form (its words, its consent sentence, its list),
 * so what a visitor agrees to is exactly what Zinn® records. ⛔ Nothing is stored on this site: the
 * handler posts the address to the Zinn® API, which sends ONE confirmation e-mail, and nobody joins
 * the list until they click it (docs/1052 §10f, §11.9b).
 *
 * @package ZinnCampaigns
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sign-up forms.
 */
final class Zinn_Campaigns_Forms {

	/** The shortcode tag. */
	public const SHORTCODE = 'zinn_campaigns_form';

	/** The block name. */
	public const BLOCK = 'zinn-campaigns/form';

	/** The editor script handle. */
	public const HANDLE = 'zinn-campaigns-form-block';

	/** The admin-post action the form posts to. */
	public const ACTION = 'zinn_campaigns_signup';

	/** The nonce field a signed-in visitor's form carries (pages are not cached for them). */
	public const NONCE = 'zc_nonce';

	/** The block inserter category shared by every Zinn® plugin. */
	public const CATEGORY = 'zinn';

	/** Cached list of the account's forms (ten minutes). */
	public const CACHE = 'zinn_campaigns_forms';

	/** A real person takes at least this long to type an address (seconds). */
	public const MIN_AGE = 2;

	/** A drawn form stays valid this long (seconds), so a cached page keeps working for a day. */
	public const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'block_categories_all', array( self::class, 'categories' ), 10, 1 );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_signed_in' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_data' ) );
		self::register_block();
	}

	/**
	 * The account's enabled forms, as the Zinn® API lists them. Empty when not connected.
	 *
	 * @return array<int, array{id: string, title: string, intro: string, button_label: string, consent_text: string, list_name: string}>
	 */
	public static function forms(): array {
		if ( '' === Zinn_Campaigns_Api::token() ) {
			return array();
		}
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		list( $code, $body ) = Zinn_Campaigns_Api::call( 'GET', '/v1/store-connect/forms' );
		$forms               = array();
		if ( 200 === $code ) {
			foreach ( (array) ( $body['forms'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) || '' === (string) ( $row['id'] ?? '' ) ) {
					continue;
				}
				$forms[] = array(
					'id'           => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $row['id'] ),
					'title'        => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
					'intro'        => sanitize_text_field( (string) ( $row['intro'] ?? '' ) ),
					'button_label' => sanitize_text_field( (string) ( $row['button_label'] ?? '' ) ),
					'consent_text' => sanitize_text_field( (string) ( $row['consent_text'] ?? '' ) ),
					'list_name'    => sanitize_text_field( (string) ( $row['list_name'] ?? '' ) ),
				);
			}
		}
		set_transient( self::CACHE, $forms, 200 === $code ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		return $forms;
	}

	/**
	 * One form by its public id, or null.
	 *
	 * @param string $id The form's public id.
	 * @return array<string, string>|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::forms() as $form ) {
			if ( $form['id'] === $id ) {
				return $form;
			}
		}
		return null;
	}

	/**
	 * The "Zinn Digital®" inserter category, shared with every Zinn® plugin (added once).
	 *
	 * @param mixed $categories The block categories.
	 * @return mixed
	 */
	public static function categories( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}
		if ( ! in_array( self::CATEGORY, array_column( $categories, 'slug' ), true ) ) {
			$categories[] = array(
				'slug'  => self::CATEGORY,
				'title' => __( 'Zinn Digital®', 'zinn-campaigns' ),
				'icon'  => null,
			);
		}
		return $categories;
	}

	/**
	 * The dynamic block (rendered on the server; the editor previews it the same way).
	 */
	private static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			self::HANDLE,
			plugins_url( 'assets/form-block.js', ZINN_CAMPAIGNS_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			ZINN_CAMPAIGNS_VERSION,
			true
		);
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Zinn® Campaigns sign-up form', 'zinn-campaigns' ),
				'category'        => self::CATEGORY,
				'editor_script'   => self::HANDLE,
				'attributes'      => array(
					'formId' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array( 'html' => false ),
				'render_callback' => array( self::class, 'render_block' ),
			)
		);
	}

	/**
	 * The editor's strings and the form picker, translated in PHP (so the catalogues carry them).
	 */
	public static function editor_data(): void {
		$options = array(
			array(
				'value' => '',
				'label' => __( 'Choose a form', 'zinn-campaigns' ),
			),
		);
		foreach ( self::forms() as $form ) {
			$options[] = array(
				'value' => $form['id'],
				'label' => $form['title'],
			);
		}
		$data = array(
			'title'       => __( 'Zinn® Campaigns sign-up form', 'zinn-campaigns' ),
			'description' => __( 'A double opt-in sign-up form from your Zinn® Campaigns account.', 'zinn-campaigns' ),
			'settings'    => __( 'Form', 'zinn-campaigns' ),
			'form'        => __( 'Sign-up form', 'zinn-campaigns' ),
			'forms'       => $options,
		);
		wp_add_inline_script( self::HANDLE, 'window.zinnCampaignsForm = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	/**
	 * The block's server render.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_block( array $attributes ): string {
		return self::form( (string) ( $attributes['formId'] ?? '' ) );
	}

	/**
	 * The shortcode: `[zinn_campaigns_form id="<form id>"]`.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		return self::form( (string) $atts['id'] );
	}

	/**
	 * The signed form token: the form and the moment it was drawn.
	 *
	 * @param string $form_id The form's public id.
	 * @param int    $ts      Unix time the form was drawn.
	 */
	public static function token( string $form_id, int $ts ): string {
		return wp_hash( self::ACTION . '|' . $form_id . '|' . $ts, 'nonce' );
	}

	/**
	 * `ok`, `forged`, `early` (faster than a person) or `expired`.
	 *
	 * @param string $form_id The form's public id.
	 * @param int    $ts      The drawn time posted back.
	 * @param string $token   The token posted back.
	 * @param int    $now     Now.
	 */
	public static function token_state( string $form_id, int $ts, string $token, int $now ): string {
		if ( ! hash_equals( self::token( $form_id, $ts ), $token ) ) {
			return 'forged';
		}
		if ( $now - $ts < self::MIN_AGE ) {
			return 'early';
		}
		return $now - $ts > self::MAX_AGE ? 'expired' : 'ok';
	}

	/**
	 * The form markup. Nothing for visitors until the site is connected and the form exists (an
	 * administrator sees why instead).
	 *
	 * @param string $form_id The form's public id.
	 */
	public static function form( string $form_id ): string {
		$form = '' === $form_id ? null : self::find( $form_id );
		if ( null === $form ) {
			if ( current_user_can( Zinn_Campaigns_Connect::CAPABILITY ) ) {
				return '<p class="zinn-campaigns-form__note">' . esc_html__( 'Zinn® Campaigns: connect this site and choose one of your forms to show a sign-up form here.', 'zinn-campaigns' ) . '</p>';
			}
			return '';
		}
		$ts = time();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: which fixed sentence to show after the redirect.
		$done   = isset( $_GET['zc_form'] ) && sanitize_key( wp_unslash( $_GET['zc_form'] ) ) === $form['id'];
		$button = '' !== $form['button_label'] ? $form['button_label'] : __( 'Sign up', 'zinn-campaigns' );
		$html   = '<form class="zinn-campaigns-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		if ( '' !== $form['title'] ) {
			$html .= '<p class="zinn-campaigns-form__title"><strong>' . esc_html( $form['title'] ) . '</strong></p>';
		}
		if ( $done ) {
			$html .= '<p class="zinn-campaigns-form__done" role="status">' . esc_html__( 'Thank you. Check your inbox and click the link in our e-mail to confirm.', 'zinn-campaigns' ) . '</p></form>';
			return $html;
		}
		if ( '' !== $form['intro'] ) {
			$html .= '<p>' . esc_html( $form['intro'] ) . '</p>';
		}
		$field = 'zc-email-' . $form['id'];
		$tick  = 'zc-consent-' . $form['id'];
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		$html .= '<input type="hidden" name="zc_form" value="' . esc_attr( $form['id'] ) . '" />';
		$html .= '<input type="hidden" name="zc_ts" value="' . esc_attr( (string) $ts ) . '" />';
		$html .= '<input type="hidden" name="zc_token" value="' . esc_attr( self::token( $form['id'], $ts ) ) . '" />';
		$html .= '<input type="hidden" name="zc_page" value="' . esc_attr( (string) get_permalink() ) . '" />';
		if ( is_user_logged_in() ) {
			$html .= wp_nonce_field( self::ACTION, self::NONCE, false, false );
		}
		// A field a person never sees; a bot that fills every field fills this one too.
		$html .= '<p class="zinn-campaigns-form__hp" aria-hidden="true" style="display:none"><label>' . esc_html__( 'Leave this empty', 'zinn-campaigns' ) . ' <input type="text" name="zc_website" tabindex="-1" autocomplete="off" /></label></p>';
		$html .= '<p><label for="' . esc_attr( $field ) . '">' . esc_html__( 'E-mail address', 'zinn-campaigns' ) . '</label><br /><input type="email" id="' . esc_attr( $field ) . '" name="zc_email" required autocomplete="email" /></p>';
		// ⛔ Unticked by default and required: the visitor's own tick is the consent Zinn® records.
		$html .= '<p><label for="' . esc_attr( $tick ) . '"><input type="checkbox" id="' . esc_attr( $tick ) . '" name="zc_consent" value="1" required /> ' . esc_html( $form['consent_text'] ) . '</label></p>';
		$html .= '<p><button type="submit">' . esc_html( $button ) . '</button></p>';
		$html .= '</form>';
		return $html;
	}

	/**
	 * `admin-post.php` for a signed-in visitor: their session could be forged into, so this one
	 * needs the `read` capability and a valid nonce before the same checks as everyone else.
	 */
	public static function handle_signed_in(): void {
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the nonce, verified next.
		if ( ! current_user_can( 'read' ) || false === wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		self::handle();
	}

	/**
	 * The posted sign-up. Validates the signed token, the honeypot and the ticked box, then hands
	 * the address to Zinn® and sends the visitor back to the page.
	 *
	 * ⛔ No nonce on purpose: the form is drawn into pages a cache serves to strangers, and a nonce
	 * would expire inside the cache. The signed, time-bound token above is this form's CSRF check,
	 * and the double opt-in e-mail is why a forged sign-up cannot add anybody to a list.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see the docblock: the signed zc_token is this handler's check.
		$form_id = isset( $_POST['zc_form'] ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( wp_unslash( $_POST['zc_form'] ) ) ) : '';
		$ts      = isset( $_POST['zc_ts'] ) ? absint( wp_unslash( $_POST['zc_ts'] ) ) : 0;
		$token   = isset( $_POST['zc_token'] ) ? sanitize_text_field( wp_unslash( $_POST['zc_token'] ) ) : '';
		$email   = isset( $_POST['zc_email'] ) ? sanitize_email( wp_unslash( $_POST['zc_email'] ) ) : '';
		$consent = ! empty( $_POST['zc_consent'] );
		$trap    = isset( $_POST['zc_website'] ) ? sanitize_text_field( wp_unslash( $_POST['zc_website'] ) ) : '';
		$page    = isset( $_POST['zc_page'] ) ? esc_url_raw( wp_unslash( $_POST['zc_page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$back = wp_validate_redirect( $page, home_url( '/' ) );
		if ( self::accept( $form_id, $ts, $token, $email, $consent, $trap ) ) {
			Zinn_Campaigns_Api::call(
				'POST',
				'/v1/store-connect/forms',
				array(
					'form_id'     => $form_id,
					'email'       => $email,
					'source_page' => $back,
					'client_ip'   => self::client_ip(),
					'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
				)
			);
		}
		// Every outcome reads the same to the visitor (no oracle), except a broken form.
		wp_safe_redirect( add_query_arg( 'zc_form', $form_id, $back ) );
		exit;
	}

	/**
	 * Whether a posted sign-up may go to Zinn®: connected, a known form, a valid token, an empty
	 * honeypot, a valid address and the ticked box.
	 *
	 * @param string $form_id The form's public id.
	 * @param int    $ts      The drawn time.
	 * @param string $token   The signed token.
	 * @param string $email   The address.
	 * @param bool   $consent The box was ticked.
	 * @param string $trap    The honeypot field.
	 */
	public static function accept( string $form_id, int $ts, string $token, string $email, bool $consent, string $trap ): bool {
		if ( '' === Zinn_Campaigns_Api::token() || '' !== $trap || ! $consent || ! is_email( $email ) ) {
			return false;
		}
		if ( 'ok' !== self::token_state( $form_id, $ts, $token, time() ) ) {
			return false;
		}
		return null !== self::find( $form_id );
	}

	/**
	 * The visitor's address as this server saw it (REMOTE_ADDR only; a forwarded header is not
	 * trusted). Kept by Zinn® as consent evidence.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
