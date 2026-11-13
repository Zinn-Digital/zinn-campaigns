<?php
/**
 * The Zinn® Campaigns admin screen: connect, the account's plan, and how to add a sign-up form.
 *
 * @package ZinnCampaigns
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen.
 */
final class Zinn_Campaigns_Admin {

	/** Cached status read (five minutes), so opening the screen is not an API call each time. */
	public const STATUS_CACHE = 'zinn_campaigns_status';

	/** Where a signed-in customer downloads the Pro add-on in the Zinn® dashboard. */
	public const PRO_DOWNLOAD_PATH = '/plugins';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_zinn_campaigns_refresh', array( self::class, 'refresh' ) );
	}

	/**
	 * The menu entry. Position 81: below Settings, never near the top (wp.org review 2.13).
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'Zinn® Campaigns', 'zinn-campaigns' ),
			__( 'Zinn® Campaigns', 'zinn-campaigns' ),
			Zinn_Campaigns_Connect::CAPABILITY,
			Zinn_Campaigns_Connect::PAGE,
			array( self::class, 'render' ),
			'dashicons-email-alt',
			81
		);
	}

	/**
	 * The connection as Zinn® sees it, or null when there is none (a revoked token is forgotten).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function status(): ?array {
		if ( '' === Zinn_Campaigns_Api::token() ) {
			return null;
		}
		$cached = get_transient( self::STATUS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		list( $code, $body ) = Zinn_Campaigns_Api::call(
			'GET',
			'/v1/store-connect/status?' . http_build_query(
				array(
					'plugin_version' => ZINN_CAMPAIGNS_VERSION,
					'wp_version'     => get_bloginfo( 'version' ),
					'integrations'   => implode( ',', Zinn_Campaigns_Api::integrations() ),
					'languages'      => Zinn_Campaigns_Api::languages( 400 ),
				)
			)
		);
		if ( 401 === $code ) {
			// Disconnected from the dashboard: show Connect again.
			Zinn_Campaigns_Api::set_token( '' );
			Zinn_Campaigns_Plan::forget();
			return null;
		}
		if ( 200 !== $code ) {
			return array( 'unreachable' => true );
		}
		set_transient( self::STATUS_CACHE, $body, 5 * MINUTE_IN_SECONDS );
		return $body;
	}

	/**
	 * The Refresh button: read the connection and the plan again now.
	 */
	public static function refresh(): void {
		if ( ! current_user_can( Zinn_Campaigns_Connect::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Zinn® Campaigns on this site.', 'zinn-campaigns' ), 403 );
		}
		check_admin_referer( 'zinn_campaigns_refresh' );
		delete_transient( self::STATUS_CACHE );
		Zinn_Campaigns_Plan::forget();
		wp_safe_redirect( Zinn_Campaigns_Connect::page_url() );
		exit;
	}

	/**
	 * A one-line notice after a redirect.
	 */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks which fixed sentence to show.
		$key      = isset( $_GET['zc_notice'] ) ? sanitize_key( wp_unslash( $_GET['zc_notice'] ) ) : '';
		$messages = array(
			'connected'    => array( 'success', __( 'This site is connected to Zinn® Campaigns.', 'zinn-campaigns' ) ),
			'disconnected' => array( 'info', __( 'This site is disconnected. Sign-up forms stop sending new sign-ups to Zinn® Campaigns.', 'zinn-campaigns' ) ),
			'expired'      => array( 'warning', __( 'That connection took too long or was started in another browser. Press Connect again.', 'zinn-campaigns' ) ),
			'refused'      => array( 'error', __( 'Zinn® did not accept the connection. Press Connect again; if it keeps failing, contact Zinn® support.', 'zinn-campaigns' ) ),
			'unreachable'  => array( 'error', __( 'Your site could not reach Zinn®. Check that your host allows outgoing connections, then press Connect again.', 'zinn-campaigns' ) ),
		);
		if ( isset( $messages[ $key ] ) ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $messages[ $key ][0] ),
				esc_html( $messages[ $key ][1] )
			);
		}
	}

	/**
	 * A POST button to an admin-post action.
	 *
	 * @param string $action  The action name.
	 * @param string $label   The button text.
	 * @param string $classes Button classes.
	 */
	private static function button( string $action, string $label, string $classes ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<p><button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * One row of the status table.
	 *
	 * @param string $label The row label.
	 * @param string $value The row value (plain text).
	 */
	private static function row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * The Pro panel: what the account's plan includes, in plain words. No feature is locked here.
	 */
	private static function plan_panel(): void {
		$plan = Zinn_Campaigns_Plan::get();
		// The add-on's name is a brand, so it is never a msgid: a brand-only string cannot pass the
		// translator's script check in a non-Latin locale (Tajik refused it). The words around it translate.
		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %s: the add-on plugin's brand name, Zinn® Campaigns Pro. */
				__( 'The %s add-on', 'zinn-campaigns' ),
				'Zinn® Campaigns Pro'
			)
		) . '</h2>';
		echo '<p>' . esc_html__( 'You get Pro free with any paid Zinn® Campaigns plan: there is no separate licence to buy. Pro is a separate add-on plugin you download from your Zinn® dashboard.', 'zinn-campaigns' ) . '</p>';
		if ( 'active' === $plan['pro'] ) {
			$line = __( 'Included: your account has a paid Zinn® Campaigns plan.', 'zinn-campaigns' );
		} elseif ( 'trial' === $plan['pro'] ) {
			$ends = strtotime( $plan['trial_ends_at'] );
			/* translators: %s: the date the free Pro trial ends. */
			$line = sprintf( __( 'Free trial until %s. Pick any paid Zinn® Campaigns plan to keep Pro after that.', 'zinn-campaigns' ), $ends ? wp_date( (string) get_option( 'date_format' ), $ends ) : '' );
		} elseif ( 'lapsed' === $plan['pro'] ) {
			$line = __( 'Not included right now: the free trial has ended or the plan is not paid. Your Pro settings are kept and come back the moment a paid plan is live.', 'zinn-campaigns' );
		} else {
			$line = __( 'Connect this site to see what your plan includes.', 'zinn-campaigns' );
		}
		echo '<p><strong>' . esc_html( $line ) . '</strong></p>';
		echo '<p><a class="button" href="' . esc_url( zinn_campaigns_app_base() . self::PRO_DOWNLOAD_PATH ) . '" target="_blank" rel="noopener">' . esc_html__( 'Download the Pro add-on', 'zinn-campaigns' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( Zinn_Campaigns_Plan::upgrade_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Zinn® Campaigns plans', 'zinn-campaigns' ) . '</a></p>';
	}

	/**
	 * How to add a sign-up form, with the shortcode for each of the account's forms.
	 */
	private static function forms_panel(): void {
		echo '<h2>' . esc_html__( 'Sign-up forms', 'zinn-campaigns' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add the "Zinn® Campaigns sign-up form" block to any page or post, or paste a shortcode below. Every sign-up gets a confirmation e-mail first (double opt-in), and only people who confirm join your list.', 'zinn-campaigns' ) . '</p>';
		$forms = Zinn_Campaigns_Forms::forms();
		if ( array() === $forms ) {
			echo '<p>' . esc_html__( 'Your account has no sign-up forms yet. Create one in your Zinn® dashboard under Campaigns, Forms, then press Refresh.', 'zinn-campaigns' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Form', 'zinn-campaigns' ) . '</th><th>' . esc_html__( 'List', 'zinn-campaigns' ) . '</th><th>' . esc_html__( 'Shortcode', 'zinn-campaigns' ) . '</th></tr></thead><tbody>';
		foreach ( $forms as $form ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td><code>%3$s</code></td></tr>',
				esc_html( (string) $form['title'] ),
				esc_html( (string) $form['list_name'] ),
				esc_html( '[zinn_campaigns_form id="' . $form['id'] . '"]' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * The screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Zinn_Campaigns_Connect::CAPABILITY ) ) {
			return;
		}
		$status = self::status();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Zinn® Campaigns', 'zinn-campaigns' ) . '</h1>';
		self::notice();
		if ( null === $status ) {
			echo '<p>' . esc_html__( 'Grow your list on this site with double opt-in sign-up forms, and send email, SMS and WhatsApp campaigns from your Zinn® Campaigns account.', 'zinn-campaigns' ) . '</p>';
			echo '<p>' . esc_html__( 'Connect opens Zinn® in this window. Sign in or create an account, approve this site, and you come straight back here. You never paste a key.', 'zinn-campaigns' ) . '</p>';
			self::button( 'zinn_campaigns_connect', __( 'Connect to Zinn® Campaigns', 'zinn-campaigns' ), 'button button-primary' );
			self::plan_panel();
			Zinn_Campaigns_Promo::render_panel();
			echo '</div>';
			return;
		}
		if ( ! empty( $status['unreachable'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Connected, but Zinn® could not be reached just now. We will try again shortly.', 'zinn-campaigns' ) . '</p></div>';
		} else {
			$connected = strtotime( (string) ( $status['connected_at'] ?? '' ) );
			echo '<table class="form-table" role="presentation"><tbody>';
			self::row( __( 'Site', 'zinn-campaigns' ), (string) ( $status['site_host'] ?? '' ) );
			self::row( __( 'Connected', 'zinn-campaigns' ), $connected ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $connected ) : '' );
			self::row(
				__( 'Zinn® Campaigns', 'zinn-campaigns' ),
				! empty( $status['campaigns_open'] )
					? __( 'Open for your account', 'zinn-campaigns' )
					: __( 'Not open for your account yet. Zinn® will e-mail you when it is.', 'zinn-campaigns' )
			);
			echo '</tbody></table>';
		}
		self::button( 'zinn_campaigns_refresh', __( 'Refresh', 'zinn-campaigns' ), 'button' );
		self::plan_panel();
		self::forms_panel();
		self::button( 'zinn_campaigns_disconnect', __( 'Disconnect', 'zinn-campaigns' ), 'button' );
		Zinn_Campaigns_Promo::render_panel();
		echo '</div>';
	}
}
