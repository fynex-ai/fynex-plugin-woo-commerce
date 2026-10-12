<?php

defined( 'ABSPATH' ) || exit;

/**
 * Applies a payment result reported by Fynex to the WooCommerce order.
 *
 * Shared by the webhook, the scheduled status check and the order-received
 * page check, so the three paths cannot disagree. Callers hold the order lock
 * (see lock_name()) and load the order after acquiring it.
 */
final class Fynex_WC_Payment_Outcome {
	public const CAPTURED  = 'captured';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';

	/**
	 * Previous `status` values that mean the money was taken. Fynex keeps sending
	 * them in `status` until its announced switch to the lifecycle values.
	 */
	private const LEGACY_CAPTURED = array( 'provider_completed', 'funds_in_flight', 'settled', 'deposit_confirmed', 'refund_pending', 'refund_failed', 'refund_cancelled' );

	public static function lock_name( int $order_id ): string {
		return 'order_' . $order_id;
	}

	/**
	 * Reads the outcome from a PaymentCompleted payload or a GET /payments response.
	 *
	 * `lifecycleStatus` is authoritative. `status` is the fallback for responses
	 * that do not carry it; it holds either the legacy or the lifecycle value
	 * depending on Fynex's rollout. Anything that is not final returns ''.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function from_status_fields( array $data ): string {
		$final     = array( self::CAPTURED, self::FAILED, self::CANCELLED );
		$lifecycle = sanitize_key( (string) ( $data['lifecycleStatus'] ?? '' ) );
		if ( '' !== $lifecycle ) {
			return in_array( $lifecycle, $final, true ) ? $lifecycle : '';
		}
		$status = sanitize_key( (string) ( $data['status'] ?? '' ) );
		if ( in_array( $status, self::LEGACY_CAPTURED, true ) ) {
			return self::CAPTURED;
		}
		return in_array( $status, $final, true ) ? $status : '';
	}

	public static function apply( WC_Order $order, string $payment_id, string $outcome, ?int $amount_minor, string $currency ): void {
		if ( self::CAPTURED === $outcome ) {
			self::apply_captured( $order, $payment_id, $amount_minor, $currency );
			return;
		}
		if ( self::FAILED !== $outcome && self::CANCELLED !== $outcome ) {
			return;
		}
		self::update_attempt_status( $order, $payment_id, $outcome );
		if ( $order->is_paid() || $payment_id !== (string) $order->get_meta( '_fynex_current_payment_id', true ) ) {
			return;
		}
		$order->update_meta_data( '_fynex_attempt_terminal', $outcome );
		$order->update_status( 'failed', __( 'Fynex reported that the payment did not complete.', 'fynex-for-woocommerce' ) );
	}

	/**
	 * Whether the attempt already reached a result, so polling it again is pointless.
	 */
	public static function attempt_is_final( WC_Order $order, string $payment_id ): bool {
		$attempt = self::attempt( $order, $payment_id );
		return is_array( $attempt ) && in_array( $attempt['status'] ?? '', array( self::CAPTURED, self::FAILED, self::CANCELLED ), true );
	}

	private static function apply_captured( WC_Order $order, string $payment_id, ?int $amount_minor, string $currency ): void {
		if ( ! self::matches_attempt( $order, $payment_id, $amount_minor, $currency ) ) {
			if ( 'yes' !== $order->get_meta( '_fynex_payment_attention', true ) ) {
				$order->update_meta_data( '_fynex_payment_attention', 'yes' );
				$order->add_order_note( __( 'Fynex payment completion did not match the expected attempt amount or currency. Review before fulfilling.', 'fynex-for-woocommerce' ) );
			}
			return;
		}
		self::update_attempt_status( $order, $payment_id, self::CAPTURED );
		if ( $order->is_paid() ) {
			if ( $payment_id !== (string) $order->get_meta( '_fynex_paid_payment_id', true ) ) {
				$order->update_meta_data( '_fynex_payment_attention', 'yes' );
				$order->add_order_note( __( 'A second Fynex payment attempt completed after this order was already paid. Review for a duplicate charge.', 'fynex-for-woocommerce' ) );
			}
			return;
		}
		$order->update_meta_data( '_fynex_paid_payment_id', $payment_id );
		$order->delete_meta_data( '_fynex_attempt_terminal' );
		$order->payment_complete( $payment_id );
		$order->add_order_note( __( 'Fynex confirmed the payment.', 'fynex-for-woocommerce' ) );
	}

	private static function matches_attempt( WC_Order $order, string $payment_id, ?int $amount_minor, string $currency ): bool {
		$attempt = self::attempt( $order, $payment_id );
		if ( ! is_array( $attempt ) || ! isset( $attempt['amount_minor'], $attempt['currency'] ) || null === $amount_minor ) {
			return false;
		}
		return $amount_minor === (int) $attempt['amount_minor']
			&& strtoupper( $currency ) === (string) $attempt['currency'];
	}

	/** @return array<string,mixed>|null */
	private static function attempt( WC_Order $order, string $payment_id ): ?array {
		$attempts = $order->get_meta( '_fynex_payment_attempts', true );
		if ( ! is_array( $attempts ) ) {
			return null;
		}
		foreach ( $attempts as $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['payment_id'] ?? '' ) === $payment_id ) {
				return $attempt;
			}
		}
		return null;
	}

	private static function update_attempt_status( WC_Order $order, string $payment_id, string $status ): void {
		$attempts = $order->get_meta( '_fynex_payment_attempts', true );
		if ( ! is_array( $attempts ) ) {
			return;
		}
		foreach ( $attempts as &$attempt ) {
			if ( is_array( $attempt ) && ( $attempt['payment_id'] ?? '' ) === $payment_id ) {
				$attempt['status'] = $status;
			}
		}
		unset( $attempt );
		$order->update_meta_data( '_fynex_payment_attempts', $attempts );
	}
}
