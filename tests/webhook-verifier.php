<?php

define( 'ABSPATH', __DIR__ . '/../' );
require_once __DIR__ . '/../includes/class-fynex-wc-webhook-verifier.php';

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$secret    = 'test-signing-secret';
$timestamp = '1775731200';
$body      = '{"eventId":42,"eventType":"PaymentCompleted","payload":{"paymentId":"wc-1-1","status":"provider_completed"}}';
$signature = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

assert_true( Fynex_WC_Webhook_Verifier::verify( $signature, $timestamp, $body, $secret, 1775731200 ), 'accepts a valid signature' );
assert_true( ! Fynex_WC_Webhook_Verifier::verify( $signature, $timestamp, $body . ' ', $secret, 1775731200 ), 'rejects a modified raw body' );
assert_true( ! Fynex_WC_Webhook_Verifier::verify( 'sha256=invalid', $timestamp, $body, $secret, 1775731200 ), 'rejects malformed signatures' );
assert_true( ! Fynex_WC_Webhook_Verifier::verify( $signature, $timestamp, $body, $secret, 1775731501 ), 'rejects expired deliveries' );
assert_true( ! Fynex_WC_Webhook_Verifier::verify( $signature, 'not-a-timestamp', $body, $secret, 1775731200 ), 'rejects malformed timestamps' );

fwrite( STDOUT, "Webhook verifier tests passed.\n" );
