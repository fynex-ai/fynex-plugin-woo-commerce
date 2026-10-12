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

	public function test_a_real_scheduler_run_schedules_the_next_check(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->respond( 'GET', '/payments/' . $payment_id, 200, $this->payment( $payment_id, 'pending' ) );
		$action_id = $this->pending_checks( $order, $payment_id )[0];

		ActionScheduler::runner()->process_action( $action_id );

		$next = $this->pending_checks( $order, $payment_id );
		$this->assertCount( 1, $next );
		$this->assertNotSame( $action_id, $next[0] );
	}

	public function test_a_failure_later_turned_into_a_capture_is_picked_up_without_the_webhook(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'failed' ) );
		$this->respond( 'GET', '/payments/' . $payment_id, 200, $this->payment( $payment_id, 'captured' ) );

		Fynex_WC_Payment_Check::run_scheduled( $order->get_id(), $payment_id );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_returning_through_the_failure_url_opens_a_new_attempt_next_time(): void {
		$order = $this->create_order();
		$first = $this->start_checkout( $order );
		$page  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[woocommerce_checkout]' ) );
		update_option( 'woocommerce_checkout_page_id', $page );
		// The pay page starts a WooCommerce session, whose cookie cannot be sent under PHPUnit.
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
		$this->go_to( add_query_arg( Fynex_WC_Payment_Check::FAILURE_RETURN_ARG, '1', wc_get_order( $order->get_id() )->get_checkout_payment_url() ) );
		$_GET[ Fynex_WC_Payment_Check::FAILURE_RETURN_ARG ] = '1';
		$_GET['key'] = $order->get_order_key();

		Fynex_WC_Payment_Check::check_on_failure_return();
		unset( $_GET[ Fynex_WC_Payment_Check::FAILURE_RETURN_ARG ], $_GET['key'] );

		$this->assertNotSame( $first, $this->start_checkout( $order ) );
	}

	/**
	 * @return array<int,int>
	 */
	private function pending_checks( WC_Order $order, string $payment_id ): array {
		return array_values(
			as_get_scheduled_actions(
				array(
					'hook'   => 'fynex_woo_check_payment',
					'args'   => array( $order->get_id(), $payment_id ),
					'status' => ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
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
