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
		Fynex_WC_Payment_Check::register();
		Fynex_WC_Refund_Reconciliation::register();
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'woocommerce_create_refund', array( 'Fynex_WC_Gateway', 'remember_created_refund' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		// woocommerce_blocks_loaded has already fired by plugins_loaded:20, so hook the
		// registry itself; it initialises on init:5.
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_blocks' ) );
	}

	/**
	 * The plugin is not distributed through WordPress.org alone, so translations
	 * shipped in languages/ have to be loaded explicitly.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'fynex-for-woocommerce', false, dirname( plugin_basename( FYNEX_WC_FILE ) ) . '/languages' );
	}

	/**
	 * Suggests text for the store's privacy policy (Settings → Privacy).
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'We use Fynex to take card payments. When you pay with Fynex, we send Fynex the order amount, currency, country, an order reference and description, and the addresses on this store you return to. We do not send your name, email address, phone number or postal address. You enter your card details on a page hosted by Fynex; they are not sent to or stored on this website.', 'fynex-for-woocommerce' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: link to the Fynex privacy policy */
				esc_html__( 'Fynex processes your payment under its own privacy policy: %s', 'fynex-for-woocommerce' ),
				'<a href="https://fynex.ai/legal/privacy-policy/">https://fynex.ai/legal/privacy-policy/</a>'
			) . '</p>';
		wp_add_privacy_policy_content( __( 'Fynex for WooCommerce', 'fynex-for-woocommerce' ), wp_kses_post( $content ) );
	}

	public static function register_gateway( array $gateways ): array {
		$gateways[] = 'Fynex_WC_Gateway';
		return $gateways;
	}

	/**
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry
	 */
	public static function register_blocks( $payment_method_registry ): void {
		if ( ! class_exists( '\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-blocks-support.php';
		$payment_method_registry->register( new Fynex_WC_Blocks_Support() );
	}

	public static function woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Fynex for WooCommerce requires WooCommerce to be installed and active.', 'fynex-for-woocommerce' ) . '</p></div>';
	}
}
