<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_Webhook {
	private const LOCK_LEASE_SECONDS = 60;
	private const LOCK_WAIT_SECONDS  = 6;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route(): void {
		register_rest_route(
			'fynex/v1',
			'/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function receive( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = $request->get_body();
		$secret   = self::webhook_secret();
		if ( ! Fynex_WC_Webhook_Verifier::verify(
			(string) $request->get_header( 'x-fynex-signature' ),
			(string) $request->get_header( 'x-fynex-timestamp' ),
			$raw_body,
			$secret
		) ) {
			Fynex_WC_Logger::warning( 'Fynex webhook rejected: signature or timestamp did not verify. Check that the stored signing secret matches the callback in Fynex.' );
			return new WP_REST_Response( array( 'received' => false ), 401 );
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) || empty( $event['eventId'] ) || empty( $event['eventType'] ) || empty( $event['payload'] ) || ! is_array( $event['payload'] ) ) {
			Fynex_WC_Logger::warning( 'Fynex webhook rejected: the signed body is not a valid event.' );
			return new WP_REST_Response( array( 'received' => false ), 400 );
		}

		$context    = array(
			'event_id'   => (string) $event['eventId'],
			'event_type' => (string) $event['eventType'],
		);
		$payment_id = self::payment_id( $event );
		$order_id   = self::find_order_id( $payment_id );
		if ( null === $order_id ) {
			Fynex_WC_Logger::info( 'Fynex webhook acknowledged without an order to update.', $context + array( 'payment_id' => $payment_id ) );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}
		$context['order_id'] = $order_id;

		// Fynex counts only HTTP 200 as delivered, and its delivery timeout is ten
		// seconds, so wait a little for a concurrent writer and otherwise ask for a retry.
		$lock  = Fynex_WC_Payment_Outcome::lock_name( $order_id );
		$lease = Fynex_WC_Lock::acquire_waiting( $lock, self::LOCK_LEASE_SECONDS, self::LOCK_WAIT_SECONDS );
		if ( null === $lease ) {
			Fynex_WC_Logger::warning( 'Fynex webhook deferred: the order is being updated by another request; Fynex will retry.', $context );
			return new WP_REST_Response( array( 'received' => false ), 503 );
		}
		try {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && ! self::was_processed( $order, (string) $event['eventId'] ) ) {
				self::apply_event( $order, $payment_id, (string) $event['eventType'], $event['payload'] );
				self::remember_event( $order, (string) $event['eventId'] );
				$order->save();
				Fynex_WC_Logger::info( 'Fynex webhook applied.', $context + array( 'order_status' => $order->get_status() ) );
			}
		} finally {
			Fynex_WC_Lock::release( $lock, $lease );
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function webhook_secret(): string {
		$secret = get_option( 'fynex_woo_webhook_secret', '' );
		if ( is_string( $secret ) && '' !== $secret ) {
			return $secret;
		}
		$settings = get_option( 'woocommerce_fynex_settings', array() );
		return is_array( $settings ) ? (string) ( $settings['webhook_secret'] ?? '' ) : '';
	}

	/** @param array<string,mixed> $event */
	private static function payment_id( array $event ): string {
		$payload = $event['payload'];
		if ( isset( $payload['paymentId'] ) && is_string( $payload['paymentId'] ) ) {
			return $payload['paymentId'];
		}
		if ( isset( $payload['externalOrderRef'] ) && is_string( $payload['externalOrderRef'] ) ) {
			return $payload['externalOrderRef'];
		}
		return '';
	}

	/**
	 * Finds the order by the payment reference the plugin sent as externalOrderRef.
	 *
	 * An order carries one _fynex_payment_id row per attempt, so this matches a
	 * retry as well as the first attempt. The meta_key/meta_value shorthand is
	 * deliberate: HPOS turns it into a meta_query itself, while the posts data
	 * store rejects a meta_query argument outright (WooCommerce 9.2+).
	 */
	private static function find_order_id( string $payment_id ): ?int {
		if ( '' === $payment_id ) {
			return null;
		}
		$ids = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => '_fynex_payment_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the payment reference is only stored as order meta.
				'meta_value' => $payment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
			)
		);
		return isset( $ids[0] ) ? (int) $ids[0] : null;
	}

	/** @param array<string,mixed> $payload */
	private static function apply_event( WC_Order $order, string $payment_id, string $event_type, array $payload ): void {
		if ( 'PaymentRefunded' === $event_type && ! empty( $payload['refundId'] ) ) {
			if ( Fynex_WC_Refund_Reconciliation::matches_webhook( $order, (string) $payload['refundId'], $payload ) ) {
				Fynex_WC_Refund_Reconciliation::mark_succeeded( $order, (string) $payload['refundId'] );
			} else {
				$order->update_meta_data( '_fynex_refund_attention', 'yes' );
				$order->add_order_note( __( 'Fynex refund webhook did not match a submitted refund. Review before recording it in WooCommerce.', 'fynex-for-woocommerce' ) );
			}
			return;
		}
		if ( 'PaymentCompleted' !== $event_type ) {
			return;
		}
		$amount = $payload['amountMinor'] ?? null;
		if ( is_string( $amount ) && ctype_digit( $amount ) ) {
			$amount = (int) $amount;
		}
		Fynex_WC_Payment_Outcome::apply(
			$order,
			$payment_id,
			Fynex_WC_Payment_Outcome::from_status_fields( $payload ),
			is_int( $amount ) ? $amount : null,
			(string) ( $payload['currencyCode'] ?? '' )
		);
	}

	private static function was_processed( WC_Order $order, string $event_id ): bool {
		return in_array( $event_id, self::event_ids( $order ), true );
	}

	private static function remember_event( WC_Order $order, string $event_id ): void {
		$event_ids   = self::event_ids( $order );
		$event_ids[] = $event_id;
		$order->update_meta_data( '_fynex_webhook_event_ids', array_slice( array_values( array_unique( $event_ids ) ), -50 ) );
	}

	/** @return array<int,string> */
	private static function event_ids( WC_Order $order ): array {
		$event_ids = $order->get_meta( '_fynex_webhook_event_ids', true );
		return is_array( $event_ids ) ? array_values( array_filter( $event_ids, 'is_string' ) ) : array();
	}
}
