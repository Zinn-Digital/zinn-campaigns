<?php
/**
 * Opt-outs made in Zinn® Campaigns, applied back on this site.
 *
 * When a list in Zinn® Campaigns is fed from this site's own database (docs/1052 §14), the
 * Zinn® side only ever READS the site. So a person who unsubscribes from an e-mail, or whose
 * address bounces or complains, is pulled back here and marked on the site too: MailPoet,
 * The Newsletter Plugin and WordPress / WooCommerce users. Other plugins can listen to the
 * `zinn_campaigns_opt_out` action.
 *
 * @package ZinnCampaigns
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The opt-out pull (`GET /v1/store-connect/campaigns/opt-outs`), hourly.
 */
final class Zinn_Campaigns_Opt_Outs {

	/** The recurring hook. */
	public const HOOK = 'zinn_campaigns_opt_out_pull';

	/** Where each source's cursor is kept (not autoloaded). */
	public const CURSORS = 'zinn_campaigns_opt_out_cursors';

	/** When an address was opted out here, on a WordPress user. */
	public const USER_META = 'zinn_campaigns_opted_out_at';

	/** Calls per run. The cursors persist, so a long backlog resumes on the next run. */
	private const CALLS_PER_RUN = 20;

	/**
	 * Hooks the pull and schedules it once.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'pull' ) );
		if ( '' === Zinn_Campaigns_Api::token() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/**
	 * Pulls every page that is waiting and applies it.
	 *
	 * @return int How many opt-outs were applied.
	 */
	public static function pull(): int {
		if ( '' === Zinn_Campaigns_Api::token() ) {
			return 0;
		}
		$cursors = get_option( self::CURSORS, array() );
		$cursors = is_array( $cursors ) ? $cursors : array();
		$applied = 0;
		for ( $call = 0; $call < self::CALLS_PER_RUN; $call++ ) {
			$query = array();
			foreach ( $cursors as $id => $cursor ) {
				$query[] = 'after=' . rawurlencode( (string) $id . '~' . (string) $cursor );
			}
			$path                = '/v1/store-connect/campaigns/opt-outs' . ( $query ? '?' . implode( '&', $query ) : '' );
			list( $code, $body ) = Zinn_Campaigns_Api::call( 'GET', $path );
			if ( 200 !== $code || ! isset( $body['sources'] ) || ! is_array( $body['sources'] ) ) {
				break;
			}
			$more = false;
			foreach ( $body['sources'] as $source ) {
				if ( ! is_array( $source ) || empty( $source['id'] ) ) {
					continue;
				}
				foreach ( (array) ( $source['opt_outs'] ?? array() ) as $row ) {
					if ( is_array( $row ) && self::apply( (string) ( $row['email'] ?? '' ), (string) ( $row['reason'] ?? 'unsubscribed' ) ) ) {
						++$applied;
					}
				}
				$cursors[ (string) $source['id'] ] = (string) ( $source['cursor'] ?? '' );
				$more                              = $more || ! empty( $source['next'] );
			}
			update_option( self::CURSORS, $cursors, false );
			if ( ! $more ) {
				break;
			}
		}
		return $applied;
	}

	/**
	 * Marks one address as opted out everywhere this plugin knows of on the site.
	 *
	 * @param string $email  The address.
	 * @param string $reason `unsubscribed`, `bounced` or `complained`.
	 */
	public static function apply( string $email, string $reason ): bool {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}
		$bounced = 'bounced' === $reason;

		$user = get_user_by( 'email', $email );
		if ( $user instanceof WP_User ) {
			delete_user_meta( $user->ID, Zinn_Campaigns_Store_Events::USER_CONSENT_META );
			update_user_meta( $user->ID, self::USER_META, time() );
		}

		$mailpoet = $wpdb->prefix . 'mailpoet_subscribers';
		if ( self::table_exists( $mailpoet ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MailPoet's own subscriber table, one address.
				$wpdb->prepare(
					'UPDATE %i SET status = %s WHERE email = %s AND status <> %s',
					$mailpoet,
					$bounced ? 'bounced' : 'unsubscribed',
					$email,
					$bounced ? 'bounced' : 'unsubscribed'
				)
			);
		}

		$newsletter = $wpdb->prefix . 'newsletter';
		if ( self::table_exists( $newsletter ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The Newsletter Plugin's own subscriber table, one address.
				$wpdb->prepare(
					'UPDATE %i SET status = %s WHERE email = %s AND status <> %s',
					$newsletter,
					$bounced ? 'B' : 'U',
					$email,
					$bounced ? 'B' : 'U'
				)
			);
		}

		/**
		 * Fires when Zinn® Campaigns reports that an address must no longer be mailed.
		 *
		 * @param string $email  The address.
		 * @param string $reason `unsubscribed`, `bounced` or `complained`.
		 */
		do_action( 'zinn_campaigns_opt_out', $email, $reason );
		return true;
	}

	/**
	 * Whether a table exists, asked once per request.
	 *
	 * @param string $table Full table name.
	 */
	private static function table_exists( string $table ): bool {
		static $seen = array();
		if ( ! isset( $seen[ $table ] ) ) {
			global $wpdb;
			$seen[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $seen[ $table ];
	}
}
