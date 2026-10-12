<?php

final class TokenModeTest extends Fynex_Test_Case {
	public function test_mode_comes_from_the_key_prefix(): void {
		$this->assertSame( 'demo', Fynex_WC_API_Client::token_mode( 'sk_test_abc' ) );
		$this->assertSame( 'live', Fynex_WC_API_Client::token_mode( ' sk_live_abc ' ) );
		$this->assertSame( 'unknown', Fynex_WC_API_Client::token_mode( 'f3b1c0legacy' ) );
	}

	public function test_settings_screen_states_the_saved_mode(): void {
		update_option( 'fynex_woo_api_token', 'sk_live_abc', false );
		$this->assertStringContainsString( 'Saved key: live', $this->gateway()->form_fields['api_token']['description'] );

		update_option( 'fynex_woo_api_token', 'sk_test_abc', false );
		$this->assertStringContainsString( 'Saved key: demo', $this->gateway()->form_fields['api_token']['description'] );
	}

	public function test_demo_key_marks_the_checkout_description(): void {
		update_option( 'fynex_woo_api_token', 'sk_test_abc', false );
		$this->assertStringStartsWith( 'Test mode', $this->gateway()->description );

		update_option( 'fynex_woo_api_token', 'sk_live_abc', false );
		$this->assertSame( 'Pay on Fynex.', $this->gateway()->description );
	}
}
