<?php

defined( 'ABSPATH' ) || exit;

/**
 * A lease lock stored as a row in the options table.
 *
 * The row is created with INSERT IGNORE: the unique option_name key lets exactly
 * one process insert it. add_option() cannot be used, because it checks for the
 * row through the options cache and then writes with ON DUPLICATE KEY UPDATE, so
 * two processes can both "create" it. An expired lease is taken over with a
 * compare-and-swap UPDATE. The options cache is never involved.
 */
final class Fynex_WC_Lock {
	public const PREFIX = 'fynex_woo_lock_';

	public static function acquire( string $name, int $lease_seconds ): ?string {
		global $wpdb;
		$option = self::PREFIX . $name;
		$lease  = wp_generate_uuid4() . ':' . ( time() + $lease_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic create; see the class comment.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $option, $lease ) );
		if ( 1 === $inserted ) {
			return $lease;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock must be read from the database, never from a cache.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		if ( ! is_string( $current ) || self::expires_at( $current ) >= time() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-swap takeover of an expired lease.
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $lease ),
			array(
				'option_name'  => $option,
				'option_value' => $current,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- deletes the row only while it still holds this process's lease.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::PREFIX . $name,
				'option_value' => $lease,
			),
			array( '%s', '%s' )
		);
	}

	private static function expires_at( string $lease ): int {
		$separator = strrpos( $lease, ':' );
		return false === $separator ? 0 : (int) substr( $lease, $separator + 1 );
	}
}
