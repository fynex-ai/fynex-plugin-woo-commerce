( function () {
	const settings = window.wc.wcSettings.getSetting( 'fynex_data', {} );
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const label = createElement( 'span', null, decodeEntities( settings.title || 'Fynex' ) );
	const Content = () => createElement( 'p', null, decodeEntities( settings.description || 'Pay securely on Fynex hosted checkout.' ) );

	registerPaymentMethod( {
		name: 'fynex',
		label,
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: () => true,
		ariaLabel: decodeEntities( settings.title || 'Fynex' ),
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
