<?php

final class PaymentCheckTest extends Fynex_Test_Case {
	/**
	 * @return array<string,mixed>
	 */
	private function payment( string $payment_id, string $lifecycle, float $amount = 49.99 ): array {
		return array(
			'paymentId'        => $payment_id,
			'externalOrderRef' => $payment_id,
			'status'           => 'captured' === $lifecycle ? 'settled' : $lifecycle,
			'lifecycleStatus'  => $lifecycle,
			'amount'           => $amount,
			'currencyCode'     => 'GBP',
		);
	}

	/**
	 * The suite deletes posts between classes, so give each return test a checkout page.
	 */
	private function visit_order_received_page( WC_Order $order, string $key ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page_id );
		$this->go_to( $order->get_checkout_order_received_url() );
		$_GET['key'] = $key;
	}

	public function test_scheduled_check_marks_a_paid_order_when_the_webhook_was_lost(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->respond( 'GET', '/payments/' . $payment_id, 200, $this->payment( $payment_id, 'captured' ) );

		Fynex_WC_Payment_Check::run_scheduled( $order->get_id(), $payment_id );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_pending_payment_is_checked_again_later(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		as_unschedule_all_actions( 'fynex_woo_check_payment' );
		$this->respond( 'GET', '/payments/' . $payment_id, 200, $this->payment( $payment_id, 'pending' ) );

		Fynex_WC_Payment_Check::run_scheduled( $order->get_id(), $payment_id );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertTrue( as_has_scheduled_action( 'fynex_woo_check_payment', array( $order->get_id(), $payment_id ), 'fynex-for-woocommerce' ) );
	}

	public function test_already_paid_order_is_not_polled(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ) );
		$this->api_requests = array();

		Fynex_WC_Payment_Check::run_scheduled( $order->get_id(), $payment_id );

		$this->assertSame( array(), $this->requests_to( 'GET', '/payments/' ) );
	}

	public function test_customer_return_applies_the_result_before_the_page_renders(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->respond( 'GET', '/payments/' . $payment_id, 200, $this->payment( $payment_id, 'captured' ) );

		$this->visit_order_received_page( $order, $order->get_order_key() );
		Fynex_WC_Payment_Check::check_on_return();
		unset( $_GET['key'] );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_customer_return_with_a_wrong_key_does_nothing(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->respond( 'GET', '/payments/' . $payment_id, 200, $this->payment( $payment_id, 'captured' ) );

		$this->visit_order_received_page( $order, 'wc_order_wrong' );
		Fynex_WC_Payment_Check::check_on_return();
		unset( $_GET['key'] );

		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( array(), $this->requests_to( 'GET', '/payments/' ) );
	}
}
