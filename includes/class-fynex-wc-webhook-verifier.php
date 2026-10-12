<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_Webhook_Verifier {
	private const MAX_CLOCK_SKEW_SECONDS = 300;

	/**
	 * Verifies the exact Fynex HMAC-SHA256 wire contract without parsing the body.
	 */
	public static function verify( string $signature_header, string $timestamp, string $raw_body, string $secret, ?int $now = null ): bool {
		if ( '' === $secret || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		$timestamp_value = (int) $timestamp;
		$now             = null === $now ? time() : $now;
		if ( abs( $now - $timestamp_value ) > self::MAX_CLOCK_SKEW_SECONDS ) {
			return false;
		}

		if ( 0 !== strncmp( $signature_header, 'sha256=', 7 ) ) {
			return false;
		}

		$signature = substr( $signature_header, strlen( 'sha256=' ) );
		if ( 64 !== strlen( $signature ) || ! ctype_xdigit( $signature ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		return hash_equals( $expected, strtolower( $signature ) );
	}
}
