<?php
/**
 * WooCommerce store events for Zinn® Campaigns e-mail flows (docs/1052 §11.9a).
 *
 * The store tells Zinn® Campaigns what a shopper did, so a flow the store owner switched on can
 * start or stop for that shopper:
 *
 * - `cart_abandoned`: a cart left alone for an hour (the delay is a filter), found by a sweep in
 *   the background (Action Scheduler), never in a shopper's request. One cart fires once.
 * - `product_viewed`: a product page seen by a shopper whose address and consent the store has.
 * - `cart_recovered`: a cart that was reported abandoned turned into an order.
 * - `order_placed` / `payment_confirmed`: an order was placed / paid.
 *
 * ⛔ Nothing is sent about a shopper who did not tick the e-mail box at checkout (or tick it on
 * an earlier order while signed in): the box is unticked by default, its wording and version go
 * with the event as the proof of consent, and the time it was ticked is never later than the event.
 * ⛔ The plugin administers nothing from outside the site (`scripts/wp-no-remote-admin.py`).
 *
 * @package ZinnCampaigns
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Captures the shopper's address + consent, keeps one row per cart, and sends the events.
 */
final class Zinn_Campaigns_Store_Events {

	/** The classic checkout field name. */
	public const FIELD = 'zinn_campaigns_email_optin';

	/** The block checkout additional field id. */
	public const BLOCK_ID = 'zinn-campaigns/email-optin';

	/** Order meta: the shopper ticked the box on this order. */
	public const OPTIN_META = '_zinn_campaigns_email_optin';

	/** Order meta: the cart this order came from. */
	public const CART_META = '_zinn_campaigns_cart';

	/** User meta: when a signed-in shopper last ticked the box (ISO 8601 UTC). */
	public const USER_CONSENT_META = 'zinn_campaigns_email_consent_at';

	/** The wording version; bump it whenever label() changes. */
	public const WORDING_V = '1';

	/** Action Scheduler hook: the abandoned-cart sweep. */
	public const SWEEP_HOOK = 'zinn_campaigns_cart_sweep';

	/** Action Scheduler hook: send one event. */
	public const SEND_HOOK = 'zinn_campaigns_send_event';

	/** Action Scheduler group. */
	public const GROUP = 'zinn-campaigns';

	/** Option: the carts table schema version. */
	public const DB_OPTION = 'zinn_campaigns_carts_db';

	/** The carts table schema version. */
	public const DB_VERSION = '1';

	/** WC session keys. */
	private const S_CART    = 'zinn_campaigns_cart';
	private const S_EMAIL   = 'zinn_campaigns_email';
	private const S_FIRST   = 'zinn_campaigns_first_name';
	private const S_CONSENT = 'zinn_campaigns_consent_at';
	private const S_VIEWED  = 'zinn_campaigns_viewed';

	/** Seconds between sweeps. */
	private const SWEEP_EVERY = 300;

	/** The engine enrols nothing from an event older than this (store_triggers.MAX_AGE). */
	private const MAX_AGE = 2 * DAY_IN_SECONDS;

	/** Rows older than this are deleted (the cart, its address and its consent leave the store). */
	private const KEEP = 30 * DAY_IN_SECONDS;

	/** The engine's batch limit (POST /v1/store-connect/events). */
	private const BATCH = 100;

	/** Retries of a failed single send. */
	private const RETRIES = 5;

