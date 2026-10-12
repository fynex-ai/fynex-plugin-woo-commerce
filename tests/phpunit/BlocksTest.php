<?php

use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Features\FeaturesController;

final class BlocksTest extends Fynex_Test_Case {
	public function test_fynex_is_offered_in_the_checkout_block(): void {
		$registry = Package::container()->get( PaymentMethodRegistry::class );

		$this->assertTrue( $registry->is_registered( 'fynex' ) );
		$method = $registry->get_registered( 'fynex' );
		$method->initialize();
		$this->assertTrue( $method->is_active() );
	}

	public function test_blocks_and_classic_checkout_declare_the_same_features(): void {
		$method = Package::container()->get( PaymentMethodRegistry::class )->get_registered( 'fynex' );
		$method->initialize();

		$this->assertSame( array( 'products', 'refunds' ), $method->get_payment_method_data()['supports'] );
		$this->assertSame( array( 'products', 'refunds' ), $this->gateway()->supports );
	}

	public function test_cart_and_checkout_blocks_compatibility_is_declared(): void {
		$features = wc_get_container()->get( FeaturesController::class )->get_compatible_features_for_plugin( plugin_basename( FYNEX_WC_FILE ) );

		$this->assertContains( 'cart_checkout_blocks', $features['compatible'] );
		$this->assertContains( 'custom_order_tables', $features['compatible'] );
	}
}
