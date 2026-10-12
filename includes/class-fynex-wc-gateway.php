<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_Gateway extends WC_Payment_Gateway {
	private string $api_token;
	private string $webhook_secret;

	public function __construct() {
		$this->id                 = 'fynex';
		$this->method_title       = __( 'Fynex', 'fynex-for-woocommerce' );
		$this->method_description = __( 'Redirect customers to Fynex hosted checkout. Card data never reaches this store.', 'fynex-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title          = (string) $this->get_option( 'title', __( 'Fynex', 'fynex-for-woocommerce' ) );
		$this->description    = (string) $this->get_option( 'description', __( 'Pay securely on Fynex hosted checkout.', 'fynex-for-woocommerce' ) );
		$this->api_token      = $this->secret_option( 'fynex_woo_api_token', 'api_token' );
		$this->webhook_secret = $this->secret_option( 'fynex_woo_webhook_secret', 'webhook_secret' );
		$this->enabled        = (string) $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'fynex-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Fynex payments', 'fynex-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'fynex-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Fynex', 'fynex-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Shown to customers at checkout.', 'fynex-for-woocommerce' ),
			),
			'description'    => array(
				'title'       => __( 'Description', 'fynex-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely on Fynex hosted checkout.', 'fynex-for-woocommerce' ),
				'description' => __( 'Card details are entered on Fynex, not on this store.', 'fynex-for-woocommerce' ),
			),
			'api_token'      => array(
				'title'       => __( 'Fynex API token', 'fynex-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Seller token from Fynex. This plugin always uses api.fynex.ai; there is no environment setting.', 'fynex-for-woocommerce' ),
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'fynex-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Saved automatically when this plugin registers its callback. If the callback already exists, paste its signing secret here.', 'fynex-for-woocommerce' ),
			),
		);
	}

	public function process_admin_options(): bool {
		$previous_token = $this->api_token;
		$result         = parent::process_admin_options();
		$this->init_settings();
		$submitted_token  = trim( (string) $this->get_option( 'api_token' ) );
		$submitted_secret = trim( (string) $this->get_option( 'webhook_secret' ) );
		if ( '' !== $submitted_token ) {
			update_option( 'fynex_woo_api_token', $submitted_token, false );
			if ( ! hash_equals( $previous_token, $submitted_token ) ) {
				delete_option( 'fynex_woo_webhook_secret' );
			}
		}
		if ( '' !== $submitted_secret ) {
			update_option( 'fynex_woo_webhook_secret', $submitted_secret, false );
		}
		$this->api_token      = $this->secret_option( 'fynex_woo_api_token', 'api_token' );
		$this->webhook_secret = $this->secret_option( 'fynex_woo_webhook_secret', 'webhook_secret' );
		unset( $this->settings['api_token'], $this->settings['webhook_secret'] );
		update_option( $this->get_option_key(), $this->settings, false );

		if ( $result && '' !== trim( $this->api_token ) ) {
			$this->ensure_webhook_registration();
		}
		return $result;
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => 'failure' );
		}
		if ( '' === trim( $this->api_token ) || '' === trim( $this->webhook_secret ) ) {
			wc_add_notice( __( 'Fynex is not fully configured. Please contact the store administrator.', 'fynex-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$amount = (float) $order->get_total();
		if ( abs( ( $amount * 100 ) - round( $amount * 100 ) ) > 0.000001 ) {
			wc_add_notice( __( 'Fynex supports amounts with up to two decimal places.', 'fynex-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$attempt = $this->prepare_payment_attempt( $order );
		$payload = array(
			'externalOrderRef' => $attempt['payment_id'],
			'amount'           => $amount,
			'currencyCode'     => $order->get_currency(),
			'countryCode'      => $this->country_code( $order ),
			'autoSettlement'   => true,
			'returnUrls'       => array(
				'success' => $this->get_return_url( $order ),
				// The pay-for-order form, so a customer who cancels can retry; the
				// on-checkout variant is a receipt page with no way to pay.
				'failure' => $order->get_checkout_payment_url(),
			),
			/* translators: %s: order number */
			'description'      => sprintf( __( 'Order #%s', 'fynex-for-woocommerce' ), $order->get_order_number() ),
		);

		$client   = new Fynex_WC_API_Client( $this->api_token );
		$response = $client->create_checkout( $payload, $attempt['idempotency_key'] );
		if ( is_wp_error( $response ) || empty( $response['checkoutUrl'] ) || ! is_string( $response['checkoutUrl'] ) ) {
			$order->add_order_note( __( 'Fynex checkout session could not be created.', 'fynex-for-woocommerce' ) );
			wc_add_notice( __( 'Unable to start Fynex checkout. Please try again.', 'fynex-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_fynex_checkout_url', esc_url_raw( $response['checkoutUrl'] ) );
		if ( isset( $response['expiresAt'] ) && is_string( $response['expiresAt'] ) ) {
			$order->update_meta_data( '_fynex_checkout_expires_at', sanitize_text_field( $response['expiresAt'] ) );
		}
		// The cart is left intact: WooCommerce empties it on the order-received page, so a
		// customer who abandons the hosted page comes back to their basket.
		$order->update_status( 'pending', __( 'Awaiting Fynex payment.', 'fynex-for-woocommerce' ) );
		$order->save();

		Fynex_WC_Payment_Check::schedule( (int) $order->get_id(), $attempt['payment_id'] );

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $response['checkoutUrl'] ),
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || '' === trim( $this->api_token ) ) {
			return new WP_Error( 'fynex_refund_unavailable', __( 'Fynex refund is unavailable.', 'fynex-for-woocommerce' ) );
		}
		$payment_id = (string) $order->get_meta( '_fynex_paid_payment_id', true );
		if ( '' === $payment_id ) {
			$payment_id = (string) $order->get_transaction_id();
		}
		if ( '' === $payment_id ) {
			return new WP_Error( 'fynex_missing_payment', __( 'No Fynex payment is associated with this order.', 'fynex-for-woocommerce' ) );
		}

		$amount = null === $amount ? (float) $order->get_total() : (float) $amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'fynex_invalid_refund', __( 'Refund amount must be greater than zero.', 'fynex-for-woocommerce' ) );
		}
		foreach ( $this->refunds( $order ) as $refund ) {
			if ( ! empty( $refund['local_refund_id'] ) || ! in_array( $refund['status'] ?? '', array( 'pending', 'succeeded' ), true ) ) {
				continue;
			}
			if ( (float) ( $refund['amount'] ?? 0 ) === $amount && (string) ( $refund['reason'] ?? '' ) === (string) $reason ) {
				return new WP_Error( 'fynex_refund_pending', __( 'This Fynex refund is already pending confirmation.', 'fynex-for-woocommerce' ) );
			}
			return new WP_Error( 'fynex_refund_pending', __( 'Another Fynex refund is pending confirmation for this order.', 'fynex-for-woocommerce' ) );
		}
		$submission = $this->refund_submission( $order, $amount, (string) $reason );
		$response   = ( new Fynex_WC_API_Client( $this->api_token ) )->refund( $payment_id, $amount, $submission['idempotency_key'] );
		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			$status     = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 0 ) : 0;
			if ( $status >= 400 && $status < 500 && 408 !== $status && 429 !== $status ) {
				$order->delete_meta_data( '_fynex_refund_submission' );
				$order->save();
			}
			return $response;
		}
		if ( empty( $response['id'] ) || ! is_string( $response['id'] ) ) {
			return new WP_Error( 'fynex_invalid_refund_response', __( 'Fynex returned an invalid refund response.', 'fynex-for-woocommerce' ) );
		}

		if ( in_array( sanitize_key( (string) ( $response['status'] ?? '' ) ), array( 'failed', 'cancelled' ), true ) ) {
			$order->delete_meta_data( '_fynex_refund_submission' );
			$order->save();
			return new WP_Error( 'fynex_refund_rejected', __( 'Fynex rejected this refund.', 'fynex-for-woocommerce' ) );
		}

		$order->delete_meta_data( '_fynex_refund_submission' );
		$refunds   = $this->refunds( $order );
		$refunds[] = array(
			'id'           => sanitize_text_field( $response['id'] ),
			'amount'       => $amount,
			'status'       => 'pending',
			'createdAt'    => time(),
			'reason'       => sanitize_text_field( (string) $reason ),
			'payment_id'   => $payment_id,
			'amount_minor' => (int) round( $amount * 100 ),
			'currency'     => strtoupper( $order->get_currency() ),
		);
		$order->update_meta_data( '_fynex_refunds', $refunds );
		/* translators: 1: Fynex refund ID, 2: refund amount */
		$order->add_order_note( sprintf( __( 'Fynex refund %1$s submitted for %2$s.', 'fynex-for-woocommerce' ), $response['id'], wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) );
		$order->save();

		Fynex_WC_Refund_Reconciliation::schedule( (int) $order->get_id(), (string) $response['id'] );
		return new WP_Error( 'fynex_refund_pending', __( 'Fynex accepted the refund. WooCommerce will record it after it is reconciled with Fynex.', 'fynex-for-woocommerce' ) );
	}

	private function refund_submission( WC_Order $order, float $amount, string $reason ): array {
		$stored = $order->get_meta( '_fynex_refund_submission', true );
		if ( is_array( $stored ) && isset( $stored['idempotency_key'], $stored['amount'], $stored['reason'] ) && (float) $stored['amount'] === $amount && (string) $stored['reason'] === $reason ) {
			return $stored;
		}
		$submission = array(
			'idempotency_key' => wp_generate_uuid4(),
			'amount'          => $amount,
			'reason'          => $reason,
		);
		$order->update_meta_data( '_fynex_refund_submission', $submission );
		$order->save();
		return $submission;
	}

	private function refunds( WC_Order $order ): array {
		$refunds = $order->get_meta( '_fynex_refunds', true );
		return is_array( $refunds ) ? $refunds : array();
	}

	private function prepare_payment_attempt( WC_Order $order ): array {
		$terminal   = (string) $order->get_meta( '_fynex_attempt_terminal', true );
		$payment    = (string) $order->get_meta( '_fynex_current_payment_id', true );
		$key        = (string) $order->get_meta( '_fynex_current_idempotency_key', true );
		$expires_at = strtotime( (string) $order->get_meta( '_fynex_checkout_expires_at', true ) );
		if ( '' !== $payment && '' !== $key && '' === $terminal && ( false === $expires_at || $expires_at > time() ) ) {
			return array(
				'payment_id'      => $payment,
				'idempotency_key' => $key,
			);
		}

		$attempt = max( 0, (int) $order->get_meta( '_fynex_attempt', true ) ) + 1;
		$payment = sprintf( 'wc-%s-%d-%d', self::site_reference(), $order->get_id(), $attempt );
		$key     = wp_generate_uuid4();
		$order->update_meta_data( '_fynex_attempt', $attempt );
		$order->update_meta_data( '_fynex_current_payment_id', $payment );
		$order->update_meta_data( '_fynex_current_idempotency_key', $key );
		$order->add_meta_data( '_fynex_payment_id', $payment, false );
		$attempts   = $this->payment_attempts( $order );
		$attempts[] = array(
			'payment_id'   => $payment,
			'amount_minor' => $this->amount_minor( $order ),
			'currency'     => strtoupper( $order->get_currency() ),
			'created_at'   => time(),
		);
		$order->update_meta_data( '_fynex_payment_attempts', $attempts );
		$order->delete_meta_data( '_fynex_attempt_terminal' );
		$order->delete_meta_data( '_fynex_checkout_expires_at' );
		$order->set_transaction_id( $payment );
		$order->save();
		return array(
			'payment_id'      => $payment,
			'idempotency_key' => $key,
		);
	}

	/**
	 * Fynex resolves refunds and status reads to the newest payment with a given
	 * externalOrderRef, and does not enforce uniqueness. A staging copy of the
	 * store using the same token would reuse order IDs, so the reference carries
	 * a short hash of the site URL to keep two sites from sharing one.
	 */
	private static function site_reference(): string {
		return substr( md5( untrailingslashit( home_url() ) ), 0, 8 );
	}

	private function secret_option( string $option, string $legacy_key ): string {
		$secret = get_option( $option, null );
		if ( is_string( $secret ) ) {
			return $secret;
		}
		$legacy = (string) $this->get_option( $legacy_key );
		if ( '' !== $legacy ) {
			update_option( $option, $legacy, false );
		}
		return $legacy;
	}

	public function is_available(): bool {
		return parent::is_available() && '' !== trim( $this->api_token ) && '' !== trim( $this->webhook_secret );
	}

	/** @return array<int,array<string,mixed>> */
	private function payment_attempts( WC_Order $order ): array {
		$attempts = $order->get_meta( '_fynex_payment_attempts', true );
		return is_array( $attempts ) ? $attempts : array();
	}

	private function amount_minor( WC_Order $order ): int {
		return (int) round( (float) $order->get_total() * 100 );
	}

	private function country_code( WC_Order $order ): string {
		$country = strtoupper( (string) $order->get_billing_country() );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = strtoupper( (string) WC()->countries->get_base_country() );
		}
		return $country;
	}

	private function ensure_webhook_registration(): void {
		$endpoint = rest_url( 'fynex/v1/webhook' );
		if ( 0 !== strpos( $endpoint, 'https://' ) ) {
			$this->admin_error( __( 'Fynex sends payment updates only to a public HTTPS address. Serve this store over HTTPS, then save the settings again.', 'fynex-for-woocommerce' ) );
			return;
		}

		$client = new Fynex_WC_API_Client( $this->api_token );
		$list   = $client->list_webhooks();
		if ( is_wp_error( $list ) ) {
			$this->admin_error( __( 'Fynex webhook could not be checked. Verify the API token and try saving again.', 'fynex-for-woocommerce' ) );
			return;
		}
		$rotated = false;
		foreach ( (array) ( $list['webhooks'] ?? array() ) as $webhook ) {
			if ( ! is_array( $webhook ) || ( $webhook['webhookUrl'] ?? '' ) !== $endpoint || 'active' !== ( $webhook['status'] ?? '' ) ) {
				continue;
			}
			if ( '' !== trim( $this->webhook_secret ) ) {
				return;
			}
			// Fynex returns a signing secret only when the callback is created, so a
			// callback this store has no secret for is replaced with a new one.
			$deleted = $client->delete_webhook( $webhook['id'] ?? '' );
			if ( is_wp_error( $deleted ) ) {
				$this->admin_error( __( 'The Fynex callback for this store exists but its signing secret is not stored here, and it could not be replaced. Paste the secret from the original registration, or delete the callback in Fynex and save again.', 'fynex-for-woocommerce' ) );
				return;
			}
			$rotated = true;
		}

		$created = $client->create_webhook( $endpoint );
		if ( is_wp_error( $created ) || empty( $created['secretKey'] ) || ! is_string( $created['secretKey'] ) ) {
			$this->admin_error( __( 'Fynex webhook registration failed. The gateway remains disabled until a signing secret is configured.', 'fynex-for-woocommerce' ) );
			return;
		}
		update_option( 'fynex_woo_webhook_secret', $created['secretKey'], false );
		$this->webhook_secret = $created['secretKey'];
		$this->admin_success(
			$rotated
				? __( 'Fynex webhook replaced and its new signing secret saved.', 'fynex-for-woocommerce' )
				: __( 'Fynex webhook registered and signing secret saved.', 'fynex-for-woocommerce' )
		);
	}

	private function admin_error( string $message ): void {
		WC_Admin_Settings::add_error( $message );
	}

	private function admin_success( string $message ): void {
		WC_Admin_Settings::add_message( $message );
	}
}
