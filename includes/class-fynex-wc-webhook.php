<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_Webhook {
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
			return new WP_REST_Response( array( 'received' => false ), 401 );
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) || empty( $event['eventId'] ) || empty( $event['eventType'] ) || empty( $event['payload'] ) || ! is_array( $event['payload'] ) ) {
			return new WP_REST_Response( array( 'received' => false ), 400 );
		}

		$payment_id = self::payment_id( $event );
		$order      = self::find_order( $payment_id );
		if ( ! $order instanceof WC_Order || self::was_processed( $order, (string) $event['eventId'] ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		self::apply_event( $order, $payment_id, (string) $event['eventType'], $event['payload'] );
		self::remember_event( $order, (string) $event['eventId'] );
		$order->save();
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

	private static function find_order( string $payment_id ): ?WC_Order {
		if ( '' === $payment_id ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_fynex_payment_id',
				'meta_value' => $payment_id,
				'return'     => 'objects',
			)
		);
		return isset( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}


	/** @param array<string,mixed> $payload */
	private static function apply_event( WC_Order $order, string $payment_id, string $event_type, array $payload ): void {
		if ( 'PaymentRefunded' === $event_type && ! empty( $payload['refundId'] ) ) {
			if ( Fynex_WC_Refund_Reconciliation::matches_webhook( $order, (string) $payload['refundId'], $payload ) ) {
				Fynex_WC_Refund_Reconciliation::mark_succeeded( $order, (string) $payload['refundId'] );
			} else {
				$order->update_meta_data( '_fynex_refund_attention', 'yes' );
				$order->add_order_note( __( 'Fynex refund webhook did not match a submitted refund. Review before recording it in WooCommerce.', 'fynex-woo-commerce' ) );
			}
			return;
		}
		if ( 'PaymentCompleted' !== $event_type ) {
			return;
		}

		$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
		if ( 'provider_completed' === $status ) {
			if ( ! self::payment_matches_attempt( $order, $payment_id, $payload ) ) {
				$order->update_meta_data( '_fynex_payment_attention', 'yes' );
				$order->add_order_note( __( 'Fynex payment completion did not match the expected attempt amount or currency. Review before fulfilling.', 'fynex-woo-commerce' ) );
				return;
			}
			self::update_attempt_status( $order, $payment_id, $status );
			if ( $order->is_paid() ) {
				if ( $payment_id !== (string) $order->get_meta( '_fynex_paid_payment_id', true ) ) {
					$order->update_meta_data( '_fynex_payment_attention', 'yes' );
					$order->add_order_note( __( 'A second Fynex payment attempt completed after this order was already paid. Review for a duplicate charge.', 'fynex-woo-commerce' ) );
				}
				return;
			}
			$order->payment_complete( $payment_id );
			$order->update_meta_data( '_fynex_paid_payment_id', $payment_id );
			$order->add_order_note( __( 'Fynex confirmed the payment.', 'fynex-woo-commerce' ) );
			return;
		}

		if ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			self::update_attempt_status( $order, $payment_id, $status );
			if ( $payment_id === (string) $order->get_meta( '_fynex_current_payment_id', true ) ) {
				$order->update_meta_data( '_fynex_attempt_terminal', $status );
				$order->update_status( 'failed', __( 'Fynex reported that the payment did not complete.', 'fynex-woo-commerce' ) );
			}
		}
	}

	/** @param array<string,mixed> $payload */
	private static function payment_matches_attempt( WC_Order $order, string $payment_id, array $payload ): bool {
		$attempt = self::attempt( $order, $payment_id );
		if ( ! is_array( $attempt ) || ! isset( $attempt['amount_minor'], $attempt['currency'] ) ) {
			return false;
		}
		$amount = $payload['amountMinor'] ?? null;
		if ( ! is_int( $amount ) && ! ( is_string( $amount ) && ctype_digit( $amount ) ) ) {
			return false;
		}
		return (int) $amount === (int) $attempt['amount_minor']
			&& strtoupper( (string) ( $payload['currencyCode'] ?? '' ) ) === (string) $attempt['currency'];
	}

	/** @return array<string,mixed>|null */
	private static function attempt( WC_Order $order, string $payment_id ): ?array {
		$attempts = $order->get_meta( '_fynex_payment_attempts', true );
		if ( ! is_array( $attempts ) ) {
			return null;
		}
		foreach ( $attempts as $attempt ) {
			if ( is_array( $attempt ) && $payment_id === ( $attempt['payment_id'] ?? '' ) ) {
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
			if ( is_array( $attempt ) && $payment_id === ( $attempt['payment_id'] ?? '' ) ) {
				$attempt['status'] = $status;
			}
		}
		unset( $attempt );
		$order->update_meta_data( '_fynex_payment_attempts', $attempts );
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
