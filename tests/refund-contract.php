<?php

define( 'ABSPATH', __DIR__ . '/' );

class WC_Order {
	private array $meta;
	public function __construct( array $meta ) { $this->meta = $meta; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? null; }
}

require_once dirname( __DIR__ ) . '/includes/class-fynex-wc-refund-reconciliation.php';

$order = new WC_Order(
	array(
		'_fynex_refunds' => array(
			array( 'id' => 'refund-1', 'payment_id' => 'wc-10-1', 'amount_minor' => 1234, 'currency' => 'GBP' ),
		),
	)
);

$valid = array( 'externalOrderRef' => 'wc-10-1', 'refundAmountMinor' => 1234, 'currencyCode' => 'GBP' );
$invalid = array( 'externalOrderRef' => 'wc-10-1', 'refundAmountMinor' => 1235, 'currencyCode' => 'GBP' );
if ( ! Fynex_WC_Refund_Reconciliation::matches_webhook( $order, 'refund-1', $valid ) || Fynex_WC_Refund_Reconciliation::matches_webhook( $order, 'refund-1', $invalid ) ) {
	fwrite( STDERR, "Refund contract tests failed.\n" );
	exit( 1 );
}

echo "Refund contract tests passed.\n";
