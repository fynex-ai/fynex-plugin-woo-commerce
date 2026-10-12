<?php

final class UninstallTest extends Fynex_Test_Case {
	public function test_deleting_the_plugin_removes_settings_locks_and_jobs_but_not_orders(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		Fynex_WC_Lock::acquire( 'order_' . $order->get_id(), 60 );
		add_option( 'fynex_woo_refund_lock_legacy', 'x', '', false );
		Fynex_WC_Refund_Reconciliation::schedule( $order->get_id(), wp_generate_uuid4() );

		define( 'WP_UNINSTALL_PLUGIN', 'fynex-for-woocommerce/fynex-for-woocommerce.php' );
		include FYNEX_WC_DIR . 'uninstall.php';
		wp_cache_flush();

		foreach ( array( 'fynex_woo_api_token', 'fynex_woo_webhook_secret', 'woocommerce_fynex_settings', 'fynex_woo_lock_order_' . $order->get_id(), 'fynex_woo_refund_lock_legacy' ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertFalse( as_has_scheduled_action( 'fynex_woo_check_payment' ) );
		$this->assertFalse( as_has_scheduled_action( 'fynex_woo_reconcile_refund' ) );
		$this->assertSame( $payment_id, wc_get_order( $order->get_id() )->get_meta( '_fynex_current_payment_id', true ) );
	}
}
