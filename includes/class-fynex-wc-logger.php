<?php

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce → Status → Logs under the source fynex-for-woocommerce.
 *
 * Warnings and errors are always written, so the first failure is on record
 * before anyone thinks to turn logging on. Info and debug lines need the
 * "Debug log" setting. Callers never pass the API token, the webhook secret
 * or a request body; the plugin never handles card data.
 */
final class Fynex_WC_Logger {
	public const SOURCE = 'fynex-for-woocommerce';

	/**
	 * @param array<string,mixed> $context
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private static function log( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( 'info' === $level && ! self::debug_enabled() ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array_merge( $context, array( 'source' => self::SOURCE ) ) );
	}

	private static function debug_enabled(): bool {
		$settings = get_option( 'woocommerce_fynex_settings', array() );
		return is_array( $settings ) && 'yes' === ( $settings['debug'] ?? 'no' );
	}
}
