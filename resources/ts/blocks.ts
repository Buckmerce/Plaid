/**
 * Checkout Blocks registration for Buckmerce for Plaid.
 *
 * Presentation only: the order is created by WooCommerce and payment
 * initiation happens server-side in the gateway's process_payment(), exactly
 * as in Classic Checkout. The bank connection itself runs on the Buckmerce
 * payment page after the redirect.
 */

interface BuckmerceBlockData {
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

declare global {
	interface Window {
		wc?: { wcBlocksRegistry?: WcBlocksRegistry; wcSettings?: WcSettings };
		wp?: { element?: WpElement; htmlEntities?: WpHtmlEntities };
	}
}

( function ( registry, settings, element, entities ): void {
	if ( ! registry || ! settings || ! element ) {
		return;
	}
	const decode = ( value: string ): string => ( entities ? entities.decodeEntities( value ) : value );
	const data = settings.getSetting< BuckmerceBlockData >( 'buckmerce_plaid_data', {} );
	// The server always sends the title and description, already translated (Settings::title(),
	// Settings::description()); the literals only keep the method labelled if that data is missing.
	const title = decode( data.title || 'Pay by Bank' );
	const description = decode( data.description || 'Securely pay directly from your bank account.' );

	const label = element.createElement(
		'span',
		// Laid out inline: no Buckmerce stylesheet is loaded on the Checkout block or in the editor.
		{ className: 'bmfp-block-label', style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
		data.icon ? element.createElement( 'img', { src: data.icon, alt: '', width: 24, height: 24, className: 'bmfp-block-label__icon' } ) : null,
		element.createElement( 'span', { className: 'bmfp-block-label__text' }, title )
	);
	const content = element.createElement( 'p', { className: 'bmfp-block-description' }, description );

	registry.registerPaymentMethod( {
		name: 'buckmerce_plaid',
		paymentMethodId: 'buckmerce_plaid',
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
	window.wp && window.wp.htmlEntities
);

export {};
