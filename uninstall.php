<?php
/**
 * Removes the plugin's settings, lock rows and scheduled jobs when it is deleted.
 *
 * Order meta (_fynex_*) is transaction history and is left on the orders.
 *
 * @package Fynex_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans up one site.
 */
function fynex_wc_uninstall_site(): void {
	global $wpdb;

	delete_option( 'fynex_woo_api_token' );
	delete_option( 'fynex_woo_webhook_secret' );
	delete_option( 'woocommerce_fynex_settings' );

	// Lease locks are options named fynex_woo_lock_*; v0.1.0 used fynex_woo_refund_lock_*.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no API deletes options by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'fynex_woo_lock_' ) . '%',
			$wpdb->esc_like( 'fynex_woo_refund_lock_' ) . '%'
		)
	);

	foreach ( array( 'fynex_woo_check_payment', 'fynex_woo_reconcile_refund' ) as $hook ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
		wp_unschedule_hook( $hook );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $fynex_wc_site_id ) {
		switch_to_blog( (int) $fynex_wc_site_id );
		fynex_wc_uninstall_site();
		restore_current_blog();
	}
} else {
	fynex_wc_uninstall_site();
}
