<?php

final class TranslationTest extends WP_UnitTestCase {
	private string $mo_file;

	public function set_up(): void {
		parent::set_up();
		$this->mo_file = FYNEX_WC_DIR . 'languages/fynex-for-woocommerce-de_DE.mo';
		$mo            = new MO();
		$mo->add_entry( new Translation_Entry( array( 'singular' => 'Order #%s', 'translations' => array( 'Bestellung #%s' ) ) ) );
		$mo->export_to_file( $this->mo_file );
	}

	public function tear_down(): void {
		remove_all_filters( 'determine_locale' );
		unload_textdomain( 'fynex-for-woocommerce' );
		wp_delete_file( $this->mo_file );
		parent::tear_down();
	}

	public function test_translations_in_the_languages_folder_are_loaded(): void {
		// switch_to_locale() refuses a locale WordPress has no core files for.
		add_filter(
			'determine_locale',
			static function (): string {
				return 'de_DE';
			}
		);
		unload_textdomain( 'fynex-for-woocommerce' );

		Fynex_WC_Plugin::load_textdomain();

		$this->assertSame( 'Bestellung #%s', __( 'Order #%s', 'fynex-for-woocommerce' ) );
	}
}
