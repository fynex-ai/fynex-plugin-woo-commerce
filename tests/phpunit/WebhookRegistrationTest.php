<?php

final class WebhookRegistrationTest extends Fynex_Test_Case {
	private const ENDPOINT = 'https://shop.example.org/wp-json/fynex/v1/webhook';

	public function set_up(): void {
		parent::set_up();
		delete_option( 'fynex_woo_webhook_secret' );
		$_POST = array();
	}

	public function tear_down(): void {
		$_POST = array();
		remove_all_filters( 'rest_url' );
		parent::tear_down();
	}

	private function save_settings(): void {
		$gateway = $this->gateway();
		$_POST   = array(
			'woocommerce_fynex_enabled'   => '1',
			'woocommerce_fynex_title'     => 'Fynex',
			'woocommerce_fynex_api_token' => self::TOKEN,
		);
		$gateway->process_admin_options();
	}

	private function serve_over_https(): void {
		add_filter(
			'rest_url',
			static function (): string {
				return self::ENDPOINT;
			}
		);
	}

	public function test_registers_the_callback_for_the_events_the_plugin_handles(): void {
		$this->serve_over_https();
		$this->respond( 'GET', '/webhooks', 200, array( 'webhooks' => array() ) );
		$this->respond( 'POST', '/webhooks', 201, array( 'id' => 7, 'webhookUrl' => self::ENDPOINT, 'status' => 'active', 'secretKey' => 'new-secret' ) );

		$this->save_settings();

		$create = $this->requests_to( 'POST', '/webhooks' )[0];
		$this->assertSame( self::ENDPOINT, $create['body']['webhookUrl'] );
		$this->assertSame( array( 'PaymentCompleted', 'PaymentRefunded' ), $create['body']['eventTypes'] );
		$this->assertSame( 'new-secret', get_option( 'fynex_woo_webhook_secret' ) );
	}

	public function test_replaces_an_active_callback_whose_secret_this_store_lost(): void {
		$this->serve_over_https();
		$this->respond( 'GET', '/webhooks', 200, array( 'webhooks' => array( array( 'id' => 7, 'webhookUrl' => self::ENDPOINT, 'status' => 'active' ) ) ) );
		$this->respond( 'DELETE', '/webhooks/7', 200, array() );
		$this->respond( 'POST', '/webhooks', 201, array( 'id' => 8, 'webhookUrl' => self::ENDPOINT, 'status' => 'active', 'secretKey' => 'rotated-secret' ) );

		$this->save_settings();

		$this->assertCount( 1, $this->requests_to( 'DELETE', '/webhooks/7' ) );
		$this->assertSame( 'rotated-secret', get_option( 'fynex_woo_webhook_secret' ) );
	}

	public function test_keeps_an_active_callback_it_has_the_secret_for(): void {
		$this->serve_over_https();
		update_option( 'fynex_woo_api_token', self::TOKEN, false );
		update_option( 'fynex_woo_webhook_secret', 'kept-secret', false );
		$this->respond( 'GET', '/webhooks', 200, array( 'webhooks' => array( array( 'id' => 7, 'webhookUrl' => self::ENDPOINT, 'status' => 'active' ) ) ) );

		$this->save_settings();

		$this->assertSame( array(), $this->requests_to( 'POST', '/webhooks' ) );
		$this->assertSame( array(), $this->requests_to( 'DELETE', '/webhooks' ) );
		$this->assertSame( 'kept-secret', get_option( 'fynex_woo_webhook_secret' ) );
	}

	public function test_refuses_to_register_a_plain_http_callback(): void {
		$this->save_settings();

		$this->assertSame( array(), $this->requests_to( 'POST', '/webhooks' ) );
		$this->assertFalse( get_option( 'fynex_woo_webhook_secret' ) );
	}
}
