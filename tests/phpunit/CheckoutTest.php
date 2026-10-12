<?php

final class CheckoutTest extends Fynex_Test_Case {
	public function test_checkout_request_matches_the_fynex_contract(): void {
		$order      = $this->create_order( '12.50' );
		$payment_id = $this->start_checkout( $order );

		$this->assertMatchesRegularExpression( '/^wc-[0-9a-f]{8}-' . $order->get_id() . '-1$/', $payment_id );
		$request = $this->requests_to( 'POST', '/checkout' )[0];
		$this->assertSame( $payment_id, $request['body']['externalOrderRef'] );
		$this->assertSame( 12.5, $request['body']['amount'] );
		$this->assertSame( 'GBP', $request['body']['currencyCode'] );
		$this->assertSame( 'GB', $request['body']['countryCode'] );
		$this->assertSame( $order->get_checkout_order_received_url(), $request['body']['returnUrls']['success'] );
		$this->assertStringContainsString( 'pay_for_order=true', $request['body']['returnUrls']['failure'] );
		$this->assertTrue( wp_is_uuid( $request['headers']['Idempotency-Key'], 4 ) );
		$this->assertSame( 'Bearer ' . self::TOKEN, $request['headers']['Authorization'] );
	}

	public function test_redirect_keeps_the_cart_for_a_customer_who_comes_back(): void {
		WC()->frontend_includes();
		WC()->initialize_session();
		WC()->initialize_cart();
		$product = $this->create_product();
		WC()->cart->add_to_cart( $product->get_id() );

		$this->start_checkout( $this->create_order() );

		$this->assertFalse( WC()->cart->is_empty() );
	}

	public function test_pay_for_order_without_a_cart_does_not_fatal(): void {
		$cart     = WC()->cart;
		WC()->cart = null;
		try {
			$payment_id = $this->start_checkout( $this->create_order() );
		} finally {
			WC()->cart = $cart;
		}
		$this->assertNotSame( '', $payment_id );
	}

	public function test_misconfigured_gateway_refuses_the_payment_without_calling_fynex(): void {
		delete_option( 'fynex_woo_webhook_secret' );

		$result = $this->gateway()->process_payment( $this->create_order()->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $this->api_requests );
	}

	public function test_fynex_error_keeps_the_order_unpaid_and_reports_it(): void {
		$this->respond( 'POST', '/checkout', 422, array( 'error' => 'currency not enabled' ) );
		$order = $this->create_order();

		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_successful_redirect_schedules_a_status_check(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );

		$this->assertTrue( as_has_scheduled_action( 'fynex_woo_check_payment', array( $order->get_id(), $payment_id ), 'fynex-for-woocommerce' ) );
	}

	public function test_retry_within_the_session_reuses_the_attempt(): void {
		$order  = $this->create_order();
		$first  = $this->start_checkout( $order );
		$second = $this->start_checkout( $order );
		$this->assertSame( $first, $second );

		$this->expire_current_attempt( $order );
		$third = $this->start_checkout( $order );
		$this->assertStringEndsWith( '-2', $third );
	}
}
