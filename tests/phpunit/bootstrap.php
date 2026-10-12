<?php
/**
 * Boots the WordPress test suite with WooCommerce and this plugin loaded.
 *
 * Requires WP_CORE_DIR (WordPress with WooCommerce under wp-content/plugins),
 * see bin/install-test-env. FYNEX_TEST_HPOS=1 runs the suite against HPOS with
 * compatibility-mode sync off; anything else uses legacy post storage.
 */

$plugin_dir = dirname( __DIR__, 2 );

require $plugin_dir . '/vendor/autoload.php';
require $plugin_dir . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( ! getenv( 'WP_CORE_DIR' ) ) {
	fwrite( STDERR, "WP_CORE_DIR is not set; run bin/install-test-env first.\n" );
	exit( 1 );
}
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$tests_dir = (string) getenv( 'WP_PHPUNIT__DIR' );
require $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require WP_CONTENT_DIR . '/plugins/woocommerce/woocommerce.php';
		// Load the plugin through the link bin/install-test-env creates in the
		// plugins directory: WooCommerce only records feature compatibility for
		// files WordPress recognises as plugins.
		$main_file = WP_CONTENT_DIR . '/plugins/fynex-for-woocommerce/fynex-for-woocommerce.php';
		wp_register_plugin_realpath( $main_file );
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php', 'fynex-for-woocommerce/fynex-for-woocommerce.php' ) );
		require $main_file;
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();
		$GLOBALS['wp_roles'] = null;
		wp_roles();

		$hpos = '1' === getenv( 'FYNEX_TEST_HPOS' );
		$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
		$sync->create_database_tables();
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		update_option( 'woocommerce_currency', 'GBP' );
		update_option( 'woocommerce_default_country', 'GB' );
	}
);

require $tests_dir . '/includes/bootstrap.php';

fwrite( STDOUT, sprintf( "Order storage: %s\n", \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS (sync off)' : 'posts' ) );

require __DIR__ . '/class-fynex-test-case.php';
