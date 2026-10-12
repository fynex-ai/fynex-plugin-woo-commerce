<?php

final class RefundTest extends Fynex_Test_Case {
	/**
	 * @return array{0:WC_Order,1:string}
	 */
	private function paid_order(): array {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ) );
		return array( wc_get_order( $order->get_id() ), $payment_id );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function refund_response( string $refund_id, string $payment_id, string $status, float $amount ): array {
		return array(
			'id'           => $refund_id,
			'paymentId'    => $payment_id,
			'status'       => $status,
			'amount'       => $amount,
			'currencyCode' => 'GBP',
		);
	}

	/**
	 * Refunds the way the order screen does.
	 *
	 * @return WC_Order_Refund|WP_Error
	 */
	private function refund_from_order_screen( WC_Order $order, string $amount ) {
		return wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => 'Customer request',
				'refund_payment' => true,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function refunded_payload( string $refund_id, string $payment_id, int $amount_minor ): array {
		return array(
			'refundId'            => $refund_id,
			'externalOrderRef'    => $payment_id,
			'refundAmountMinor'   => $amount_minor,
			'originalAmountMinor' => 4999,
			'currencyCode'        => 'GBP',
			'partial'             => true,
		);
	}

	public function test_accepted_refund_is_recorded_at_once_and_linked_to_fynex(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );

		$local = $this->refund_from_order_screen( $order, '10.00' );

		$this->assertInstanceOf( WC_Order_Refund::class, $local );
		$this->assertSame( $refund_id, wc_get_order( $local->get_id() )->get_meta( '_fynex_refund_id', true ) );
		$this->assertTrue( wc_get_order( $local->get_id() )->get_refunded_payment() );
		$request = $this->requests_to( 'POST', '/payments/' . $payment_id . '/refund' )[0];
		$this->assertEquals( 10.0, $request['body']['amount'] );
		$this->assertTrue( wp_is_uuid( $request['headers']['Idempotency-Key'], 4 ) );
		$this->assertTrue( as_has_scheduled_action( 'fynex_woo_reconcile_refund', array( $order->get_id(), $refund_id ), 'fynex-for-woocommerce' ) );
	}

	public function test_confirmation_by_webhook_does_not_add_a_second_record(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );
		$this->refund_from_order_screen( $order, '10.00' );

		$this->deliver_webhook( 'PaymentRefunded', $this->refunded_payload( $refund_id, $payment_id, 1000 ) );
		$this->deliver_webhook( 'PaymentRefunded', $this->refunded_payload( $refund_id, $payment_id, 1000 ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( '10.00', wc_format_decimal( $order->get_total_refunded(), 2 ) );
		$this->assertSame( '', $order->get_meta( '_fynex_refund_attention', true ) );
	}

	public function test_confirmation_by_reconciliation_does_not_add_a_second_record(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );
		$this->refund_from_order_screen( $order, '10.00' );
		$this->respond( 'GET', '/refunds/' . $refund_id, 200, $this->refund_response( $refund_id, $payment_id, 'succeeded', 10.0 ) );

		Fynex_WC_Refund_Reconciliation::reconcile( $order->get_id(), $refund_id );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( 'succeeded', $order->get_meta( '_fynex_refunds', true )[0]['status'] );
	}

	public function test_refund_that_fails_later_is_flagged_with_the_record_to_delete(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );
		$local = $this->refund_from_order_screen( $order, '10.00' );
		$this->respond( 'GET', '/refunds/' . $refund_id, 200, $this->refund_response( $refund_id, $payment_id, 'failed', 10.0 ) );

		Fynex_WC_Refund_Reconciliation::reconcile( $order->get_id(), $refund_id );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'yes', $order->get_meta( '_fynex_refund_attention', true ) );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertStringContainsString( 'Delete WooCommerce refund #' . $local->get_id(), implode( "\n", $notes ) );
	}

	public function test_reconciliation_waits_while_the_order_is_busy(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );
		$this->refund_from_order_screen( $order, '10.00' );
		as_unschedule_all_actions( 'fynex_woo_reconcile_refund' );
		$this->api_requests = array();
		Fynex_WC_Lock::acquire( Fynex_WC_Payment_Outcome::lock_name( $order->get_id() ), 60 );

		Fynex_WC_Refund_Reconciliation::reconcile( $order->get_id(), $refund_id );

		$this->assertSame( array(), $this->requests_to( 'GET', '/refunds/' ) );
		$this->assertTrue( as_has_scheduled_action( 'fynex_woo_reconcile_refund', array( $order->get_id(), $refund_id ), 'fynex-for-woocommerce' ) );
	}

	public function test_refund_rejected_by_fynex_leaves_no_record(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 409, array( 'error' => 'refund is already in progress' ) );

		$result = $this->refund_from_order_screen( $order, '10.00' );

		$this->assertWPError( $result );
		$this->assertSame( 'refund is already in progress', $result->get_error_message() );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	public function test_refund_failed_at_once_leaves_no_record(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( wp_generate_uuid4(), $payment_id, 'failed', 10.0 ) );

		$this->assertWPError( $this->refund_from_order_screen( $order, '10.00' ) );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	public function test_refund_webhook_with_a_different_amount_is_flagged(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );
		$this->refund_from_order_screen( $order, '10.00' );

		$this->deliver_webhook( 'PaymentRefunded', $this->refunded_payload( $refund_id, $payment_id, 1001 ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( 'yes', $order->get_meta( '_fynex_refund_attention', true ) );
	}

	public function test_refund_pending_from_v0_1_is_still_recorded_on_confirmation(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$order->update_meta_data(
			'_fynex_refunds',
			array(
				array(
					'id'           => $refund_id,
					'amount'       => 5.0,
					'status'       => 'pending',
					'createdAt'    => time(),
					'reason'       => '',
					'payment_id'   => $payment_id,
					'amount_minor' => 500,
					'currency'     => 'GBP',
				),
			)
		);
		$order->save();

		$this->deliver_webhook( 'PaymentRefunded', $this->refunded_payload( $refund_id, $payment_id, 500 ) );

		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( $refund_id, $refunds[0]->get_meta( '_fynex_refund_id', true ) );
	}
}
