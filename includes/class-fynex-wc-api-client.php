<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_API_Client {
	private const API_BASE = 'https://api.fynex.ai/payments-api/v1';

	private string $token;

	public function __construct( string $token ) {
		$this->token = trim( $token );
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
		return $this->request( 'POST', '/webhooks', array( 'webhookUrl' => $url ) );
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
			return new WP_Error( 'fynex_missing_token', __( 'Fynex API token is not configured.', 'fynex-woo-commerce' ) );
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
			self::API_BASE . $path,
			array(
				'method'      => $method,
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => null === $body ? null : wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fynex_network_error', __( 'Fynex could not be reached. Please try again.', 'fynex-woo-commerce' ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $decoded['error'] ) && is_string( $decoded['error'] )
				? $decoded['error']
				: __( 'Fynex rejected the request.', 'fynex-woo-commerce' );
			return new WP_Error( 'fynex_api_error', $message, array( 'status' => $status ) );
		}

		return $decoded;
	}
}
