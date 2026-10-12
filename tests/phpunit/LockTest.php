<?php

final class LockTest extends WP_UnitTestCase {
	private function lock_row( string $name ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Fynex_WC_Lock::PREFIX . $name ) );
		return is_string( $value ) ? $value : null;
	}

	public function test_only_one_holder_at_a_time(): void {
		$first = Fynex_WC_Lock::acquire( 'order_1', 60 );

		$this->assertNotNull( $first );
		$this->assertNull( Fynex_WC_Lock::acquire( 'order_1', 60 ) );

		Fynex_WC_Lock::release( 'order_1', $first );
		$this->assertNotNull( Fynex_WC_Lock::acquire( 'order_1', 60 ) );
	}

	public function test_a_stale_options_cache_cannot_hand_out_a_held_lock(): void {
		global $wpdb;
		$held = 'held-elsewhere:' . ( time() + 60 );
		$wpdb->insert( $wpdb->options, array( 'option_name' => Fynex_WC_Lock::PREFIX . 'order_2', 'option_value' => $held, 'autoload' => 'off' ) );
		// Another request cached "no such option" before the row was written.
		wp_cache_set( 'notoptions', array( Fynex_WC_Lock::PREFIX . 'order_2' => true ), 'options' );

		$this->assertNull( Fynex_WC_Lock::acquire( 'order_2', 60 ) );
		$this->assertSame( $held, $this->lock_row( 'order_2' ) );
	}

	public function test_an_expired_lease_is_taken_over(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->options, array( 'option_name' => Fynex_WC_Lock::PREFIX . 'order_3', 'option_value' => 'crashed:' . ( time() - 1 ), 'autoload' => 'off' ) );

		$lease = Fynex_WC_Lock::acquire( 'order_3', 60 );

		$this->assertNotNull( $lease );
		$this->assertSame( $lease, $this->lock_row( 'order_3' ) );
	}

	public function test_release_by_someone_else_keeps_the_lock(): void {
		$lease = Fynex_WC_Lock::acquire( 'order_4', 60 );

		Fynex_WC_Lock::release( 'order_4', 'not-the-holder:' . ( time() + 60 ) );

		$this->assertSame( $lease, $this->lock_row( 'order_4' ) );
	}
}
