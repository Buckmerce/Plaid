/**
 * Checkout Blocks registration for PayBridge for Plaid.
 *
 * Presentation only: the order is created by WooCommerce and payment
 * initiation happens server-side in the gateway's process_payment(), exactly
 * as in Classic Checkout. The bank connection itself runs on the PayBridge
 * payment page after the redirect.
 */

interface PayBridgeBlockData {
	title?: string;
	description?: string;
	icon?: string;
	supports?: string[];
	available?: boolean;
}

interface WcBlocksRegistry {
	registerPaymentMethod: ( config: Record< string, unknown > ) => void;
}

interface WcSettings {
	getSetting: < T >( name: string, fallback: T ) => T;
}

interface WpElement {
	createElement: ( type: unknown, props: Record< string, unknown > | null, ...children: unknown[] ) => unknown;
}

interface WpHtmlEntities {
	decodeEntities: ( value: string ) => string;
}

interface WpI18n {
	__: ( text: string, domain?: string ) => string;
}

declare global {
	interface Window {
		wc?: { wcBlocksRegistry?: WcBlocksRegistry; wcSettings?: WcSettings };
		wp?: { element?: WpElement; htmlEntities?: WpHtmlEntities; i18n?: WpI18n };
	}
}

( function ( registry, settings, element, entities, i18n ): void {
	if ( ! registry || ! settings || ! element ) {
		return;
	}
	const decode = ( value: string ): string => ( entities ? entities.decodeEntities( value ) : value );
	const translate = ( text: string ): string => ( i18n ? i18n.__( text, 'paybridge-for-plaid' ) : text );
	const data = settings.getSetting< PayBridgeBlockData >( 'paybridge_plaid_data', {} );
	const title = decode( data.title || translate( 'Pay by Bank' ) );
	const description = decode( data.description || translate( 'Securely pay directly from your bank account.' ) );

	const label = element.createElement(
		'span',
		{ className: 'pbfp-block-label' },
		data.icon ? element.createElement( 'img', { src: data.icon, alt: '', width: 24, height: 24, className: 'pbfp-block-label__icon' } ) : null,
		element.createElement( 'span', { className: 'pbfp-block-label__text' }, title )
	);
	const content = element.createElement( 'p', { className: 'pbfp-block-description' }, description );

	registry.registerPaymentMethod( {
		name: 'paybridge_plaid',
		paymentMethodId: 'paybridge_plaid',
		ariaLabel: title,
		label,
		content,
		edit: content,
		canMakePayment: (): boolean => true === data.available,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )(
	window.wc && window.wc.wcBlocksRegistry,
	window.wc && window.wc.wcSettings,
	window.wp && window.wp.element,
	window.wp && window.wp.htmlEntities,
	window.wp && window.wp.i18n
);

export {};
