// Behavioural tests for the built payment page script (assets/build/payment-page.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync( new URL( '../../assets/build/payment-page.js', import.meta.url ), 'utf8' );
const tick = async ( times = 5 ) => {
	for ( let i = 0; i < times; i++ ) {
		await new Promise( ( resolve ) => setImmediate( resolve ) );
	}
};

function element( text = '' ) {
	return {
		textContent: text,
		disabled: false,
		attributes: {},
		listeners: {},
		classes: new Set(),
		classList: { toggle( name, on ) { on ? this.owner.classes.add( name ) : this.owner.classes.delete( name ); } },
		setAttribute( name, value ) { this.attributes[ name ] = value; },
		addEventListener( type, handler ) { this.listeners[ type ] = handler; },
		focus() {},
	};
}

function harness( { responses = [], plaid = true, restNonce = '' } = {} ) {
	const root = element();
	const button = element( 'Connect bank and pay' );
	const status = element();
	status.classList.owner = status;
	root.querySelector = ( selector ) => ( { '[data-pbfp-pay]': button, '[data-pbfp-status]': status }[ selector ] || null );
	const requests = [];
	const redirects = [];
	const handlers = [];
	const queue = [ ...responses ];
	const window = {
		paybridgePlaidPayment: {
			orderId: 42,
			orderKey: 'wc_order_abc123',
			paymentNonce: '0123456789',
			restNonce,
			linkTokenUrl: 'https://shop.test/wp-json/paybridge-for-plaid/v1/link-token',
			completeUrl: 'https://shop.test/wp-json/paybridge-for-plaid/v1/complete',
			returnUrl: 'https://shop.test/checkout/order-received/42/?key=wc_order_abc123',
			i18n: { preparing: 'preparing', opening: 'opening', verifying: 'verifying', submitted: 'submitted', exited: 'exited', incomplete: 'incomplete', insufficient: 'insufficient', failed: 'failed', unverified: 'unverified', review: 'review', error: 'error', unavailable: 'unavailable', notPayable: 'notPayable', missingName: 'missingName', rateLimited: 'rateLimited', retry: 'Try again' },
		},
		location: { assign: ( url ) => redirects.push( url ) },
		setTimeout: ( fn ) => setImmediate( fn ),
	};
	if ( plaid ) {
		window.Plaid = { create( config ) { const handler = { config, opened: 0, open() { this.opened++; }, exit() {}, destroy() {} }; handlers.push( handler ); return handler; } };
	}
	const fetch = async ( url, options ) => {
		requests.push( { url, options, body: JSON.parse( options.body ) } );
		const next = queue.shift() || { status: 200, body: { status: 'unverified' } };
		return { ok: next.status >= 200 && next.status < 300, status: next.status, json: async () => next.body };
	};
	const context = vm.createContext( { window, document: { querySelector: ( selector ) => ( '.pbfp-payment' === selector ? root : null ) }, fetch, JSON, Promise, setImmediate } );
	vm.runInContext( source, context );
	return { root, button, status, requests, redirects, handlers };
}

test( 'double click issues one Link token request with no amount or intent in it', async () => {
	const page = harness( { responses: [ { status: 200, body: { status: 'ready', link_token: 'link-sandbox-1' } } ] } );
	assert.equal( page.button.disabled, false );
	page.button.listeners.click();
	page.button.listeners.click();
	assert.equal( page.button.disabled, true, 'button disabled while the request runs' );
	await tick();
	assert.equal( page.requests.length, 1 );
	assert.deepEqual( page.requests[ 0 ].body, { order_id: 42, order_key: 'wc_order_abc123', payment_nonce: '0123456789' } );
	assert.equal( page.requests[ 0 ].options.headers[ 'X-WP-Nonce' ], undefined, 'guests send no wp_rest nonce' );
	assert.equal( page.handlers.length, 1 );
	assert.equal( page.handlers[ 0 ].opened, 1 );
	assert.equal( page.status.textContent, 'opening' );
} );

test( 'logged-in customers send the REST nonce', async () => {
	const page = harness( { restNonce: 'restnonce1', responses: [ { status: 200, body: { status: 'ready', link_token: 'link-sandbox-1' } } ] } );
	page.button.listeners.click();
	await tick();
	assert.equal( page.requests[ 0 ].options.headers[ 'X-WP-Nonce' ], 'restnonce1' );
} );

