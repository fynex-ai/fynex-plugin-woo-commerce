<?php

final class WebhookVerifierTest extends WP_UnitTestCase {
	private const SECRET    = 'test-signing-secret';
	private const TIMESTAMP = '1775731200';
	private const BODY      = '{"eventId":42,"eventType":"PaymentCompleted","payload":{"paymentId":"wc-1-1","status":"provider_completed"}}';

	private function signature( string $body = self::BODY ): string {
		return 'sha256=' . hash_hmac( 'sha256', self::TIMESTAMP . '.' . $body, self::SECRET );
	}

	public function test_accepts_a_valid_signature(): void {
		$this->assertTrue( Fynex_WC_Webhook_Verifier::verify( $this->signature(), self::TIMESTAMP, self::BODY, self::SECRET, 1775731200 ) );
	}

	public function test_rejects_a_modified_body(): void {
		$this->assertFalse( Fynex_WC_Webhook_Verifier::verify( $this->signature(), self::TIMESTAMP, self::BODY . ' ', self::SECRET, 1775731200 ) );
	}

	public function test_rejects_malformed_signatures(): void {
		$this->assertFalse( Fynex_WC_Webhook_Verifier::verify( 'sha256=invalid', self::TIMESTAMP, self::BODY, self::SECRET, 1775731200 ) );
		$this->assertFalse( Fynex_WC_Webhook_Verifier::verify( substr( $this->signature(), 7 ), self::TIMESTAMP, self::BODY, self::SECRET, 1775731200 ) );
	}

	public function test_rejects_deliveries_outside_the_five_minute_window(): void {
		$this->assertFalse( Fynex_WC_Webhook_Verifier::verify( $this->signature(), self::TIMESTAMP, self::BODY, self::SECRET, 1775731501 ) );
		$this->assertFalse( Fynex_WC_Webhook_Verifier::verify( $this->signature(), self::TIMESTAMP, self::BODY, self::SECRET, 1775730899 ) );
	}

	public function test_rejects_malformed_timestamps_and_an_empty_secret(): void {
		$this->assertFalse( Fynex_WC_Webhook_Verifier::verify( $this->signature(), 'not-a-timestamp', self::BODY, self::SECRET, 1775731200 ) );
		$this->assertFalse( Fynex_WC_Webhook_Verifier::verify( $this->signature(), self::TIMESTAMP, self::BODY, '', 1775731200 ) );
	}
}
