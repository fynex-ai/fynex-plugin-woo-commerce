<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_Refund_Reconciliation {
	private const ACTION             = 'fynex_woo_reconcile_refund';
	private const GROUP              = 'fynex-for-woocommerce';
	private const LOCK_LEASE_SECONDS = 300;

	/** Fynex can hold a refund for hours (a capture must settle before it can be refunded). */
	private const MAX_PENDING_SECONDS = 172800;

	/** Attempts at writing the WooCommerce record for a confirmed refund before asking the merchant. */
	private const MAX_LOCAL_REFUND_TRIES = 5;

	public static function register(): void {
		add_action( self::ACTION, array( __CLASS__, 'reconcile' ), 10, 2 );
	}

	public static function schedule( int $order_id, string $refund_id, int $delay = 60 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::ACTION, array( $order_id, $refund_id ), self::GROUP );
			return;
		}
		wp_schedule_single_event( time() + $delay, self::ACTION, array( $order_id, $refund_id ) );
	}

	/**
	 * @param int|string $order_id
	 */
	public static function reconcile( $order_id, string $refund_id ): void {
		$order_id = (int) $order_id;
		$lock     = Fynex_WC_Payment_Outcome::lock_name( $order_id );
		$lease    = Fynex_WC_Lock::acquire( $lock, self::LOCK_LEASE_SECONDS );
		if ( null === $lease ) {
			self::schedule( $order_id, $refund_id );
			return;
		}
		try {
			$order = Fynex_WC_Payment_Outcome::load_order( $order_id );
			if ( null !== $order ) {
				self::reconcile_order( $order, $refund_id );
			}
		} finally {
			Fynex_WC_Lock::release( $lock, $lease );
		}
	}

	private static function reconcile_order( WC_Order $order, string $refund_id ): void {
		$token = (string) get_option( 'fynex_woo_api_token', '' );
		if ( '' === $token ) {
			$settings = get_option( 'woocommerce_fynex_settings', array() );
			$token    = is_array( $settings ) ? (string) ( $settings['api_token'] ?? '' ) : '';
		}
		if ( '' === trim( $token ) ) {
			return;
		}

		$response = ( new Fynex_WC_API_Client( $token ) )->get_refund( $refund_id );
		if ( is_wp_error( $response ) ) {
			self::reschedule_if_current( $order, $refund_id );
			return;
		}
		$status = sanitize_key( (string) ( $response['status'] ?? '' ) );
		if ( ! self::matches_refund( $order, $refund_id, $response ) ) {
			Fynex_WC_Logger::warning(
				'Fynex refund data did not match the submitted refund.',
				array(
					'order_id'  => $order->get_id(),
					'refund_id' => $refund_id,
				)
			);
			$order->update_meta_data( '_fynex_refund_attention', 'yes' );
			$order->add_order_note( __( 'Fynex refund data did not match the submitted refund. Review before recording it in WooCommerce.', 'fynex-for-woocommerce' ) );
			$order->save();
			return;
		}
		// The submission key (_fynex_refund_submission) belongs to whichever refund POST is
		// still unanswered, possibly a different refund, so it is not cleared here.
		if ( 'succeeded' === $status ) {
			self::mark_succeeded( $order, $refund_id );
			$order->save();
			return;
		}
		if ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			self::mark_failed( $order, $refund_id );
			$order->save();
			return;
		}
		self::reschedule_if_current( $order, $refund_id );
	}

	/** @param array<string,mixed> $payload */
	public static function matches_webhook( WC_Order $order, string $refund_id, array $payload ): bool {
		return self::matches_values(
			$order,
			$refund_id,
			(string) ( $payload['externalOrderRef'] ?? '' ),
			$payload['refundAmountMinor'] ?? null,
			(string) ( $payload['currencyCode'] ?? '' )
		);
	}

	/** @param array<string,mixed> $response */
	private static function matches_refund( WC_Order $order, string $refund_id, array $response ): bool {
		return self::matches_values( $order, $refund_id, (string) ( $response['paymentId'] ?? '' ), isset( $response['amount'] ) ? (int) round( (float) $response['amount'] * 100 ) : null, (string) ( $response['currencyCode'] ?? '' ) );
	}

	private static function matches_values( WC_Order $order, string $refund_id, string $payment_id, $amount_minor, string $currency ): bool {
		foreach ( self::refunds( $order ) as $refund ) {
			if ( ( $refund['id'] ?? '' ) !== $refund_id ) {
				continue;
			}
			return (string) ( $refund['payment_id'] ?? '' ) === $payment_id
				&& (int) ( $refund['amount_minor'] ?? -1 ) === (int) $amount_minor
				&& (string) ( $refund['currency'] ?? '' ) === strtoupper( $currency );
		}
		return false;
	}

	public static function mark_succeeded( WC_Order $order, string $refund_id ): void {
		$lock  = 'refund_' . md5( $order->get_id() . ':' . $refund_id );
		$lease = Fynex_WC_Lock::acquire( $lock, self::LOCK_LEASE_SECONDS );
		if ( null === $lease ) {
			self::schedule( (int) $order->get_id(), $refund_id );
			return;
		}
		try {
			$changed = self::update_refund_status( $order, $refund_id, 'succeeded' );
			self::ensure_local_refund( $order, $refund_id );
			if ( $changed ) {
				/* translators: %s: Fynex refund ID */
				$order->add_order_note( sprintf( __( 'Fynex confirmed refund %s.', 'fynex-for-woocommerce' ), $refund_id ) );
			}
			$order->save();
		} finally {
			Fynex_WC_Lock::release( $lock, $lease );
		}
	}

	private static function ensure_local_refund( WC_Order $order, string $refund_id ): void {
		foreach ( $order->get_refunds() as $local_refund ) {
			if ( $refund_id === (string) $local_refund->get_meta( '_fynex_refund_id', true ) ) {
				self::set_local_refund_id( $order, $refund_id, (int) $local_refund->get_id() );
				return;
			}
		}
		foreach ( self::refunds( $order ) as $refund ) {
			if ( ( $refund['id'] ?? '' ) !== $refund_id || ! empty( $refund['local_refund_id'] ) || ! empty( $refund['local_refund_failed'] ) ) {
				continue;
			}
			$marker = static function ( $local_refund, $args ) use ( $refund_id, $order ): void {
				if ( (int) ( $args['order_id'] ?? 0 ) === (int) $order->get_id() ) {
					$local_refund->update_meta_data( '_fynex_refund_id', $refund_id );
				}
			};
			add_action( 'woocommerce_create_refund', $marker, 10, 2 );
			try {
				$local_refund = wc_create_refund(
					array(
						'amount'         => (float) ( $refund['amount'] ?? 0 ),
						'reason'         => (string) ( $refund['reason'] ?? '' ),
						'order_id'       => $order->get_id(),
						'refund_payment' => false,
						'restock_items'  => false,
					)
				);
			} finally {
				remove_action( 'woocommerce_create_refund', $marker, 10 );
			}
			if ( is_wp_error( $local_refund ) ) {
				self::local_refund_failed( $order, $refund_id, $local_refund );
				return;
			}
			self::set_local_refund_id( $order, $refund_id, (int) $local_refund->get_id() );
			return;
		}
	}

	/**
	 * WooCommerce refuses a record for more than the order's remaining amount, which
	 * no retry fixes, so give up after a few tries and leave it to the merchant.
	 */
	private static function local_refund_failed( WC_Order $order, string $refund_id, WP_Error $error ): void {
		$refunds = self::refunds( $order );
		$tries   = 0;
		foreach ( $refunds as &$refund ) {
			if ( is_array( $refund ) && ( $refund['id'] ?? '' ) === $refund_id ) {
				$tries                         = (int) ( $refund['local_refund_tries'] ?? 0 ) + 1;
				$refund['local_refund_tries']  = $tries;
				$refund['local_refund_failed'] = $tries >= self::MAX_LOCAL_REFUND_TRIES;
			}
		}
		unset( $refund );
		$order->update_meta_data( '_fynex_refunds', $refunds );
		$order->update_meta_data( '_fynex_refund_attention', 'yes' );
		Fynex_WC_Logger::warning(
			'WooCommerce refused the record for a refund Fynex confirmed.',
			array(
				'order_id'  => $order->get_id(),
				'refund_id' => $refund_id,
				'tries'     => $tries,
				'error'     => $error->get_error_message(),
			)
		);
		if ( $tries >= self::MAX_LOCAL_REFUND_TRIES ) {
			/* translators: 1: Fynex refund ID, 2: error from WooCommerce */
			$order->add_order_note( sprintf( __( 'Fynex refunded %1$s, but WooCommerce would not record it (%2$s). Record the refund on this order by hand.', 'fynex-for-woocommerce' ), $refund_id, $error->get_error_message() ) );
			return;
		}
		if ( 1 === $tries ) {
			$order->add_order_note( __( 'Fynex confirmed a refund, but WooCommerce could not create its local refund record. Retrying automatically.', 'fynex-for-woocommerce' ) );
		}
		self::schedule( (int) $order->get_id(), $refund_id, 300 );
	}

	private static function set_local_refund_id( WC_Order $order, string $refund_id, int $local_refund_id ): void {
		$refunds = self::refunds( $order );
		foreach ( $refunds as &$refund ) {
			if ( is_array( $refund ) && ( $refund['id'] ?? '' ) === $refund_id ) {
				$refund['local_refund_id'] = $local_refund_id;
			}
		}
		unset( $refund );
		$order->update_meta_data( '_fynex_refunds', $refunds );
	}

	private static function mark_failed( WC_Order $order, string $refund_id ): void {
		if ( ! self::update_refund_status( $order, $refund_id, 'failed' ) ) {
			return;
		}
		$order->update_meta_data( '_fynex_refund_attention', 'yes' );
		$local_refund_id = self::local_refund_id( $order, $refund_id );
		Fynex_WC_Logger::warning(
			'Fynex reported a refund as failed.',
			array(
				'order_id'        => $order->get_id(),
				'refund_id'       => $refund_id,
				'local_refund_id' => $local_refund_id,
			)
		);
		if ( $local_refund_id > 0 ) {
			/* translators: 1: Fynex refund ID, 2: WooCommerce refund ID */
			$order->add_order_note( sprintf( __( 'Fynex refund %1$s failed, so no money was returned. Delete WooCommerce refund #%2$d from this order, then refund again.', 'fynex-for-woocommerce' ), $refund_id, $local_refund_id ) );
			return;
		}
		/* translators: %s: Fynex refund ID */
		$order->add_order_note( sprintf( __( 'Fynex refund %s failed. Review this refund before issuing another one.', 'fynex-for-woocommerce' ), $refund_id ) );
	}

	private static function local_refund_id( WC_Order $order, string $refund_id ): int {
		foreach ( self::refunds( $order ) as $refund ) {
			if ( is_array( $refund ) && ( $refund['id'] ?? '' ) === $refund_id ) {
				return (int) ( $refund['local_refund_id'] ?? 0 );
			}
		}
		return 0;
	}

	private static function reschedule_if_current( WC_Order $order, string $refund_id ): void {
		foreach ( self::refunds( $order ) as $refund ) {
			if ( ( $refund['id'] ?? '' ) !== $refund_id || 'pending' !== ( $refund['status'] ?? '' ) ) {
				continue;
			}
			$age = time() - (int) ( $refund['createdAt'] ?? 0 );
			if ( $age < self::MAX_PENDING_SECONDS ) {
				self::schedule( (int) $order->get_id(), $refund_id, self::backoff( $age ) );
				return;
			}
			$order->update_meta_data( '_fynex_refund_attention', 'yes' );
			Fynex_WC_Logger::warning(
				'Fynex refund still pending after 48 hours.',
				array(
					'order_id'  => $order->get_id(),
					'refund_id' => $refund_id,
				)
			);
			/* translators: %s: Fynex refund ID */
			$order->add_order_note( sprintf( __( 'Fynex refund %s is still pending after 48 hours. Contact Fynex support before retrying.', 'fynex-for-woocommerce' ), $refund_id ) );
			$order->save();
			return;
		}
	}

	/**
	 * Every minute at first, then less often: most refunds settle in minutes, some wait for a settlement batch.
	 */
	private static function backoff( int $age ): int {
		if ( $age < 1200 ) {
			return 60;
		}
		return $age < 21600 ? 900 : 3600;
	}

	private static function update_refund_status( WC_Order $order, string $refund_id, string $status ): bool {
		$refunds = self::refunds( $order );
		$changed = false;
		foreach ( $refunds as &$refund ) {
			if ( ( $refund['id'] ?? '' ) === $refund_id && ( $refund['status'] ?? '' ) !== $status ) {
				$refund['status'] = $status;
				$changed          = true;
			}
		}
		unset( $refund );
		if ( $changed ) {
			$order->update_meta_data( '_fynex_refunds', $refunds );
		}
		return $changed;
	}

	/** @return array<int,array<string,mixed>> */
	private static function refunds( WC_Order $order ): array {
		$refunds = $order->get_meta( '_fynex_refunds', true );
		return is_array( $refunds ) ? $refunds : array();
	}
}
