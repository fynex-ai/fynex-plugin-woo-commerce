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
	private const GATEWAY_ID = 'fynex';

	/** Statuses an unpaid order may be in for a Fynex capture to complete it automatically. */
	private const PAYABLE_STATUSES = array( 'pending', 'failed', 'on-hold' );

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
	 * Loads the order bypassing caches that another request may have filled before
	 * the caller took the order lock.
	 */
	public static function load_order( int $order_id ): ?WC_Order {
		clean_post_cache( $order_id );
		if ( class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
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
		if ( $payment_id !== (string) $order->get_meta( '_fynex_current_payment_id', true ) ) {
			return;
		}
		$order->update_meta_data( '_fynex_attempt_terminal', $outcome );
		// A customer who went back and chose another payment method keeps the same order,
		// so only an order still waiting on Fynex is failed.
		if ( self::GATEWAY_ID === $order->get_payment_method() && $order->has_status( 'pending' ) ) {
			$order->update_status( 'failed', __( 'Fynex reported that the payment did not complete.', 'fynex-for-woocommerce' ) );
		}
	}

	/**
	 * Whether polling the attempt again is pointless. Only a capture is final: Fynex
	 * can turn a failed payment into a captured one later.
	 */
	public static function attempt_is_final( WC_Order $order, string $payment_id ): bool {
		$attempt = self::attempt( $order, $payment_id );
		return is_array( $attempt ) && self::CAPTURED === ( $attempt['status'] ?? '' );
	}

	private static function apply_captured( WC_Order $order, string $payment_id, ?int $amount_minor, string $currency ): void {
		if ( ! self::matches_attempt( $order, $payment_id, $amount_minor, $currency ) ) {
			Fynex_WC_Logger::warning(
				'Fynex reported a captured payment that does not match the attempt; the order was not marked paid.',
				array(
					'order_id'     => $order->get_id(),
					'payment_id'   => $payment_id,
					'amount_minor' => $amount_minor,
					'currency'     => $currency,
				)
			);
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
		$conflict = self::capture_conflict( $order, $payment_id, (int) $amount_minor, $currency );
		if ( '' !== $conflict ) {
			Fynex_WC_Logger::warning(
				'Fynex captured a payment this order cannot take automatically; the order was flagged.',
				array(
					'order_id'   => $order->get_id(),
					'payment_id' => $payment_id,
				)
			);
			$order->update_meta_data( '_fynex_payment_attention', 'yes' );
			$order->add_order_note( $conflict );
			return;
		}
		$order->update_meta_data( '_fynex_paid_payment_id', $payment_id );
		$order->delete_meta_data( '_fynex_attempt_terminal' );
		$order->payment_complete( $payment_id );
		$order->add_order_note( __( 'Fynex confirmed the payment.', 'fynex-for-woocommerce' ) );
	}

	/**
	 * Why a capture that matches its attempt still must not complete the order, or ''.
	 * The money has been taken in each case, so the merchant decides.
	 */
	private static function capture_conflict( WC_Order $order, string $payment_id, int $amount_minor, string $currency ): string {
		if ( self::GATEWAY_ID !== $order->get_payment_method() ) {
			/* translators: 1: Fynex payment reference, 2: name of the payment method the order now uses */
			return sprintf( __( 'Fynex captured payment %1$s, but this order is now paid with %2$s. Review it and refund one of the two payments.', 'fynex-for-woocommerce' ), $payment_id, $order->get_payment_method_title() );
		}
		if ( ! $order->has_status( self::PAYABLE_STATUSES ) ) {
			/* translators: 1: Fynex payment reference, 2: order status, e.g. Cancelled */
			return sprintf( __( 'Fynex captured payment %1$s while this order is %2$s. Reinstate the order or refund the payment.', 'fynex-for-woocommerce' ), $payment_id, wc_get_order_status_name( $order->get_status() ) );
		}
		$order_total_minor = (int) round( (float) $order->get_total() * 100 );
		if ( $order_total_minor !== $amount_minor ) {
			/* translators: 1: amount Fynex captured, 2: current order total */
			return sprintf( __( 'Fynex captured %1$s, but the order total is now %2$s. Review before fulfilling.', 'fynex-for-woocommerce' ), wc_price( $amount_minor / 100, array( 'currency' => $currency ) ), wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) );
		}
		return '';
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
