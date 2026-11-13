<?php
/**
 * What the connected Zinn® Campaigns account includes, as the Zinn® API reports it.
 *
 * The free plugin only SHOWS this (and the add-on reads it through the documented
 * `zinn_campaigns_plan()` function). No feature of the free plugin depends on it: there is no
 * locked feature here (WordPress.org trialware rule).
 *
 * @package ZinnCampaigns
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plan read (docs/1052 §11.9b, `GET /v1/store-connect/campaigns`).
 */
final class Zinn_Campaigns_Plan {

	/** Cached read (ten minutes), so a page view is never an API call. */
	public const CACHE = 'zinn_campaigns_plan';

	/** The upgrade page in the Zinn® dashboard. */
	public const UPGRADE_PATH = '/email-marketing/campaigns';

	/**
	 * The plan as the API reports it: `pro` is `active`, `trial`, `lapsed`, or `none` when the
	 * site is not connected or Zinn® could not be reached (never a guess in the permissive
	 * direction).
	 *
	 * @return array{pro: string, plan_paid: bool, plan_code: string, trial_ends_at: string}
	 */
	public static function get(): array {
		$none = array(
			'pro'           => 'none',
			'plan_paid'     => false,
			'plan_code'     => '',
			'trial_ends_at' => '',
		);
		if ( '' === Zinn_Campaigns_Api::token() ) {
			return $none;
		}
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['pro'] ) ) {
			return $cached;
		}
		list( $code, $body ) = Zinn_Campaigns_Api::call( 'GET', '/v1/store-connect/campaigns' );
		if ( 200 !== $code ) {
			// Cached briefly too, so an outage does not make every page view call out.
			set_transient( self::CACHE, $none, MINUTE_IN_SECONDS );
			return $none;
		}
		$pro   = (string) ( $body['pro'] ?? 'none' );
		$value = array(
			'pro'           => in_array( $pro, array( 'active', 'trial', 'lapsed' ), true ) ? $pro : 'none',
			'plan_paid'     => ! empty( $body['plan_paid'] ),
			'plan_code'     => sanitize_key( (string) ( $body['plan_code'] ?? '' ) ),
			'trial_ends_at' => sanitize_text_field( (string) ( $body['trial_ends_at'] ?? '' ) ),
		);
		set_transient( self::CACHE, $value, 10 * MINUTE_IN_SECONDS );
		return $value;
	}

	/**
	 * Forget the cached read (after connect, disconnect, or the admin's Refresh).
	 */
	public static function forget(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * The dashboard page where the account owner picks a paid plan.
	 */
	public static function upgrade_url(): string {
		return zinn_campaigns_app_base() . self::UPGRADE_PATH;
	}
}