test( 'onSuccess only asks the server to verify and follows the server redirect', async () => {
	const page = harness( { responses: [
		{ status: 200, body: { status: 'ready', link_token: 'link-sandbox-1' } },
		{ status: 200, body: { status: 'submitted', redirect: 'https://shop.test/order-received/42/' } },
	] } );
	page.button.listeners.click();
	await tick();
	page.handlers[ 0 ].config.onSuccess( 'public-sandbox-x', { transfer_status: 'COMPLETE', accounts: [ { id: 'acc' } ] } );
	await tick();
	assert.equal( page.requests[ 1 ].url, 'https://shop.test/wp-json/paybridge-for-plaid/v1/complete' );
	assert.deepEqual( page.requests[ 1 ].body, { order_id: 42, order_key: 'wc_order_abc123', payment_nonce: '0123456789' }, 'no public token, metadata or status is sent' );
	assert.deepEqual( page.redirects, [ 'https://shop.test/order-received/42/' ] );
} );

test( 'NSF decline re-enables the button as a retry', async () => {
	const page = harness( { responses: [
		{ status: 200, body: { status: 'ready', link_token: 'link-sandbox-1' } },
		{ status: 200, body: { status: 'incomplete', reason: 'NSF' } },
	] } );
	page.button.listeners.click();
	await tick();
	page.handlers[ 0 ].config.onSuccess( 'p', {} );
	await tick();
	assert.equal( page.status.textContent, 'insufficient' );
	assert.equal( page.button.disabled, false );
	assert.equal( page.button.textContent, 'Try again' );
	assert.deepEqual( page.redirects, [] );
} );

test( 'failed authorization and Link errors allow retry; manual review does not', async () => {
	const failed = harness( { responses: [ { status: 200, body: { status: 'ready', link_token: 't' } }, { status: 200, body: { status: 'failed' } } ] } );
	failed.button.listeners.click();
	await tick();
	failed.handlers[ 0 ].config.onSuccess( 'p', {} );
	await tick();
	assert.equal( failed.status.textContent, 'failed' );
	assert.equal( failed.button.disabled, false );

	const exited = harness( { responses: [ { status: 200, body: { status: 'ready', link_token: 't' } } ] } );
	exited.button.listeners.click();
	await tick();
	exited.handlers[ 0 ].config.onExit( { error_code: 'INSTITUTION_ERROR' }, {} );
	assert.equal( exited.status.textContent, 'exited' );
	assert.equal( exited.button.disabled, false );

	const review = harness( { responses: [ { status: 200, body: { status: 'ready', link_token: 't' } }, { status: 200, body: { status: 'incomplete', reason: 'MANUAL_REVIEW' } } ] } );
	review.button.listeners.click();
	await tick();
	review.handlers[ 0 ].config.onSuccess( 'p', {} );
	await tick();
	assert.equal( review.status.textContent, 'review' );
	assert.equal( review.button.disabled, true, 'no retry while the store reviews the payment' );
} );

test( 'server errors and a missing Plaid Link script are recoverable', async () => {
	const error = harness( { responses: [ { status: 500, body: { code: 'internal_server_error' } } ] } );
	error.button.listeners.click();
	await tick();
	assert.equal( error.status.textContent, 'error' );
	assert.equal( error.button.disabled, false );

	const unavailable = harness( { responses: [ { status: 503, body: { code: 'paybridge_unavailable' } } ] } );
	unavailable.button.listeners.click();
	await tick();
	assert.equal( unavailable.status.textContent, 'notPayable', 'a gateway that does not accept new payments is explained' );
	assert.equal( unavailable.button.disabled, false );

	const limited = harness( { responses: [ { status: 429, body: { code: 'paybridge_rate_limited' } } ] } );
	limited.button.listeners.click();
	await tick();
	assert.equal( limited.status.textContent, 'rateLimited' );
	assert.equal( limited.button.disabled, false );

	const noName = harness( { responses: [ { status: 400, body: { code: 'paybridge_missing_name' } } ] } );
	noName.button.listeners.click();
	await tick();
	assert.equal( noName.status.textContent, 'missingName' );
	assert.equal( noName.button.disabled, true, 'retrying cannot fix a missing account holder name' );
	assert.equal( noName.handlers.length, 0 );

	const noPlaid = harness( { plaid: false } );
	noPlaid.button.listeners.click();
	await tick();
	assert.equal( noPlaid.status.textContent, 'unavailable' );
	assert.equal( noPlaid.requests.length, 0, 'no token is requested without Plaid Link' );
} );

test( 'an already submitted payment redirects instead of opening Link', async () => {
	const page = harness( { responses: [ { status: 200, body: { status: 'submitted', redirect: 'https://shop.test/order-received/42/' } } ] } );
	page.button.listeners.click();
	await tick();
	assert.equal( page.handlers.length, 0 );
	assert.deepEqual( page.redirects, [ 'https://shop.test/order-received/42/' ] );
} );
