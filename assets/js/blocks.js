( function () {
	const settings = window.wc.wcSettings.getSetting( 'fynex_data', {} );
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;
	const { __ } = window.wp.i18n;

	// The title and description come translated from PHP; these only cover missing settings.
	const title = decodeEntities( settings.title || __( 'Fynex', 'fynex-for-woocommerce' ) );
	const description = decodeEntities( settings.description || __( 'Pay securely on Fynex hosted checkout.', 'fynex-for-woocommerce' ) );

	const Content = () => createElement( 'p', null, description );

	registerPaymentMethod( {
		name: 'fynex',
		label: createElement( 'span', null, title ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: () => true,
		ariaLabel: title,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
