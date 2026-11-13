<?php
/**
 * Uninstall: removes every option and transient the plugin made. Nothing else is stored here.
 *
 * @package ZinnCampaigns
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's data.
 */
function zinn_campaigns_uninstall(): void {
	global $wpdb;
	$like = array(
		$wpdb->esc_like( 'zinn_campaigns_' ) . '%',
		$wpdb->esc_like( '_transient_zinn_campaigns_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_zinn_campaigns_' ) . '%',
	);
	foreach ( $like as $pattern ) {
		$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall: finding our own options.
		foreach ( (array) $options as $option ) {
			delete_option( (string) $option );
		}
	}
}

zinn_campaigns_uninstall();
