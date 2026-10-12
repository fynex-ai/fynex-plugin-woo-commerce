<?php

final class LoggingTest extends Fynex_Test_Case {
	/** @var array<int,array{level:string,message:string,context:array<string,mixed>}> */
	private array $entries = array();
	private WC_Logger_Interface $logger;

	public function set_up(): void {
		parent::set_up();
		$entries      = &$this->entries;
		$this->logger = new class( $entries ) extends WC_Logger {
			/** @var array<int,array<string,mixed>> */
			private array $sink;

			/** @param array<int,array<string,mixed>> $sink */
			public function __construct( array &$sink ) {
				parent::__construct( array() );
				$this->sink = &$sink;
			}

			/**
			 * @param string              $level
			 * @param string              $message
			 * @param array<string,mixed> $context
			 */
			public function log( $level, $message, $context = array() ) {
				$this->sink[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		};
		add_filter( 'woocommerce_logging_class', array( $this, 'logger' ) );
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_logging_class', array( $this, 'logger' ) );
		parent::tear_down();
	}

	public function logger(): WC_Logger_Interface {
		return $this->logger;
	}

	private function enable_debug( bool $enabled ): void {
		$settings          = get_option( 'woocommerce_fynex_settings' );
		$settings['debug'] = $enabled ? 'yes' : 'no';
		update_option( 'woocommerce_fynex_settings', $settings );
	}

	private function all_logged_text(): string {
		return wp_json_encode( $this->entries );
	}

	public function test_rejected_webhook_is_logged_even_with_debug_off(): void {
		$this->enable_debug( false );

		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( 'wc-x-1-1', 'captured' ), null, 'wrong-secret' );

		$this->assertSame( 'warning', $this->entries[0]['level'] );
		$this->assertSame( 'fynex-for-woocommerce', $this->entries[0]['context']['source'] );
		$this->assertStringContainsString( 'signature', $this->entries[0]['message'] );
	}

	public function test_successful_payment_is_logged_only_with_debug_on(): void {
		$this->enable_debug( false );
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ) );
		$this->assertSame( array(), $this->entries );

		$this->enable_debug( true );
		$order      = $this->create_order();
		$payment_id = $this->start_checkout( $order );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( $payment_id, 'captured' ) );

		$messages = wp_list_pluck( $this->entries, 'message' );
		$this->assertContains( 'Fynex API request succeeded.', $messages );
		$this->assertContains( 'Fynex webhook applied.', $messages );
	}

	public function test_logs_never_contain_the_token_or_the_secret(): void {
		$this->enable_debug( true );
		$this->respond( 'POST', '/checkout', 422, array( 'error' => 'currency not enabled' ) );
		$this->gateway()->process_payment( $this->create_order()->get_id() );
		$this->deliver_webhook( 'PaymentCompleted', $this->payment_completed( 'wc-x-1-1', 'captured' ), null, 'wrong-secret' );

		$this->assertNotEmpty( $this->entries );
		$this->assertStringNotContainsString( self::TOKEN, $this->all_logged_text() );
		$this->assertStringNotContainsString( self::SECRET, $this->all_logged_text() );
	}
}
