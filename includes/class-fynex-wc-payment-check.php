<?php

defined( 'ABSPATH' ) || exit;

/**
 * Asks Fynex for the payment result when the webhook may not have arrived.
 *
 * Fynex gives up on a webhook after three attempts within about fifteen
 * seconds, so a store that is briefly unreachable would otherwise leave a
 * paid order pending forever. Two fallbacks read GET /payments/{ref}:
 * the order-received page when the customer returns, and a scheduled check
 * that repeats until the attempt reaches a result or MAX_AGE_SECONDS pass.
 */
final class Fynex_WC_Payment_Check {
	private const ACTION          = 'fynex_woo_check_payment';
	private const GROUP           = 'fynex-for-woocommerce';
	private const INTERVAL        = 300;
	private const MAX_AGE_SECONDS = 3600;
	private const LOCK_LEASE      = 60;
	private const RETURN_TIMEOUT  = 5;

	public static function register(): void {
		add_action( self::ACTION, array( __CLASS__, 'run_scheduled' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'check_on_return' ), 5 );
	}

	public static function schedule( int $order_id, string $payment_id ): void {
		$args = array( $order_id, $payment_id );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( self::ACTION, $args, self::GROUP ) ) {
				as_schedule_single_action( time() + self::INTERVAL, self::ACTION, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::ACTION, $args ) ) {
			wp_schedule_single_event( time() + self::INTERVAL, self::ACTION, $args );
		}
	}

	/**
	 * @param int|string $order_id
	 */
	public static function run_scheduled( $order_id, string $payment_id ): void {
		$order_id = (int) $order_id;
		$lease    = Fynex_WC_Lock::acquire( Fynex_WC_Payment_Outcome::lock_name( $order_id ), self::LOCK_LEASE );
		if ( null === $lease ) {
			self::schedule( $order_id, $payment_id );
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! self::needs_check( $order, $payment_id ) ) {
				return;
			}
			self::check( $order, $payment_id, null );
			if ( self::needs_check( $order, $payment_id ) && ! self::too_old( $order, $payment_id ) ) {
				self::schedule( $order_id, $payment_id );
			}
		} finally {
			Fynex_WC_Lock::release( Fynex_WC_Payment_Outcome::lock_name( $order_id ), $lease );
		}
	}

	/**
	 * Runs before WooCommerce renders the order-received page, so the customer
	 * sees the paid order even when the webhook is late or lost.
	 */
	public static function check_on_return(): void {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}
		global $wp;
		$order_id = absint( $wp->query_vars['order-received'] ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the capability here, compared with hash_equals() below.
		$order_key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( $order_id <= 0 || '' === $order_key ) {
			return;
		}
		// Never wait here: if the webhook holds the lock it is applying the result already.
		$lease = Fynex_WC_Lock::acquire( Fynex_WC_Payment_Outcome::lock_name( $order_id ), self::LOCK_LEASE );
		if ( null === $lease ) {
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || 'fynex' !== $order->get_payment_method() || ! hash_equals( $order->get_order_key(), $order_key ) ) {
				return;
			}
			$payment_id = (string) $order->get_meta( '_fynex_current_payment_id', true );
			if ( '' !== $payment_id && self::needs_check( $order, $payment_id ) ) {
				self::check( $order, $payment_id, self::RETURN_TIMEOUT );
			}
		} finally {
			Fynex_WC_Lock::release( Fynex_WC_Payment_Outcome::lock_name( $order_id ), $lease );
		}
	}

	private static function needs_check( WC_Order $order, string $payment_id ): bool {
		return ! $order->is_paid() && ! Fynex_WC_Payment_Outcome::attempt_is_final( $order, $payment_id );
	}

	private static function check( WC_Order $order, string $payment_id, ?int $timeout ): void {
		$token = (string) get_option( 'fynex_woo_api_token', '' );
		if ( '' === trim( $token ) ) {
			return;
		}
		$client   = null === $timeout ? new Fynex_WC_API_Client( $token ) : new Fynex_WC_API_Client( $token, $timeout );
		$response = $client->get_payment( $payment_id );
		if ( is_wp_error( $response ) ) {
			return;
		}
		$outcome = Fynex_WC_Payment_Outcome::from_status_fields( $response );
		if ( '' === $outcome ) {
			return;
		}
		$amount_minor = isset( $response['amount'] ) && is_numeric( $response['amount'] ) ? (int) round( (float) $response['amount'] * 100 ) : null;
		Fynex_WC_Payment_Outcome::apply( $order, $payment_id, $outcome, $amount_minor, (string) ( $response['currencyCode'] ?? '' ) );
		$order->save();
	}

	private static function too_old( WC_Order $order, string $payment_id ): bool {
		$attempts = $order->get_meta( '_fynex_payment_attempts', true );
		foreach ( is_array( $attempts ) ? $attempts : array() as $attempt ) {
			if ( is_array( $attempt ) && ( $attempt['payment_id'] ?? '' ) === $payment_id ) {
				return time() - (int) ( $attempt['created_at'] ?? 0 ) > self::MAX_AGE_SECONDS;
			}
		}
		return true;
	}
}
