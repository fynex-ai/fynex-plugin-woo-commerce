<?php
/**
 * Shared fixtures: a configured gateway, a fake Fynex API and signed webhook deliveries.
 */
abstract class Fynex_Test_Case extends WP_UnitTestCase {
	protected const TOKEN  = 'sk_test_plugin_suite';
	protected const SECRET = 'whsec-plugin-suite';

	/**
	 * Responses keyed by "METHOD /path", each a list consumed in order; the last one repeats.
	 *
	 * @var array<string,array<int,array{0:int,1:array<string,mixed>}>>
	 */
	private array $api_responses = array();

	/** @var array<int,array{method:string,path:string,headers:array<string,string>,body:array<string,mixed>|null}> */
	protected array $api_requests = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'fynex_woo_api_token', self::TOKEN, false );
		update_option( 'fynex_woo_webhook_secret', self::SECRET, false );
		update_option(
			'woocommerce_fynex_settings',
			array(
				'enabled'     => 'yes',
				'title'       => 'Fynex',
				'description' => 'Pay on Fynex.',
			)
		);
		$this->api_responses = array();
		$this->api_requests  = array();
		add_filter( 'pre_http_request', array( $this, 'fake_fynex_api' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_fynex_api' ), 10 );
		parent::tear_down();
	}

	/**
	 * @param array<string,mixed> $body
	 */
	protected function respond( string $method, string $path, int $status, array $body ): void {
		$this->api_responses[ $method . ' ' . $path ][] = array( $status, $body );
	}

	/**
	 * @param false|array<string,mixed>|WP_Error $preempt
	 * @param array<string,mixed>                $args
	 * @return array<string,mixed>|WP_Error
	 */
	public function fake_fynex_api( $preempt, array $args, string $url ) {
		$base = 'https://api.fynex.ai/payments-api/v1';
		if ( 0 !== strpos( $url, $base ) ) {
			return new WP_Error( 'unexpected_http', 'Unexpected HTTP call to ' . $url );
		}
		$path   = substr( $url, strlen( $base ) );
		$method = (string) $args['method'];
		$body   = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : null;

		$this->api_requests[] = array(
			'method'  => $method,
			'path'    => $path,
			'headers' => $args['headers'],
			'body'    => $body,
		);

		$key = $method . ' ' . $path;
		if ( empty( $this->api_responses[ $key ] ) ) {
			return new WP_Error( 'unexpected_http', 'No fake response for ' . $key );
		}
		$response = count( $this->api_responses[ $key ] ) > 1 ? array_shift( $this->api_responses[ $key ] ) : $this->api_responses[ $key ][0];
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $response[1] ),
			'response' => array(
				'code'    => $response[0],
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * @return array<int,array{method:string,path:string,headers:array<string,string>,body:array<string,mixed>|null}>
	 */
	protected function requests_to( string $method, string $path_prefix ): array {
		return array_values(
			array_filter(
				$this->api_requests,
				static function ( array $request ) use ( $method, $path_prefix ): bool {
					return $method === $request['method'] && 0 === strpos( $request['path'], $path_prefix );
				}
			)
		);
	}

	protected function gateway(): Fynex_WC_Gateway {
		return new Fynex_WC_Gateway();
	}

	protected function create_product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->set_regular_price( '12.50' );
		$product->save();
		return $product;
	}

	protected function create_order( string $total = '49.99' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( 'fynex' );
		$order->set_currency( 'GBP' );
		$order->set_billing_country( 'GB' );
		$order->set_total( $total );
		$order->save();
		return $order;
	}

	/**
	 * Starts a hosted checkout for the order through the real gateway and returns the payment reference.
	 */
	protected function start_checkout( WC_Order $order ): string {
		$this->respond(
			'POST',
			'/checkout',
			201,
			array(
				'sessionId'   => wp_generate_uuid4(),
				'checkoutUrl' => 'https://checkout.fynex.ai/s/' . $order->get_id(),
				'expiresAt'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 900 ),
			)
		);
		$result = $this->gateway()->process_payment( $order->get_id() );
		$this->assertSame( 'success', $result['result'] );
		return (string) wc_get_order( $order->get_id() )->get_meta( '_fynex_current_payment_id', true );
	}

	/**
	 * Marks the current attempt expired so the next checkout opens a new attempt.
	 */
	protected function expire_current_attempt( WC_Order $order ): void {
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_fynex_checkout_expires_at', gmdate( 'Y-m-d\TH:i:s\Z', time() - 1 ) );
		$order->save();
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	protected function deliver_webhook( string $event_type, array $payload, ?int $event_id = null, ?string $secret = null ): WP_REST_Response {
		$body      = wp_json_encode(
			array(
				'eventId'           => $event_id ?? wp_rand( 1, 2147483647 ),
				'eventType'         => $event_type,
				'sellerAccountUuid' => '6f2a1c1e-6a1e-4f10-9f2b-9c1d0b3a7e55',
				'occurredAt'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'payload'           => $payload,
			)
		);
		$timestamp = (string) time();
		$request   = new WP_REST_Request( 'POST', '/fynex/v1/webhook' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-fynex-timestamp', $timestamp );
		$request->set_header( 'x-fynex-signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ?? self::SECRET ) );
		return rest_do_request( $request );
	}

	/**
	 * A PaymentCompleted payload as Fynex sends it today, before its lifecycle `status` switch.
	 *
	 * @return array<string,mixed>
	 */
	protected function payment_completed( string $payment_id, string $lifecycle, int $amount_minor = 4999, string $currency = 'GBP' ): array {
		$legacy = array(
			'captured'  => 'provider_completed',
			'failed'    => 'failed',
			'cancelled' => 'cancelled',
		);
		return array(
			'genericPaymentId'  => 88213,
			'paymentId'         => $payment_id,
			'providerPaymentId' => wp_generate_uuid4(),
			'providerCode'      => 'paysafe',
			'amountMinor'       => $amount_minor,
			'currencyCode'      => $currency,
			'occurredAt'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'status'            => $legacy[ $lifecycle ],
			'lifecycleStatus'   => $lifecycle,
			'legacyStatus'      => $legacy[ $lifecycle ],
		);
	}
}
