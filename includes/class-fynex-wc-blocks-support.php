<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Fynex_WC_Blocks_Support extends AbstractPaymentMethodType {
	protected $name = 'fynex';

	public function initialize(): void {
		$settings       = get_option( 'woocommerce_fynex_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	public function is_active(): bool {
		return 'yes' === ( $this->settings['enabled'] ?? 'no' )
			&& '' !== trim( (string) get_option( 'fynex_woo_api_token', $this->settings['api_token'] ?? '' ) )
			&& '' !== trim( (string) get_option( 'fynex_woo_webhook_secret', $this->settings['webhook_secret'] ?? '' ) );
	}

	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'fynex-for-woocommerce-blocks',
			FYNEX_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			FYNEX_WC_VERSION,
			true
		);
		wp_set_script_translations( 'fynex-for-woocommerce-blocks', 'fynex-for-woocommerce', FYNEX_WC_DIR . 'languages' );
		return array( 'fynex-for-woocommerce-blocks' );
	}

	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->settings['title'] ?? __( 'Fynex', 'fynex-for-woocommerce' ),
			'description' => Fynex_WC_Gateway::checkout_description( $this->settings['description'] ?? __( 'Pay securely on Fynex hosted checkout.', 'fynex-for-woocommerce' ), (string) get_option( 'fynex_woo_api_token', '' ) ),
			'supports'    => $this->supported_features(),
		);
	}

	/**
	 * Mirrors the classic gateway so both checkouts offer the same features.
	 *
	 * @return array<int,string>
	 */
	private function supported_features(): array {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['fynex'] ) ) {
			return array( 'products' );
		}
		$gateway = $gateways['fynex'];
		return array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) );
	}
}
