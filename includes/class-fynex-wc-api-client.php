<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_API_Client {
	private const API_BASE = 'https://api.fynex.ai/payments-api/v1';

	/** Event types the plugin acts on; anything else would only be acknowledged. */
	public const WEBHOOK_EVENT_TYPES = array( 'PaymentCompleted', 'PaymentRefunded' );

	private string $token;
	private int $timeout;

	public function __construct( string $token, int $timeout = 20 ) {
		$this->token   = trim( $token );
		$this->timeout = $timeout;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_checkout( array $payload, string $idempotency_key ) {
		return $this->request( 'POST', '/checkout', $payload, $idempotency_key );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_webhook( string $url ) {
		return $this->request(
			'POST',
			'/webhooks',
			array(
				'webhookUrl' => $url,
				'eventTypes' => self::WEBHOOK_EVENT_TYPES,
			)
		);
	}

	/**
	 * @param int|string $webhook_id
	 * @return array<string, mixed>|WP_Error
	 */
	public function delete_webhook( $webhook_id ) {
		return $this->request( 'DELETE', '/webhooks/' . rawurlencode( (string) $webhook_id ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_webhooks() {
		return $this->request( 'GET', '/webhooks' );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_payment( string $payment_id ) {
		return $this->request( 'GET', '/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function refund( string $payment_id, float $amount, string $idempotency_key ) {
		return $this->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/refund', array( 'amount' => $amount ), $idempotency_key );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_refund( string $refund_id ) {
		return $this->request( 'GET', '/refunds/' . rawurlencode( $refund_id ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null, string $idempotency_key = '' ) {
		if ( '' === $this->token ) {
			return new WP_Error( 'fynex_missing_token', __( 'Fynex API token is not configured.', 'fynex-for-woocommerce' ) );
		}

		$headers = array(
			'Accept'           => 'application/json',
			'Authorization'    => 'Bearer ' . $this->token,
			'X-Source-Channel' => 'api',
		);
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request(
			self::api_base() . $path,
			array(
				'method'      => $method,
				'timeout'     => $this->timeout,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => null === $body ? null : wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fynex_network_error', __( 'Fynex could not be reached. Please try again.', 'fynex-for-woocommerce' ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $decoded['error'] ) && is_string( $decoded['error'] )
				? $decoded['error']
				: __( 'Fynex rejected the request.', 'fynex-for-woocommerce' );
			return new WP_Error( 'fynex_api_error', $message, array( 'status' => $status ) );
		}

		return $decoded;
	}

	/**
	 * FYNEX_WC_API_BASE lets Fynex point a test store at a non-production API.
	 * Merchants never set it; the token alone selects demo or live.
	 */
	private static function api_base(): string {
		if ( defined( 'FYNEX_WC_API_BASE' ) && is_string( FYNEX_WC_API_BASE ) && '' !== FYNEX_WC_API_BASE ) {
			return untrailingslashit( FYNEX_WC_API_BASE );
		}
		return self::API_BASE;
	}
}
