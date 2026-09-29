async page => {
	const initialUrl = page.url();
	const baseUrl = initialUrl.replace( /\/\?.*$/, '' ).replace( /\/$/, '' );
	const productId = ( initialUrl.match( /[?&]pbfp_e2e_product=(\d+)/ ) || [] )[ 1 ] || '';
	const consoleErrors = [];
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' && message.location().url.startsWith( baseUrl ) ) {
			consoleErrors.push( message.text() );
		}
	} );
	const assert = ( condition, message ) => {
		if ( ! condition ) {
			throw new Error( 'E2E ASSERTION FAILED: ' + message );
		}
	};
	assert( baseUrl && productId, 'The start URL must carry the product fixture.' );

	// Deterministic Plaid Link double served in place of cdn.plaid.com.
	const fakeLink = `window.Plaid = { create: function ( cfg ) {
		window.__pbfpCreated = ( window.__pbfpCreated || 0 ) + 1;
		return {
			open: function () {
				window.__pbfpOpened = ( window.__pbfpOpened || 0 ) + 1;
				var outcome = window.__pbfpOutcome || 'success';
				if ( outcome === 'exit' ) { setTimeout( function () { cfg.onExit( { error_code: 'USER_EXIT' }, {} ); }, 50 ); return; }
				fetch( '/?pbfp_e2e_authorize=' + encodeURIComponent( cfg.token ) + '&outcome=' + outcome, { credentials: 'same-origin' } )
					.then( function () { cfg.onSuccess( 'public-sandbox-e2e', { transfer_status: outcome === 'success' ? 'COMPLETE' : 'INCOMPLETE' } ); } );
			},
			exit: function () {},
			destroy: function () {}
		};
	} };`;
	let plaidAvailable = true;
	await page.route( 'https://cdn.plaid.com/**', ( route ) =>
		plaidAvailable ? route.fulfill( { status: 200, contentType: 'application/javascript', body: fakeLink } ) : route.abort()
	);
	let linkTokenRequests = 0;
	page.on( 'request', ( request ) => {
		if ( request.url().includes( '/paybridge-for-plaid/v1/link-token' ) ) {
			linkTokenRequests++;
		}
	} );
	const json = async ( path ) => {
		const response = await page.request.get( baseUrl + path );
		assert( response.ok(), 'Helper request failed: ' + path );
		return response.json();
	};
	const orderIdFromUrl = () => ( page.url().match( /order-(?:pay|received)\/(\d+)/ ) || [] )[ 1 ] || '';
	const statusText = async () => ( await page.locator( '[data-pbfp-status]' ).textContent() ) || '';
	const waitStatus = async ( fragment ) => {
		await page.waitForFunction( ( text ) => ( document.querySelector( '[data-pbfp-status]' ) || {} ).textContent?.includes( text ), fragment, { timeout: 15000 } );
	};

	// ---------------------------------------------------------------- admin
	await page.goto( baseUrl + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', 'pbfp_admin' );
	await page.fill( '#user_pass', 'local-test-password' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	await page.goto( baseUrl + '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=paybridge_plaid', { waitUntil: 'domcontentloaded' } );
	const settingsHtml = await page.content();
	assert( settingsHtml.includes( 'PayBridge for Plaid' ), 'Settings screen renders.' );
	assert( ! settingsHtml.includes( 'browser-sandbox-secret-value' ), 'The stored secret is never rendered.' );
	assert( await page.locator( '#woocommerce_paybridge_plaid_secret' ).getAttribute( 'type' ) === 'password', 'Secret input is a password field.' );
	assert( ( await page.locator( '#woocommerce_paybridge_plaid_secret' ).inputValue() ) === '', 'Secret input is empty.' );
	assert( ( await page.locator( '#pbfp-webhook-url' ).inputValue() ).endsWith( '/paybridge-for-plaid/v1/webhook' ), 'Webhook URL is shown.' );
	assert( await page.getByRole( 'button', { name: 'Copy' } ).isVisible(), 'Copy webhook URL button is shown.' );
	assert( ( await page.locator( '.pbfp-status--ok' ).first().textContent() ).includes( 'Ready' ), 'Configuration status is ready.' );
	await Promise.all( [ page.waitForNavigation(), page.getByRole( 'link', { name: 'Test connection' } ).click() ] );
	assert( ( await page.content() ).includes( 'Connected to Plaid Transfer' ), 'Test connection reports success.' );
	for ( const legacy of [ 'cryptocurrency', 'Cryptocurrency', 'Shop ID', 'Merchant secret', 'payment direction' ] ) {
		assert( ! ( await page.locator( '#mainform' ).textContent() ).includes( legacy ), 'No obsolete setting: ' + legacy );
	}
	await page.goto( baseUrl + '/wp-admin/admin.php?page=paybridge-plaid-diagnostics', { waitUntil: 'domcontentloaded' } );
	const diagnostics = await page.content();
	assert( diagnostics.includes( 'Database schema' ) && diagnostics.includes( 'Valid' ), 'Diagnostics page shows a valid schema.' );
	assert( ! diagnostics.includes( 'browser-sandbox-secret-value' ), 'Diagnostics never expose the secret.' );
	await page.context().clearCookies();

	// ------------------------------------------------------ classic checkout
	const classicCheckout = async () => {
		await page.goto( baseUrl + '/?add-to-cart=' + productId, { waitUntil: 'domcontentloaded' } );
		await page.goto( baseUrl + '/classic-checkout/', { waitUntil: 'networkidle' } );
		await page.getByRole( 'textbox', { name: 'First name' } ).fill( 'Anne' );
		await page.getByRole( 'textbox', { name: 'Last name' } ).fill( 'Charleston' );
		await page.getByRole( 'textbox', { name: 'Street address' } ).fill( '100 Market Street' );
		await page.getByRole( 'textbox', { name: 'Town / City' } ).fill( 'San Francisco' );
		await page.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94103' );
		await page.getByRole( 'textbox', { name: 'Phone' } ).fill( '4155550100' );
		await page.getByRole( 'textbox', { name: 'Email address' } ).fill( 'anne@example.com' );
		assert( ( await page.locator( 'li.payment_method_paybridge_plaid' ).textContent() ).includes( 'Securely pay directly from your bank account.' ), 'Classic checkout shows the Pay by Bank description.' );
		await Promise.all( [ page.waitForURL( /order-pay\/\d+/, { timeout: 30000 } ), page.locator( '#place_order' ).click() ] );
	};
	await classicCheckout();
	let orderId = orderIdFromUrl();
	assert( orderId, 'Classic checkout redirects to the PayBridge payment page.' );
	const summary = await page.locator( '.pbfp-payment__summary' ).textContent();
	assert( summary.includes( '#' + orderId ) && summary.includes( '11.11' ), 'Payment page shows the order number and server-side amount.' );
	const payButton = page.locator( '[data-pbfp-pay]' );
	await payButton.waitFor();
	assert( await payButton.isEnabled(), 'Pay button is ready once Plaid Link is loaded.' );
	assert( ( await payButton.textContent() ).includes( 'Connect bank and pay' ), 'Pay button label.' );
	let state = await json( '/?pbfp_e2e_order=' + orderId );
	assert( state.payment_state === 'intent_created' && state.intent_id && state.creates === 1, 'Transfer Intent created exactly once at checkout.' );

	// Double click → exactly one Link token request.
	linkTokenRequests = 0;
	await page.evaluate( () => { window.__pbfpOutcome = 'success'; } );
	await payButton.dblclick();
	await page.waitForURL( /order-received\/\d+/, { timeout: 30000 } );
	assert( linkTokenRequests === 1, 'Double click issues a single Link token request (got ' + linkTokenRequests + ').' );
	assert( await page.getByRole( 'heading', { name: 'Order received' } ).isVisible(), 'Customer lands on the order received page.' );
	state = await json( '/?pbfp_e2e_order=' + orderId );
	assert( state.status === 'on-hold' && ! state.paid && state.transfer_id, 'After Transfer UI the order is on-hold (not paid) with a transfer ID.' );
	state = await json( '/?pbfp_e2e_order=' + orderId + '&advance=1' );
	assert( state.paid && state.payment_state === 'funds_available', 'Plaid events mark the order paid only at funds_available.' );

	// ----------------------------------------------- failure / exit / retry UX
	await classicCheckout();
	orderId = orderIdFromUrl();
	await payButton.waitFor();
	await page.evaluate( () => { window.__pbfpOutcome = 'failed'; } );
	await payButton.click();
	await waitStatus( 'could not be authorized' );
	assert( await payButton.isEnabled() && ( await payButton.textContent() ).includes( 'Try again' ), 'Failure re-enables the button as a retry.' );
	const failedIntent = ( await json( '/?pbfp_e2e_order=' + orderId ) ).intent_id;
	await page.evaluate( () => { window.__pbfpOutcome = 'exit'; } );
	await payButton.click();
	await waitStatus( 'closed before the payment was completed' );
	assert( await payButton.isEnabled(), 'Exit leaves the payment retryable.' );
	await page.evaluate( () => { window.__pbfpOutcome = 'success'; } );
	await payButton.click();
	await page.waitForURL( /order-received\/\d+/, { timeout: 30000 } );
	state = await json( '/?pbfp_e2e_order=' + orderId );
	assert( state.intent_id !== failedIntent && state.transfer_id, 'Retry after a failed intent uses a new intent and succeeds.' );

	// -------------------------------------------- access control (new session)
	await classicCheckout();
	const protectedUrl = page.url();
	await page.context().clearCookies();
	await page.goto( protectedUrl, { waitUntil: 'domcontentloaded' } );
	assert( ( await page.locator( '[data-pbfp-pay]' ).count() ) === 0, 'Another session cannot open the payment page, even with the order key.' );
	assert( ( await page.content() ).includes( 'This payment session has expired' ), 'Access denial message is shown.' );

	// ------------------------------------------------ Plaid Link unavailable
	await classicCheckout();
	plaidAvailable = false;
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.locator( '[data-pbfp-pay]' ).click();
	await waitStatus( 'could not be loaded' );
	plaidAvailable = true;

	// --------------------------------------------------------- Checkout Blocks
	await json( '/?pbfp_e2e_checkout=blocks' );
	await page.goto( baseUrl + '/?add-to-cart=' + productId, { waitUntil: 'domcontentloaded' } );
	await page.goto( baseUrl + '/blocks-checkout/', { waitUntil: 'networkidle' } );
	const blocksMethod = page.getByRole( 'radio', { name: 'Pay by Bank' } );
	await blocksMethod.waitFor( { timeout: 20000 } );
	await blocksMethod.check();
	assert( ( await page.content() ).includes( 'Securely pay directly from your bank account.' ), 'Blocks checkout shows the description.' );
	const email = page.getByRole( 'textbox', { name: 'Email address' } );
	if ( ( await email.inputValue() ) === '' ) {
		await email.fill( 'anne@example.com' );
	}
	await Promise.all( [ page.waitForURL( /order-pay\/\d+/, { timeout: 30000 } ), page.getByRole( 'button', { name: 'Place Order' } ).click() ] );
	orderId = orderIdFromUrl();
	await page.locator( '[data-pbfp-pay]' ).waitFor();
	await page.evaluate( () => { window.__pbfpOutcome = 'success'; } );
	await page.locator( '[data-pbfp-pay]' ).click();
	await page.waitForURL( /order-received\/\d+/, { timeout: 30000 } );
	state = await json( '/?pbfp_e2e_order=' + orderId );
	assert( state.status === 'on-hold' && state.transfer_id, 'Checkout Blocks order reaches Transfer UI and on-hold.' );
	await json( '/?pbfp_e2e_checkout=classic' );

	assert( consoleErrors.length === 0, 'No browser console errors: ' + consoleErrors.join( ' | ' ) );
	return 'PayBridge browser smoke passed.';
}
