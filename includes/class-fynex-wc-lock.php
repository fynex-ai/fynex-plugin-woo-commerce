<?php

defined( 'ABSPATH' ) || exit;

/**
 * A lease lock stored as an autoload-off option.
 *
 * add_option() is an atomic insert, so only one process can create the row.
 * An expired lease is taken over with a compare-and-swap UPDATE, which
 * update_option() cannot express because it has no "only if the value is
 * still X" condition.
 */
final class Fynex_WC_Lock {
	public const PREFIX = 'fynex_woo_lock_';

	public static function acquire( string $name, int $lease_seconds ): ?string {
		$option = self::PREFIX . $name;
		$lease  = wp_generate_uuid4() . ':' . ( time() + $lease_seconds );
		self::clear_cache( $option );
		if ( add_option( $option, $lease, '', false ) ) {
			return $lease;
		}
		$current = get_option( $option, '' );
		$parts   = is_string( $current ) ? explode( ':', $current ) : array();
		$expires = (int) end( $parts );
		if ( '' === $current || $expires >= time() ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-swap takeover of an expired lease; see the class comment.
		$updated = $wpdb->update( $wpdb->options, array( 'option_value' => $lease ), array( 'option_name' => $option, 'option_value' => $current ), array( '%s' ), array( '%s', '%s' ) );
		self::clear_cache( $option );
		return 1 === $updated ? $lease : null;
	}

	/**
	 * Retries acquire() until $wait_seconds pass, for callers that cannot defer the work.
	 */
	public static function acquire_waiting( string $name, int $lease_seconds, int $wait_seconds ): ?string {
		$deadline = microtime( true ) + $wait_seconds;
		do {
			$lease = self::acquire( $name, $lease_seconds );
			if ( null !== $lease ) {
				return $lease;
			}
			usleep( 250000 );
		} while ( microtime( true ) < $deadline );
		return null;
	}

	public static function release( string $name, string $lease ): void {
		global $wpdb;
		$option = self::PREFIX . $name;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- deletes the row only while it still holds this process's lease.
		$wpdb->delete( $wpdb->options, array( 'option_name' => $option, 'option_value' => $lease ), array( '%s', '%s' ) );
		self::clear_cache( $option );
	}

	private static function clear_cache( string $option ): void {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( $option, 'notoptions' );
	}
}
