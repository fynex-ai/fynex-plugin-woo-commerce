<?php
/**
 * Plugin Name: Fynex for WooCommerce
 * Plugin URI: https://fynex.ai/integrations/woocommerce
 * Description: Accept payments through Fynex hosted checkout without card data touching your WooCommerce store.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Fynex
 * Author URI: https://fynex.ai
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fynex-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 10.9
 * WC tested up to: 11.2
 */

defined( 'ABSPATH' ) || exit;

define( 'FYNEX_WC_VERSION', '0.1.0' );
define( 'FYNEX_WC_FILE', __FILE__ );
define( 'FYNEX_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FYNEX_WC_URL', plugin_dir_url( __FILE__ ) );

require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-logger.php';
require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-webhook-verifier.php';
require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-api-client.php';
require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-lock.php';
require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-payment-outcome.php';
require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-payment-check.php';
require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-webhook.php';
require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-refund-reconciliation.php';
require_once FYNEX_WC_DIR . 'includes/class-fynex-wc-plugin.php';

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FYNEX_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FYNEX_WC_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Fynex_WC_Plugin', 'bootstrap' ), 20 );
add_action( 'init', array( 'Fynex_WC_Plugin', 'load_textdomain' ) );
