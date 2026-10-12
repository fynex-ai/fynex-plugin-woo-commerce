<?php

define( 'ABSPATH', __DIR__ . '/' );

function add_action( $hook, $callback, $priority = 10 ): void {}
function plugin_dir_path( $file ): string { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ): string { return 'https://example.test/wp-content/plugins/fynex-for-woocommerce/'; }

require_once dirname( __DIR__ ) . '/fynex-for-woocommerce.php';

if ( ! class_exists( 'Fynex_WC_Plugin' ) || class_exists( 'Fynex_WC_Gateway' ) ) {
	fwrite( STDERR, "Plugin dependency bootstrap test failed.\n" );
	exit( 1 );
}

echo "Plugin dependency bootstrap test passed.\n";