	private const ZERO_DECIMAL  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
	private const THREE_DECIMAL = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Hooks. The send and sweep jobs are always registered, so a queued job still runs after a
	 * disconnect (and then sends nothing).
	 */
	public static function register(): void {
		add_action( self::SEND_HOOK, array( self::class, 'deliver' ), 10, 2 );
		add_action( self::SWEEP_HOOK, array( self::class, 'sweep' ) );
		if ( ! self::active() ) {
			return;
		}
		self::maybe_install();
		// Capture: classic checkout.
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'classic_field' ) );
		add_filter( 'woocommerce_checkout_fields', array( self::class, 'watch_email' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( self::class, 'on_order_review' ) );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'on_classic_checkout' ), 25, 1 );
		// Capture: block checkout (WooCommerce 8.9+ for the field; the hooks are older).
		if ( did_action( 'woocommerce_init' ) ) {
			self::block_field();
		} else {
			add_action( 'woocommerce_init', array( self::class, 'block_field' ) );
		}
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( self::class, 'on_store_api_request' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_draft', array( self::class, 'on_store_api_draft' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'on_store_api_order' ), 20, 2 );
		// The cart itself.
		add_action( 'woocommerce_cart_updated', array( self::class, 'on_cart_updated' ) );
		add_action( 'woocommerce_cart_emptied', array( self::class, 'on_cart_emptied' ) );
		add_action( 'wp_loaded', array( self::class, 'maybe_restore' ), 30 );
		add_action( 'woocommerce_after_single_product', array( self::class, 'on_product_view' ) );
		// Orders.
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_order_created' ), 30, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_order_created' ), 30, 1 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_paid' ), 30, 1 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'on_paid' ), 30, 1 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_paid' ), 30, 1 );
	}

	/**
	 * WooCommerce is active, the site is connected, and the store owner did not switch it off.
	 */
	public static function active(): bool {
		if ( ! class_exists( 'WooCommerce' ) || '' === Zinn_Campaigns_Api::token() ) {
			return false;
		}
		/**
		 * Filters whether the store sends cart, product and order events to Zinn® Campaigns
		 * (and shows the e-mail opt-in box at checkout).
		 *
		 * @param bool $on Default true.
		 */
		return (bool) apply_filters( 'zinn_campaigns_store_events', true );
	}

	/**
	 * The opt-in label, translated. Bump WORDING_V when it changes.
	 */
	public static function label(): string {
		return __( 'Email me offers and reminders, including about items left in my cart. I can unsubscribe at any time.', 'zinn-campaigns' );
	}

	/**
	 * The carts table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zinn_campaigns_carts';
	}

	/**
	 * Creates or updates the carts table (once per schema version).
	 */
	public static function maybe_install(): void {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  cart_key char(32) NOT NULL,
  email varchar(254) NOT NULL DEFAULT '',
  first_name varchar(40) NOT NULL DEFAULT '',
  locale varchar(16) NOT NULL DEFAULT '',
  consent_at datetime NULL DEFAULT NULL,
  contents longtext NOT NULL,
  total_minor bigint(20) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  state varchar(12) NOT NULL DEFAULT 'open',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (cart_key),
  KEY state_updated (state,updated_at)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/*
	 * ------------------------------------------------------------------ capture: the opt-in box
	 */

	/**
	 * Classic checkout: the unticked box above Place order. `update_totals_on_change` makes
	 * WooCommerce refresh the order review when it changes, so the tick reaches the server before
	 * the shopper leaves.
	 */
	public static function classic_field(): void {
		if ( ! function_exists( 'woocommerce_form_field' ) ) {
			return;
		}
		woocommerce_form_field(
			self::FIELD,
			array(
				'type'     => 'checkbox',
				'class'    => array( 'form-row-wide', 'update_totals_on_change' ),
				'label'    => esc_html( self::label() ),
				'required' => false,
				'default'  => 0,
			),
			0
		);
	}

	/**
	 * Classic checkout: refresh the order review when the e-mail address changes, so the address
	 * is captured before the order is placed.
	 *
	 * @param array<string, mixed> $fields The checkout fields.
	 * @return array<string, mixed>
	 */
	public static function watch_email( $fields ) {
		if ( is_array( $fields ) && isset( $fields['billing']['billing_email'] ) && is_array( $fields['billing']['billing_email'] ) ) {
			$class = (array) ( $fields['billing']['billing_email']['class'] ?? array() );
			if ( ! in_array( 'update_totals_on_change', $class, true ) ) {
				$class[] = 'update_totals_on_change';
			}
			$fields['billing']['billing_email']['class'] = $class;
		}
		return $fields;
	}

	/**
	 * Block checkout: an additional checkout field beside the e-mail address.
	 */
	public static function block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::BLOCK_ID,
					'label'    => self::label(),
					'location' => 'contact',
					'type'     => 'checkbox',
				)
			);
		} catch ( \Throwable $e ) {
			return; // Registered twice, or an older WooCommerce: the classic box still works.
		}
	}

	/**
	 * Classic checkout AJAX (`update_order_review`, nonce checked by WooCommerce).
	 *
	 * @param string $post_data The serialised checkout form.
	 */
	public static function on_order_review( $post_data ): void {
		$form = array();
		wp_parse_str( (string) $post_data, $form );
		self::capture(
			(string) ( $form['billing_email'] ?? '' ),
			(string) ( $form['billing_first_name'] ?? '' ),
			! empty( $form[ self::FIELD ] )
		);
	}

	/**
	 * Classic checkout: keep the tick and the cart on the order (saved by WooCommerce right after).
	 *
	 * @param object $order The WC_Order.
	 */
	public static function on_classic_checkout( $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		$ticked = ! empty( $_POST[ self::FIELD ] );
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		if ( $ticked ) {
			$order->update_meta_data( self::OPTIN_META, 'yes' );
		}
		$cart = self::session_get( self::S_CART );
		if ( '' !== $cart ) {
			$order->update_meta_data( self::CART_META, $cart );
		}
	}

	/**
	 * Block checkout: the shopper's address, as it is typed (`cart/update-customer`).
	 *
	 * @param object $customer The WC_Customer.
	 * @param object $request  The WP_REST_Request.
	 */
	public static function on_store_api_request( $customer, $request ): void {
		unset( $customer );
		self::from_request( $request );
	}

	/**
	 * Block checkout: the form, before an order exists (WooCommerce 10.8+ PATCH).
	 *
	 * @param object $request The WP_REST_Request.
	 */
	public static function on_store_api_draft( $request ): void {
		self::from_request( $request );
	}

	/**
	 * Block checkout: the form against the draft or the placed order.
	 *
	 * @param object $order   The WC_Order.
	 * @param object $request The WP_REST_Request.
	 */
	public static function on_store_api_order( $order, $request ): void {
		$ticked = self::from_request( $request );
		if ( is_object( $order ) && method_exists( $order, 'update_meta_data' ) ) {
			if ( true === $ticked ) {
				$order->update_meta_data( self::OPTIN_META, 'yes' );
			}
			$cart = self::session_get( self::S_CART );
			if ( '' !== $cart ) {
				$order->update_meta_data( self::CART_META, $cart );
			}
		}
	}

	/**
	 * Reads billing e-mail, first name and the box from a Store API request.
	 *
	 * @param object $request The WP_REST_Request.
	 * @return bool|null The box's state, or null when the request did not carry it.
	 */
	private static function from_request( $request ): ?bool {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return null;
		}
		$billing = $request->get_param( 'billing_address' );
		$billing = is_array( $billing ) ? $billing : array();
		$extra   = $request->get_param( 'additional_fields' );
		$ticked  = ( is_array( $extra ) && array_key_exists( self::BLOCK_ID, $extra ) ) ? ! empty( $extra[ self::BLOCK_ID ] ) : null;
		self::capture( (string) ( $billing['email'] ?? '' ), (string) ( $billing['first_name'] ?? '' ), $ticked );
		return $ticked;
	}

	/**
	 * Keeps what the shopper typed in the WC session, then updates the cart row.
	 *
	 * @param string    $email  The address ('' = unchanged).
	 * @param string    $first  The first name ('' = unchanged).
	 * @param bool|null $ticked The box (null = unchanged).
	 */
	public static function capture( string $email, string $first, ?bool $ticked ): void {
		if ( ! self::session() ) {
			return;
		}
		$email = sanitize_email( $email );
		if ( '' !== $email && is_email( $email ) ) {
			if ( strtolower( $email ) !== strtolower( self::session_get( self::S_EMAIL ) ) ) {
				// A different address is a different person: an earlier tick is not theirs.
				WC()->session->set( self::S_CONSENT, '' );
			}
			WC()->session->set( self::S_EMAIL, $email );
		}
		$first = sanitize_text_field( $first );
		if ( '' !== $first ) {
			WC()->session->set( self::S_FIRST, mb_substr( $first, 0, 40 ) );
		}
		if ( true === $ticked && '' === self::session_get( self::S_CONSENT ) ) {
			WC()->session->set( self::S_CONSENT, gmdate( 'Y-m-d H:i:s' ) );
		} elseif ( false === $ticked ) {
			WC()->session->set( self::S_CONSENT, '' );
		}
		self::on_cart_updated();
	}

	/*
	 * ------------------------------------------------------------------ the cart row
	 */

	/**
	 * Writes this session's cart row (only once the store has an address for the shopper).
	 */
	public static function on_cart_updated(): void {
		if ( ! self::session() || ! isset( WC()->cart ) || ! is_object( WC()->cart ) ) {
			return;
		}
		$email = self::shopper_email();
		$items = self::cart_items();
		$key   = self::session_get( self::S_CART );
		if ( array() === $items ) {
			if ( '' !== $key ) {
				// Emptied without an order: nothing is left to remind anyone about.
				self::mark( array( $key ), 'emptied', 'open' );
				self::mark( array( $key ), 'emptied', 'skipped' );
			}
			return;
		}
		if ( '' === $email ) {
			return;
		}
		if ( '' === $key ) {
			$key = self::new_key();
			WC()->session->set( self::S_CART, $key );
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		self::upsert(
			$key,
			array(
				'email'       => $email,
				'first_name'  => self::shopper_first_name(),
				'locale'      => self::locale(),
				'consent_at'  => self::consent_at(),
				'contents'    => (string) wp_json_encode( $items ),
				'total_minor' => self::minor( (float) WC()->cart->get_total( 'edit' ), $currency ),
				'currency'    => $currency,
			)
		);
		self::ensure_sweep();
	}

	/**
	 * The cart was emptied (an order was paid, or the shopper emptied it): the next cart is a new
	 * cart. A row still open is closed (an order already marked it `converted`).
	 */
	public static function on_cart_emptied(): void {
		$key = self::session_get( self::S_CART );
		if ( '' === $key ) {
			return;
		}
		self::mark( array( $key ), 'emptied', 'open' );
		self::mark( array( $key ), 'emptied', 'skipped' );
		WC()->session->set( self::S_CART, '' );
	}

	/**
	 * Inserts or updates a cart row. A cart already reported or converted keeps its state, so it
	 * never fires twice; any other row is open again (the shopper came back).
	 *
	 * @param string               $key  The cart key.
	 * @param array<string, mixed> $data The columns.
	 */
	public static function upsert( string $key, array $data ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table; a write.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (cart_key, email, first_name, locale, consent_at, contents, total_minor, currency, state, updated_at)
				VALUES (%s, %s, %s, %s, NULLIF(%s, ''), %s, %d, %s, 'open', %s)
				ON DUPLICATE KEY UPDATE email = VALUES(email), first_name = VALUES(first_name), locale = VALUES(locale),
				consent_at = VALUES(consent_at), contents = VALUES(contents), total_minor = VALUES(total_minor),
				currency = VALUES(currency), updated_at = VALUES(updated_at),
				state = IF(state IN ('abandoned', 'converted'), state, 'open')",
				self::table(),
				$key,
				(string) $data['email'],
				(string) $data['first_name'],
				(string) $data['locale'],
				(string) $data['consent_at'],
				(string) $data['contents'],
				(int) $data['total_minor'],
				(string) $data['currency'],
				$now
			)
		);
	}

	/**
	 * One cart row, or null.
	 *
	 * @param string $key The cart key.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $key ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, read once per conversion.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE cart_key = %s', self::table(), $key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Sets a row's state.
	 *
	 * @param string[] $keys  Cart keys.
	 * @param string   $state The new state.
	 * @param string   $only  Change only rows in this state ('' = any).
	 */
	public static function mark( array $keys, string $state, string $only = '' ): void {
		if ( array() === $keys ) {
			return;
		}
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// '' = any state: the guard is always in the SQL, so nothing but placeholders is spliced in.
		$args = array_merge( array( self::table(), $state ), array_values( $keys ), array( $only, $only ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table; the placeholders and their values (one array) are built above.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = %s WHERE cart_key IN ({$in}) AND ( %s = '' OR state = %s )", $args ) );
	}

	/*
	 * ------------------------------------------------------------------ the sweep
	 */

	/**
	 * Schedules the recurring sweep once (checked at most hourly).
	 */
	public static function ensure_sweep(): void {
		if ( get_transient( 'zinn_campaigns_sweep_ok' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::SWEEP_HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + self::SWEEP_EVERY, self::SWEEP_EVERY, self::SWEEP_HOOK, array(), self::GROUP );
			}
		} elseif ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + self::SWEEP_EVERY, 'hourly', self::SWEEP_HOOK );
		}
		set_transient( 'zinn_campaigns_sweep_ok', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Minutes a cart must be left alone before it counts as abandoned.
	 */
	public static function delay_minutes(): int {
		/**
		 * Filters how long a cart must be left alone before Zinn® Campaigns hears it was abandoned.
		 *
		 * @param int $minutes Default 60. At least 15.
		 */
		return max( 15, (int) apply_filters( 'zinn_campaigns_abandoned_after_minutes', 60 ) );
	}

	/**
	 * The background job: report every cart left alone long enough, page by page, until none is
	 * left or the run's time is up (then it queues itself again at once: it resumes, never caps).
	 * A handled row leaves `open`, so it is never selected again.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return array{sent: int, skipped: int, expired: int, failed: int}
	 */
	public static function sweep( $now = null ): array {
		$out = array(
			'sent'    => 0,
			'skipped' => 0,
			'expired' => 0,
			'failed'  => 0,
		);
		if ( '' === Zinn_Campaigns_Api::token() ) {
			return $out;
		}
		global $wpdb;
		$now      = null === $now ? time() : (int) $now;
		$table    = self::table();
		$deadline = microtime( true ) + 20;
		$cutoff   = gmdate( 'Y-m-d H:i:s', $now - 60 * self::delay_minutes() );
		$stale    = gmdate( 'Y-m-d H:i:s', $now - self::MAX_AGE );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table, in a background job.
		// Too old to mail (the engine would refuse it): retire, never send.
		$out['expired'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'expired' WHERE state IN ('open', 'skipped') AND updated_at < %s", $table, $stale ) );
		$more           = false;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE state = 'open' AND updated_at <= %s ORDER BY updated_at LIMIT %d", $table, $cutoff, self::BATCH ), ARRAY_A );
			if ( ! is_array( $rows ) || array() === $rows ) {
				break;
			}
			$events = array();
			$skip   = array();
			foreach ( $rows as $row ) {
				$event = self::abandoned_event( $row );
				if ( null === $event ) {
					$skip[] = (string) $row['cart_key'];
				} else {
					$events[ (string) $row['cart_key'] ] = $event;
				}
			}
			// No consent: nothing leaves the store. A later tick re-opens the row (upsert()).
			self::mark( $skip, 'skipped', 'open' );
			$out['skipped'] += count( $skip );
			if ( array() !== $events ) {
				list( $code ) = Zinn_Campaigns_Api::call( 'POST', '/v1/store-connect/events', array( 'events' => array_values( $events ) ) );
				if ( $code >= 200 && $code < 300 ) {
					self::mark( array_keys( $events ), 'abandoned', 'open' );
					$out['sent'] += count( $events );
				} elseif ( 400 === $code ) {
					// One bad row must not hold the others back: send them one by one.
					foreach ( $events as $key => $event ) {
						list( $one ) = Zinn_Campaigns_Api::call( 'POST', '/v1/store-connect/events', array( 'events' => array( $event ) ) );
						$ok          = $one >= 200 && $one < 300;
						self::mark( array( (string) $key ), $ok ? 'abandoned' : 'failed', 'open' );
						$out[ $ok ? 'sent' : 'failed' ] += 1;
					}
				} else {
					// Offline, refused or rate-limited: the rows stay open for the next run.
					$out['failed'] += count( $events );
					break;
				}
			}
			if ( microtime( true ) > $deadline ) {
				$more = true;
				break;
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s LIMIT 1000', $table, gmdate( 'Y-m-d H:i:s', $now - self::KEEP ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $more && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::SWEEP_HOOK, array(), self::GROUP );
		}
		return $out;
	}

	/**
	 * The `cart_abandoned` event for a row, or null when the shopper has not consented.
	 *
	 * @param array<string, mixed> $row The cart row.
	 * @return array<string, mixed>|null
	 */
	public static function abandoned_event( array $row ): ?array {
		$event = self::cart_event( $row, 'cart_abandoned' );
		if ( null === $event || ! isset( $event['marketing_consent'] ) ) {
			return null;
		}
		return $event;
	}

	/**
	 * A cart event (`cart_abandoned` / `cart_recovered`) from a row.
	 *
	 * @param array<string, mixed> $row  The cart row.
	 * @param string               $kind The kind.
	 * @return array<string, mixed>|null
	 */
	public static function cart_event( array $row, string $kind ): ?array {
		$key   = (string) ( $row['cart_key'] ?? '' );
		$email = (string) ( $row['email'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) || '' === $email ) {
			return null;
		}
		$at      = self::iso( (string) $row['updated_at'] );
		$event   = array(
			'id'          => Zinn_Campaigns_Api::site_uuid() . ':woocommerce:cart:' . $key . ( 'cart_abandoned' === $kind ? '' : ':' . str_replace( 'cart_', '', $kind ) ),
			'kind'        => $kind,
			'integration' => 'woocommerce',
			'ref'         => $key,
			'occurred_at' => 'cart_abandoned' === $kind ? $at : gmdate( DATE_ATOM ),
			'locale'      => (string) $row['locale'],
			'customer'    => array(
				'email'      => $email,
				'first_name' => (string) $row['first_name'],
			),
			'cart_key'    => $key,
			'cart_url'    => self::cart_url( $key ),
			'totals'      => array(
				'minor'    => (int) $row['total_minor'],
				'currency' => (string) $row['currency'],
			),
		);
		$consent = (string) ( $row['consent_at'] ?? '' );
		if ( '' !== $consent && strtotime( $consent . ' UTC' ) <= strtotime( (string) $row['updated_at'] . ' UTC' ) ) {
			$event['marketing_consent'] = self::consent_block( self::iso( $consent ) );
		}
		return $event;
	}

	/**
	 * The consent proof the engine records (store_triggers.payload_fields).
	 *
	 * @param string $captured_at ISO 8601 with a zone.
	 * @return array<string, mixed>
	 */
	public static function consent_block( string $captured_at ): array {
		return array(
			'ticked'          => true,
			'channel'         => 'email',
			'captured_at'     => $captured_at,
			'wording_version' => self::WORDING_V,
			'wording'         => self::label(),
		);
	}

	/*
	 * ------------------------------------------------------------------ recovery link
	 */

	/**
	 * The link in the reminder: the store's cart page, which puts the items back.
	 *
	 * @param string $key The cart key.
	 */
	public static function cart_url( string $key ): string {
		$base = function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : home_url( '/' );
		return (string) add_query_arg( 'zinn_cart', $key, $base );
	}

	/**
	 * A shopper followed the reminder: put the cart back (only into an empty cart) and remember
	 * it is this cart, so the order counts as recovered.
	 */
	public static function maybe_restore(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an e-mail link: the 32-character random key is the capability, and it only fills this visitor's own cart.
		$key = isset( $_GET['zinn_cart'] ) ? sanitize_key( wp_unslash( $_GET['zinn_cart'] ) ) : '';
		if ( '' === $key || ! self::session() || ! isset( WC()->cart ) ) {
			return;
		}
		$row = self::find( $key );
		if ( null === $row || in_array( (string) $row['state'], array( 'converted', 'expired' ), true ) ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			$items = json_decode( (string) $row['contents'], true );
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				WC()->cart->add_to_cart(
					(int) ( $item['product_id'] ?? 0 ),
					max( 1, (int) ( $item['quantity'] ?? 1 ) ),
					(int) ( $item['variation_id'] ?? 0 ),
					is_array( $item['variation'] ?? null ) ? array_map( 'strval', $item['variation'] ) : array()
				);
			}
		}
		WC()->session->set( self::S_CART, $key );
		WC()->session->set( self::S_EMAIL, (string) $row['email'] );
		if ( '' !== (string) $row['first_name'] ) {
			WC()->session->set( self::S_FIRST, (string) $row['first_name'] );
		}
		if ( '' !== (string) ( $row['consent_at'] ?? '' ) ) {
			WC()->session->set( self::S_CONSENT, (string) $row['consent_at'] );
		}
		wp_safe_redirect( remove_query_arg( 'zinn_cart' ) );
		exit;
	}

	/*
	 * ------------------------------------------------------------------ product views
	 */

	/**
	 * A product page, seen by a shopper whose address and consent the store has: queue
	 * `product_viewed` (once per product per day).
	 */
	public static function on_product_view(): void {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) get_the_ID() ) : null;
		if ( ! $product || ! self::session() ) {
			return;
		}
		$email   = self::shopper_email();
		$consent = self::consent_at();
		if ( '' === $email || '' === $consent ) {
			return;
		}
		$day    = gmdate( 'Ymd' );
		$id     = (int) $product->get_id();
		$viewed = WC()->session->get( self::S_VIEWED );
		$viewed = ( is_array( $viewed ) && ( $viewed['day'] ?? '' ) === $day ) ? $viewed : array(
			'day' => $day,
			'ids' => array(),
		);
		if ( in_array( $id, (array) $viewed['ids'], true ) ) {
			return;
		}
		$viewed['ids'][] = $id;
		WC()->session->set( self::S_VIEWED, $viewed );
		self::queue( self::product_event( $product, $email, self::shopper_first_name(), $consent, $day ) );
	}

	/**
	 * The `product_viewed` event.
	 *
	 * @param object $product    The WC_Product.
	 * @param string $email      The shopper's address.
	 * @param string $first      The first name.
	 * @param string $consent_at When the box was ticked (Y-m-d H:i:s UTC).
	 * @param string $day        Ymd (the idempotency window).
	 * @return array<string, mixed>
	 */
	public static function product_event( $product, string $email, string $first, string $consent_at, string $day ): array {
		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$id       = (int) $product->get_id();
		return array(
			'id'                => Zinn_Campaigns_Api::site_uuid() . ':woocommerce:view:' . substr( hash( 'sha256', strtolower( $email ) ), 0, 16 ) . ':' . $id . ':' . $day,
			'kind'              => 'product_viewed',
			'integration'       => 'woocommerce',
			'ref'               => (string) $id,
			'occurred_at'       => gmdate( DATE_ATOM ),
			'locale'            => self::locale(),
			'customer'          => array(
				'email'      => $email,
				'first_name' => $first,
			),
			'product'           => array(
				'name' => wp_strip_all_tags( (string) $product->get_name() ),
				'url'  => (string) get_permalink( $id ),
			),
			'totals'            => array(
				'minor'    => self::minor( (float) $product->get_price(), $currency ),
				'currency' => $currency,
			),
			'marketing_consent' => self::consent_block( self::iso( $consent_at ) ),
		);
	}

	/*
	 * ------------------------------------------------------------------ orders
	 */

	/**
	 * An order was placed: its cart converted. A cart already reported abandoned sends
	 * `cart_recovered`; every tracked or consented shopper's order sends `order_placed`
	 * (both end the shopper's open cart and browse flows).
	 *
	 * @param int|object $order_or_id The order or its id.
	 */
	public static function on_order_created( $order_or_id ): void {
		$order = is_object( $order_or_id ) ? $order_or_id : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_or_id ) : null );
		if ( ! $order || $order->get_meta( '_zinn_campaigns_placed' ) ) {
			return;
		}
		$order->update_meta_data( '_zinn_campaigns_placed', '1' );
		$key = (string) $order->get_meta( self::CART_META );
		if ( '' === $key ) {
			$key = self::session_get( self::S_CART );
		}
		$row = '' !== $key ? self::find( $key ) : null;
		if ( self::opted_in( $order ) ) {
			$user = (int) $order->get_customer_id();
			if ( $user > 0 ) {
				update_user_meta( $user, self::USER_CONSENT_META, gmdate( 'Y-m-d H:i:s' ) );
			}
		}
		$order->save_meta_data();
		if ( null !== $row ) {
			if ( 'abandoned' === (string) $row['state'] ) {
				$event = self::cart_event( $row, 'cart_recovered' );
				if ( null !== $event ) {
					$event['order_number'] = (string) $order->get_order_number();
					self::queue( $event );
				}
			}
			self::mark( array( $key ), 'converted' );
		}
		if ( null !== $row || self::opted_in( $order ) ) {
			self::queue( self::order_event( $order, 'order_placed' ) );
		}
	}

	/**
	 * The order was paid: `payment_confirmed` (starts a post-purchase flow), once, and only for a
	 * shopper who ticked the box on this order.
	 *
	 * @param int $order_id The order id.
	 */
	public static function on_paid( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order || $order->get_meta( '_zinn_campaigns_paid' ) || ! self::opted_in( $order ) ) {
			return;
		}
		$order->update_meta_data( '_zinn_campaigns_paid', '1' );
		$order->save_meta_data();
		$event                      = self::order_event( $order, 'payment_confirmed' );
		$created                    = $order->get_date_created();
		$event['marketing_consent'] = self::consent_block( $created ? gmdate( DATE_ATOM, (int) $created->getTimestamp() ) : (string) $event['occurred_at'] );
		self::queue( $event );
	}

	/**
	 * An order event body.
	 *
	 * @param object $order The WC_Order.
	 * @param string $kind  The kind.
	 * @return array<string, mixed>
	 */
	public static function order_event( $order, string $kind ): array {
		$currency = strtoupper( (string) $order->get_currency() );
		return array(
			'id'           => Zinn_Campaigns_Api::site_uuid() . ':woocommerce:' . $order->get_id() . ':' . $kind . ':1',
			'kind'         => $kind,
			'integration'  => 'woocommerce',
			'ref'          => (string) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'occurred_at'  => gmdate( DATE_ATOM ),
			'locale'       => self::locale(),
			'customer'     => array(
				'email'      => (string) $order->get_billing_email(),
				'first_name' => mb_substr( (string) $order->get_billing_first_name(), 0, 40 ),
			),
			'totals'       => array(
				'minor'    => self::minor( (float) $order->get_total(), $currency ),
				'currency' => $currency,
			),
		);
	}

	/**
	 * Did the shopper tick the box on this order (classic meta or block field)?
	 *
	 * @param object $order The WC_Order.
	 */
	public static function opted_in( $order ): bool {
		if ( 'yes' === (string) $order->get_meta( self::OPTIN_META ) ) {
			return true;
		}
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) && class_exists( '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields' ) ) {
			try {
				$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
				return (bool) $fields->get_field_from_object( self::BLOCK_ID, $order, 'other' );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		return false;
	}

	/*
	 * ------------------------------------------------------------------ delivery
	 */

	/**
	 * Queues one event off the request (Action Scheduler, else WP-Cron).
	 *
	 * @param array<string, mixed> $event The event.
	 * @param int                  $tries Tries so far.
	 * @param int                  $delay Seconds from now.
	 */
	public static function queue( array $event, int $tries = 0, int $delay = 0 ): void {
		$args = array( $event, $tries );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::SEND_HOOK, $args, self::GROUP );
			return;
		}
		wp_schedule_single_event( time() + $delay, self::SEND_HOOK, $args );
	}

	/**
	 * The queued job: post the event; retry a failed post with a back-off (the id makes a retry
	 * the same event, so it never counts twice).
	 *
	 * @param array<string, mixed> $event The event.
	 * @param int                  $tries Tries so far.
	 */
	public static function deliver( $event, $tries = 0 ): void {
		if ( ! is_array( $event ) || '' === Zinn_Campaigns_Api::token() ) {
			return;
		}
		list( $code ) = Zinn_Campaigns_Api::call( 'POST', '/v1/store-connect/events', array( 'events' => array( $event ) ) );
		if ( $code >= 200 && $code < 300 ) {
			return;
		}
		$tries = (int) $tries;
		// 4xx other than 429 will not succeed on a retry; everything else (0 = network) may.
		if ( $tries >= self::RETRIES || ( $code >= 400 && $code < 500 && 429 !== $code ) ) {
			return;
		}
		self::queue( $event, $tries + 1, 60 * ( 2 ** $tries ) );
	}

	/*
	 * ------------------------------------------------------------------ helpers
	 */

	/**
	 * A WooCommerce session exists for this visitor.
	 */
	private static function session(): bool {
		return function_exists( 'WC' ) && isset( WC()->session ) && is_object( WC()->session );
	}

	/**
	 * A string from the WC session.
	 *
	 * @param string $key Session key.
	 */
	private static function session_get( string $key ): string {
		if ( ! self::session() ) {
			return '';
		}
		$value = WC()->session->get( $key );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The shopper's address: typed at checkout, else a signed-in customer's.
	 */
	public static function shopper_email(): string {
		$email = self::session_get( self::S_EMAIL );
		if ( '' === $email && is_user_logged_in() && isset( WC()->customer ) && is_object( WC()->customer ) ) {
			$email = (string) WC()->customer->get_billing_email();
			if ( '' === $email ) {
				$email = (string) wp_get_current_user()->user_email;
			}
		}
		return is_email( $email ) ? $email : '';
	}

	/**
	 * The shopper's first name.
	 */
	private static function shopper_first_name(): string {
		$first = self::session_get( self::S_FIRST );
		if ( '' === $first && is_user_logged_in() && isset( WC()->customer ) && is_object( WC()->customer ) ) {
			$first = (string) WC()->customer->get_billing_first_name();
		}
		return mb_substr( $first, 0, 40 );
	}

	/**
	 * When the shopper ticked the box (Y-m-d H:i:s UTC), or ''. A signed-in shopper's tick on an
	 * earlier order counts while the address is still theirs.
	 */
	public static function consent_at(): string {
		$at = self::session_get( self::S_CONSENT );
		if ( '' === $at && is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$typed = self::session_get( self::S_EMAIL );
			if ( '' === $typed || strtolower( $typed ) === strtolower( (string) $user->user_email ) || ( isset( WC()->customer ) && strtolower( $typed ) === strtolower( (string) WC()->customer->get_billing_email() ) ) ) {
				$at = (string) get_user_meta( (int) $user->ID, self::USER_CONSENT_META, true );
			}
		}
		return preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at ) ? $at : '';
	}

	/**
	 * The cart lines, as the restore link needs them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function cart_items(): array {
		$items = array();
		foreach ( (array) WC()->cart->get_cart() as $line ) {
			if ( ! is_array( $line ) || empty( $line['product_id'] ) ) {
				continue;
			}
			$items[] = array(
				'product_id'   => (int) $line['product_id'],
				'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
				'quantity'     => (int) ( $line['quantity'] ?? 1 ),
				'variation'    => is_array( $line['variation'] ?? null ) ? $line['variation'] : array(),
			);
		}
		return $items;
	}

	/**
	 * A new random cart key (32 hex characters).
	 */
	private static function new_key(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The shopper's language (the engine writes the e-mail in it when it can).
	 */
	private static function locale(): string {
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale();
		return preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,2}$/', $locale ) ? $locale : '';
	}

	/**
	 * A database time (UTC) as ISO 8601 with its zone.
	 *
	 * @param string $mysql Y-m-d H:i:s, UTC.
	 */
	public static function iso( string $mysql ): string {
		$ts = strtotime( $mysql . ' UTC' );
		return gmdate( DATE_ATOM, false === $ts ? time() : $ts );
	}

	/**
	 * ISO 4217 minor units (the engine formats by the currency's own exponent).
	 *
	 * @param float  $amount   The amount.
	 * @param string $currency The currency.
	 */
	public static function minor( float $amount, string $currency ): int {
		$decimals = in_array( $currency, self::ZERO_DECIMAL, true ) ? 0 : ( in_array( $currency, self::THREE_DECIMAL, true ) ? 3 : 2 );
		return (int) round( $amount * ( 10 ** $decimals ) );
	}
}
