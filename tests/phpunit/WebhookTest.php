<?php

final class WebhookTest extends Fynex_Test_Case {
	public function test_captured_payment_marks_the_order_paid(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );

		$response = $this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ) );

		$this->assertSame( 200, $response->get_status() );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $payment_id, $order->get_meta( '_fynex_paid_payment_id', true ) );
		$this->assertSame( $payment_id, $order->get_transaction_id() );
	}

	public function test_legacy_payload_without_lifecycle_status_still_marks_the_order_paid(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$payload    = $this->payment_completed( $payment_id, 'captured' );
		unset( $payload['lifecycleStatus'], $payload['legacyStatus'] );

		$this->deliver_webhook( 'PaymentCompleted', $payload );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_payload_after_the_status_switch_marks_the_order_paid(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$payload    = $this->payment_completed( $payment_id, 'captured' );
		$payload['status'] = 'captured';

		$this->deliver_webhook( 'PaymentCompleted', $payload );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_second_attempt_is_found_and_paid(): void {
		$order = $this->create_order();
		$first = $this->start_checkout( $order );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $first, 'failed' ) );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );

		$second = $this->start_checkout( $order );
		$this->assertNotSame( $first, $second );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $second, 'captured' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( $second, $order->get_meta( '_fynex_paid_payment_id', true ) );
	}

	public function test_failed_then_captured_for_the_same_attempt_ends_paid(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );

		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'failed' ) );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ) );

		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_late_failure_does_not_unpay_a_paid_order(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );

		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ) );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'cancelled' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertNotSame( 'failed', $order->get_status() );
	}

	public function test_amount_mismatch_flags_the_order_instead_of_paying_it(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );

		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured', 4998 ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'yes', $order->get_meta( '_fynex_payment_attention', true ) );
	}

	public function test_replayed_event_is_applied_once(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$payload    = $this->payment_completed( $payment_id, 'captured' );

		$this->deliver_webhook( 'PaymentCompleted', $payload, 7001 );
		$notes_after_first = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$response          = $this->deliver_webhook( 'PaymentCompleted', $payload, 7001 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( $notes_after_first, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	public function test_bad_signature_is_rejected(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );

		$response = $this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ), null, 'wrong-secret' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_unknown_payment_and_other_event_types_are_acknowledged(): void {
		$this->assertSame( 200, $this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( 'wc-unknown-1-1', 'captured' ) )->get_status() );
		$this->assertSame( 200, $this->deliver_webhook( 'KYBVerificationApproved', array( 'applicantId' => 'abc' ) )->get_status() );
	}

	public function test_busy_order_asks_fynex_to_retry(): void {
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$lease      = Fynex_WC_Lock::acquire( Fynex_WC_Payment_Outcome::lock_name( $order->get_id() ), 60 );
		$this->assertNotNull( $lease );

		$response = $this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}
}
