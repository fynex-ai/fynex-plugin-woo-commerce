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

		self::apply_event( $order, (string) $event['eventType'], $event['payload'] );
		self::remember_event( $order, (string) $event['eventId'] );
		$order->save();
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function webhook_secret(): string {
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
	private static function apply_event( WC_Order $order, string $event_type, array $payload ): void {
		if ( 'PaymentCompleted' === $event_type ) {
			$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
			if ( 'provider_completed' === $status ) {
				if ( ! $order->is_paid() ) {
					$order->payment_complete( (string) $order->get_meta( '_fynex_payment_id', true ) );
					$order->add_order_note( __( 'Fynex confirmed the payment.', 'fynex-woo-commerce' ) );
				}
				return;
			}
			if ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
				$order->update_meta_data( '_fynex_attempt_terminal', $status );
				$order->update_status( 'failed', __( 'Fynex reported that the payment did not complete.', 'fynex-woo-commerce' ) );
			}
			return;
		}

		if ( 'PaymentRefunded' === $event_type && ! empty( $payload['refundId'] ) ) {
			Fynex_WC_Refund_Reconciliation::mark_succeeded( $order, (string) $payload['refundId'] );
		}
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
