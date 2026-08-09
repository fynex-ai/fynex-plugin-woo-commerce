<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_Refund_Reconciliation {
	private const ACTION = 'fynex_woo_reconcile_refund';
	private const GROUP  = 'fynex-woo-commerce';
	private const MAX_AGE_SECONDS = 1200;

	public static function register(): void {
		add_action( self::ACTION, array( __CLASS__, 'reconcile' ), 10, 2 );
	}

	public static function schedule( int $order_id, string $refund_id ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 60, self::ACTION, array( $order_id, $refund_id ), self::GROUP );
			return;
		}
		wp_schedule_single_event( time() + 60, self::ACTION, array( $order_id, $refund_id ) );
	}

	public static function reconcile( int $order_id, string $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$settings = get_option( 'woocommerce_fynex_settings', array() );
		$token    = is_array( $settings ) ? (string) ( $settings['api_token'] ?? '' ) : '';
		if ( '' === trim( $token ) ) {
			return;
		}

		$response = ( new Fynex_WC_API_Client( $token ) )->get_refund( $refund_id );
		if ( is_wp_error( $response ) ) {
			self::reschedule_if_current( $order, $refund_id );
			return;
		}
		$status = sanitize_key( (string) ( $response['status'] ?? '' ) );
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

	public static function mark_succeeded( WC_Order $order, string $refund_id ): void {
		if ( self::update_refund_status( $order, $refund_id, 'succeeded' ) ) {
			$order->add_order_note( sprintf( __( 'Fynex confirmed refund %s.', 'fynex-woo-commerce' ), $refund_id ) );
		}
	}

	private static function mark_failed( WC_Order $order, string $refund_id ): void {
		if ( self::update_refund_status( $order, $refund_id, 'failed' ) ) {
			$order->update_meta_data( '_fynex_refund_attention', 'yes' );
			$order->add_order_note( sprintf( __( 'Fynex refund %s failed. Review this refund before issuing another one.', 'fynex-woo-commerce' ), $refund_id ) );
		}
	}

	private static function reschedule_if_current( WC_Order $order, string $refund_id ): void {
		foreach ( self::refunds( $order ) as $refund ) {
			if ( ( $refund['id'] ?? '' ) !== $refund_id || 'pending' !== ( $refund['status'] ?? '' ) ) {
				continue;
			}
			if ( time() - (int) ( $refund['createdAt'] ?? 0 ) < self::MAX_AGE_SECONDS ) {
				self::schedule( (int) $order->get_id(), $refund_id );
				return;
			}
			$order->update_meta_data( '_fynex_refund_attention', 'yes' );
			$order->add_order_note( sprintf( __( 'Fynex refund %s is still pending after 20 minutes. Contact Fynex support before retrying.', 'fynex-woo-commerce' ), $refund_id ) );
			$order->save();
			return;
		}
	}

	private static function update_refund_status( WC_Order $order, string $refund_id, string $status ): bool {
		$refunds = self::refunds( $order );
		$changed = false;
		foreach ( $refunds as &$refund ) {
			if ( ( $refund['id'] ?? '' ) === $refund_id && ( $refund['status'] ?? '' ) !== $status ) {
				$refund['status'] = $status;
				$changed = true;
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
