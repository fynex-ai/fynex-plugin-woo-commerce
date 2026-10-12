<?php

final class RefundTest extends Fynex_Test_Case {
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

	private function submit_refund( WC_Order $order, string $amount ) {
		return wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => 'Customer request',
				'refund_payment' => true,
			)
		);
	}

	public function test_refund_is_sent_for_the_paid_attempt(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );

		$this->submit_refund( $order, '10.00' );

		$request = $this->requests_to( 'POST', '/payments/' . $payment_id . '/refund' )[0];
		$this->assertEquals( 10.0, $request['body']['amount'] );
		$this->assertTrue( wp_is_uuid( $request['headers']['Idempotency-Key'], 4 ) );
	}

	public function test_refund_webhook_with_matching_data_records_the_refund_once(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );
		$this->submit_refund( $order, '10.00' );

		$payload = array(
			'refundId'          => $refund_id,
			'externalOrderRef'  => $payment_id,
			'refundAmountMinor' => 1000,
			'originalAmountMinor' => 4999,
			'currencyCode'      => 'GBP',
			'partial'           => true,
		);
		$this->deliver_webhook( 'PaymentRefunded', $payload );
		$this->deliver_webhook( 'PaymentRefunded', $payload );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( '10.00', wc_format_decimal( $order->get_total_refunded(), 2 ) );
	}

	public function test_refund_webhook_with_a_different_amount_is_not_recorded(): void {
		list( $order, $payment_id ) = $this->paid_order();
		$refund_id                  = wp_generate_uuid4();
		$this->respond( 'POST', '/payments/' . $payment_id . '/refund', 201, $this->refund_response( $refund_id, $payment_id, 'pending', 10.0 ) );
		$this->submit_refund( $order, '10.00' );
		$refunds_before = count( wc_get_order( $order->get_id() )->get_refunds() );

		$this->deliver_webhook(
			'PaymentRefunded',
			array(
				'refundId'          => $refund_id,
				'externalOrderRef'  => $payment_id,
				'refundAmountMinor' => 1001,
				'currencyCode'      => 'GBP',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( $refunds_before, $order->get_refunds() );
		$this->assertSame( 'yes', $order->get_meta( '_fynex_refund_attention', true ) );
	}
}
