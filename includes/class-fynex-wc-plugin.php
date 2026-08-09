<?php

defined( 'ABSPATH' ) || exit;

final class Fynex_WC_Plugin {
	public static function bootstrap(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
			return;
		}

		require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-gateway.php';

		Fynex_WC_Webhook::register();
		Fynex_WC_Refund_Reconciliation::register();
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_blocks' ) );
	}

	public static function register_gateway( array $gateways ): array {
		$gateways[] = 'Fynex_WC_Gateway';
		return $gateways;
	}

	public static function register_blocks(): void {
		if ( ! class_exists( '\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $payment_method_registry ): void {
				$payment_method_registry->register( new Fynex_WC_Blocks_Support() );
			}
		);
	}

	public static function woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Fynex for WooCommerce requires WooCommerce to be installed and active.', 'fynex-woo-commerce' ) . '</p></div>';
	}
}
