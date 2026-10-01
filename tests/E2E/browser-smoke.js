async page => {
	const initialUrl = page.url();
	const baseUrl = initialUrl.replace( /\/\?.*$/, '' ).replace( /\/$/, '' );
	const productId = ( initialUrl.match( /[?&]bmfp_e2e_product=(\d+)/ ) || [] )[ 1 ] || '';
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
		window.__bmfpCreated = ( window.__bmfpCreated || 0 ) + 1;
		return {
			open: function () {
				window.__bmfpOpened = ( window.__bmfpOpened || 0 ) + 1;
				var outcome = window.__bmfpOutcome || 'success';
				if ( outcome === 'exit' ) { setTimeout( function () { cfg.onExit( { error_code: 'USER_EXIT' }, {} ); }, 50 ); return; }
				fetch( '/?bmfp_e2e_authorize=' + encodeURIComponent( cfg.token ) + '&outcome=' + outcome, { credentials: 'same-origin' } )
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
		if ( request.url().includes( '/buckmerce-plaid/v1/link-token' ) ) {
			linkTokenRequests++;
		}
	} );
	const json = async ( path ) => {
		const response = await page.request.get( baseUrl + path );
		assert( response.ok(), 'Helper request failed: ' + path );
		return response.json();
	};
	const blocksCheckout = async () => {
		await page.goto( baseUrl + '/?add-to-cart=' + productId, { waitUntil: 'domcontentloaded' } );
		await page.goto( baseUrl + '/blocks-checkout/', { waitUntil: 'networkidle' } );
		const method = page.getByRole( 'radio', { name: 'Pay by Bank' } );
		await method.waitFor( { timeout: 20000 } );
		await method.check();
		const fill = async ( name, value ) => {
			const field = page.getByRole( 'textbox', { name, exact: true } );
			if ( ( await field.count() ) > 0 && ( await field.first().isVisible() ) && '' === ( await field.first().inputValue() ) ) {
				await field.first().fill( value );
			}
		};
		await fill( 'Email address', 'anne@example.com' );
		await fill( 'First name', 'Anne' );
		await fill( 'Last name', 'Charleston' );
		await fill( 'Address', '100 Market Street' );
		await fill( 'City', 'San Francisco' );
		await fill( 'ZIP Code', '94103' );
		await Promise.all( [ page.waitForURL( /order-pay\/\d+/, { timeout: 30000 } ), page.getByRole( 'button', { name: 'Place Order' } ).click() ] );
	};
	const methodOffered = async () => {
		await page.goto( baseUrl + '/?add-to-cart=' + productId, { waitUntil: 'domcontentloaded' } );
		await page.goto( baseUrl + '/classic-checkout/', { waitUntil: 'networkidle' } );
		const classic = await page.locator( 'li.payment_method_buckmerce_plaid' ).count();
		await json( '/?bmfp_e2e_checkout=blocks' );
		await page.goto( baseUrl + '/blocks-checkout/', { waitUntil: 'networkidle' } );
		await page.locator( '.wp-block-woocommerce-checkout' ).first().waitFor( { timeout: 20000 } );
		await page.waitForTimeout( 1500 );
		const blocks = await page.getByRole( 'radio', { name: 'Pay by Bank' } ).count();
		await json( '/?bmfp_e2e_checkout=classic' );
		return { classic, blocks };
	};
	const orderIdFromUrl = () => ( page.url().match( /order-(?:pay|received)\/(\d+)/ ) || [] )[ 1 ] || '';
	const statusText = async () => ( await page.locator( '[data-bmfp-status]' ).textContent() ) || '';
	const waitStatus = async ( fragment ) => {
		await page.waitForFunction( ( text ) => ( document.querySelector( '[data-bmfp-status]' ) || {} ).textContent?.includes( text ), fragment, { timeout: 15000 } );
	};
	// axe-core WCAG 2.x A/AA scan of Buckmerce-owned markup only (theme, WordPress and Plaid's iframe are out of scope).
	const axe = async ( selector, label ) => {
		const loaded = await page.evaluate( () => typeof window.axe !== 'undefined' );
		if ( ! loaded ) {
			await page.addScriptTag( { url: baseUrl + '/wp-content/bmfp-axe.min.js' } );
		}
		const violations = await page.evaluate( async ( scope ) => {
			const result = await window.axe.run( { include: [ scope ] }, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] } } );
			return result.violations.map( ( v ) => ( { id: v.id, impact: v.impact, nodes: v.nodes.slice( 0, 3 ).map( ( n ) => n.target.join( ' ' ) ) } ) );
		}, selector );
		assert( violations.length === 0, 'Accessibility (' + label + '): ' + JSON.stringify( violations ) );
		accessibilityScans.push( label );
	};
	const accessibilityScans = [];
	const activeIsPayButton = () => page.evaluate( () => document.activeElement === document.querySelector( '[data-bmfp-pay]' ) );
	// The phase reached is reported with any failure, so a CI log says where the run stopped.
	let currentPhase = 'start';
	const phase = ( name ) => {
		currentPhase = name;
	};
	try {

	// ---------------------------------------------------------------- admin
	phase( 'admin' );
	await page.goto( baseUrl + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', 'bmfp_admin' );
	await page.fill( '#user_pass', 'local-test-password' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	await page.goto( baseUrl + '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=buckmerce_plaid', { waitUntil: 'domcontentloaded' } );
	const settingsHtml = await page.content();
	assert( settingsHtml.includes( 'Buckmerce for Plaid' ), 'Settings screen renders.' );
	assert( ! settingsHtml.includes( 'browser-sandbox-secret-value' ), 'The stored secret is never rendered.' );
	assert( await page.locator( '#woocommerce_buckmerce_plaid_secret' ).getAttribute( 'type' ) === 'password', 'Secret input is a password field.' );
	assert( ( await page.locator( '#woocommerce_buckmerce_plaid_secret' ).inputValue() ) === '', 'Secret input is empty.' );
	assert( ( await page.locator( '#bmfp-webhook-url' ).inputValue() ).endsWith( '/buckmerce-plaid/v1/webhook' ), 'Webhook URL is shown.' );
	assert( await page.getByRole( 'button', { name: 'Copy' } ).isVisible(), 'Copy webhook URL button is shown.' );
	assert( ( await page.locator( '#woocommerce_buckmerce_plaid_ach_class' ).count() ) === 0, 'ACH class is not a merchant setting (always WEB).' );
	assert( ( await page.locator( '#woocommerce_buckmerce_plaid_statement_descriptor' ).inputValue() ) === 'PAYMENT', 'Statement descriptor setting.' );
	assert( ( await page.locator( '.bmfp-badge--sandbox' ).first().textContent() ).includes( 'Sandbox' ), 'Sandbox is visibly distinguished.' );
	await Promise.all( [ page.waitForNavigation(), page.getByRole( 'link', { name: 'Test connection' } ).click() ] );
	assert( ( await page.content() ).includes( 'Connected to Plaid Transfer' ), 'Test connection reports success.' );
	assert( ( await page.locator( '#bmfp-status-heading' ).textContent() ).includes( 'Ready' ), 'Configuration status is Ready after a successful test.' );
	assert( ( await page.locator( '.bmfp-check--pass' ).filter( { hasText: 'Plaid Client ID and Secret' } ).count() ) === 1, 'Checklist shows the credential check.' );
	await axe( '.bmfp-status-panel', 'settings status panel' );
	for ( const legacy of [ 'cryptocurrency', 'Cryptocurrency', 'Shop ID', 'Merchant secret', 'payment direction' ] ) {
		assert( ! ( await page.locator( '#mainform' ).textContent() ).includes( legacy ), 'No obsolete setting: ' + legacy );
	}
	await page.goto( baseUrl + '/wp-admin/admin.php?page=buckmerce-plaid-diagnostics', { waitUntil: 'domcontentloaded' } );
	const diagnostics = await page.content();
	assert( diagnostics.includes( 'Database schema' ) && diagnostics.includes( 'Valid' ), 'Diagnostics page shows a valid schema.' );
	assert( diagnostics.includes( 'Monitored bank payments' ) && diagnostics.includes( 'Refunds pending' ), 'Diagnostics show operational counts.' );
	assert( ! diagnostics.includes( 'browser-sandbox-secret-value' ), 'Diagnostics never expose the secret.' );
	await axe( '.wrap', 'diagnostics page' );
	await page.context().clearCookies();

	// ------------------------------------------------------ classic checkout
	phase( 'classic checkout' );
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
		assert( ( await page.locator( 'li.payment_method_buckmerce_plaid' ).textContent() ).includes( 'Securely pay directly from your bank account.' ), 'Classic checkout shows the Pay by Bank description.' );
		if ( ! accessibilityScans.includes( 'classic checkout payment method' ) ) {
			await axe( 'li.payment_method_buckmerce_plaid', 'classic checkout payment method' );
		}
		await Promise.all( [ page.waitForURL( /order-pay\/\d+/, { timeout: 30000 } ), page.locator( '#place_order' ).click() ] );
	};
	await classicCheckout();
	let orderId = orderIdFromUrl();
	assert( orderId, 'Classic checkout redirects to the Buckmerce payment page.' );
	const summary = await page.locator( '.bmfp-payment__summary' ).textContent();
	assert( summary.includes( '#' + orderId ) && summary.includes( '11.11' ), 'Payment page shows the order number and server-side amount.' );
	const payButton = page.locator( '[data-bmfp-pay]' );
	await payButton.waitFor();
	assert( await payButton.isEnabled(), 'Pay button is ready once Plaid Link is loaded.' );
	assert( ( await payButton.textContent() ).includes( 'Connect bank and pay' ), 'Pay button label.' );
	let state = await json( '/?bmfp_e2e_order=' + orderId );
	assert( state.payment_state === 'intent_created' && state.intent_id && state.creates === 1, 'Transfer Intent created exactly once at checkout.' );
	const firstPaidOrder = orderId;
	assert( await page.locator( '.bmfp-payment__sandbox' ).isVisible(), 'Sandbox payment page shows a discrete Sandbox indicator.' );
	assert( ( await page.locator( '.bmfp-payment__steps li' ).count() ) === 3, 'The page explains what happens next.' );
	const describedBy = await payButton.getAttribute( 'aria-describedby' );
	assert( describedBy && ( await page.locator( '#' + describedBy ).getAttribute( 'role' ) ) === 'status', 'Status messages are associated with the button and announced (role=status).' );
	await axe( '.bmfp-payment', 'payment page (ready)' );

	// Double click → exactly one Link token request.
	linkTokenRequests = 0;
	await page.evaluate( () => { window.__bmfpOutcome = 'success'; } );
	await payButton.dblclick();
	await page.waitForURL( /order-received\/\d+/, { timeout: 30000 } );
	assert( linkTokenRequests === 1, 'Double click issues a single Link token request (got ' + linkTokenRequests + ').' );
	assert( await page.getByRole( 'heading', { name: 'Order received' } ).isVisible(), 'Customer lands on the order received page.' );
	state = await json( '/?bmfp_e2e_order=' + orderId );
	assert( state.status === 'on-hold' && ! state.paid && state.transfer_id, 'After Transfer UI the order is on-hold (not paid) with a transfer ID.' );
	state = await json( '/?bmfp_e2e_order=' + orderId + '&advance=1' );
	assert( state.paid && state.payment_state === 'funds_available', 'Plaid events mark the order paid only at funds_available.' );

	// ----------------------------------------------- failure / exit / retry UX
	phase( 'failure / exit / retry UX' );
	await classicCheckout();
	orderId = orderIdFromUrl();
	await payButton.waitFor();
	await page.evaluate( () => { window.__bmfpOutcome = 'failed'; } );
	// Loading state: the Link token request is slowed down so the busy state can be observed.
	await page.route( '**/buckmerce-plaid/v1/link-token', async ( route ) => { await new Promise( ( resolve ) => setTimeout( resolve, 1200 ) ); await route.continue(); } );
	await payButton.focus();
	await page.keyboard.press( 'Enter' );
	await page.waitForFunction( () => document.querySelector( '.bmfp-payment' ).getAttribute( 'aria-busy' ) === 'true' );
	assert( await payButton.isDisabled(), 'Loading state: the button is disabled while the bank connection is prepared.' );
	assert( ( await statusText() ).length > 0, 'Loading state is announced in the status region.' );
	await axe( '.bmfp-payment', 'payment page (loading)' );
	await page.unroute( '**/buckmerce-plaid/v1/link-token' );
	await waitStatus( 'could not be authorized' );
	assert( await payButton.isEnabled() && ( await payButton.textContent() ).includes( 'Try again' ), 'Failure re-enables the button as a retry (keyboard activation works).' );
	assert( await activeIsPayButton(), 'Focus returns to the retry button after the failure is announced.' );
	assert( await page.locator( '.bmfp-payment__status--error' ).isVisible(), 'Error state is visually marked.' );
	await axe( '.bmfp-payment', 'payment page (error / retry)' );
	const failedIntent = ( await json( '/?bmfp_e2e_order=' + orderId ) ).intent_id;
	await page.evaluate( () => { window.__bmfpOutcome = 'exit'; } );
	await payButton.click();
	await waitStatus( 'closed before the payment was completed' );
	assert( await payButton.isEnabled(), 'Exit leaves the payment retryable.' );
	assert( await activeIsPayButton(), 'Focus is restored to the payment button after Plaid Link exits.' );
	await page.evaluate( () => { window.__bmfpOutcome = 'success'; } );
	await payButton.click();
	await page.waitForURL( /order-received\/\d+/, { timeout: 30000 } );
	state = await json( '/?bmfp_e2e_order=' + orderId );
	assert( state.intent_id !== failedIntent && state.transfer_id, 'Retry after a failed intent uses a new intent and succeeds.' );
	const returningOrder = orderId;

	// -------------------------------------------- access control (new session)
	phase( 'access control (new session)' );
	await classicCheckout();
	const protectedUrl = page.url();
	const unpaidOrder = orderIdFromUrl();
	await page.context().clearCookies();
	await page.goto( protectedUrl, { waitUntil: 'domcontentloaded' } );
	assert( ( await page.locator( '[data-bmfp-pay]' ).count() ) === 0, 'Another session cannot open the payment page, even with the order key.' );
	assert( ( await page.content() ).includes( 'This payment session has expired' ), 'Access denial message is shown.' );

	// ------------------------------------------------ Plaid Link unavailable
	phase( 'Plaid Link unavailable' );
	await classicCheckout();
	plaidAvailable = false;
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.locator( '[data-bmfp-pay]' ).click();
	await waitStatus( 'could not be loaded' );
	plaidAvailable = true;

	// --------------------------------------------------------- Checkout Blocks
	phase( 'Checkout Blocks' );
	await json( '/?bmfp_e2e_checkout=blocks' );
	await page.goto( baseUrl + '/?add-to-cart=' + productId, { waitUntil: 'domcontentloaded' } );
	await page.goto( baseUrl + '/blocks-checkout/', { waitUntil: 'networkidle' } );
	const blocksMethod = page.getByRole( 'radio', { name: 'Pay by Bank' } );
	await blocksMethod.waitFor( { timeout: 20000 } );
	await blocksMethod.check();
	assert( ( await page.content() ).includes( 'Securely pay directly from your bank account.' ), 'Blocks checkout shows the description.' );
	await axe( '.bmfp-block-label', 'checkout block payment method label' );
	await axe( '.bmfp-block-description', 'checkout block payment method description' );
	const email = page.getByRole( 'textbox', { name: 'Email address' } );
	if ( ( await email.inputValue() ) === '' ) {
		await email.fill( 'anne@example.com' );
	}
	await Promise.all( [ page.waitForURL( /order-pay\/\d+/, { timeout: 30000 } ), page.getByRole( 'button', { name: 'Place Order' } ).click() ] );
	orderId = orderIdFromUrl();
	await page.locator( '[data-bmfp-pay]' ).waitFor();
	await page.evaluate( () => { window.__bmfpOutcome = 'success'; } );
	await page.locator( '[data-bmfp-pay]' ).click();
	await page.waitForURL( /order-received\/\d+/, { timeout: 30000 } );
	state = await json( '/?bmfp_e2e_order=' + orderId );
	assert( state.status === 'on-hold' && state.transfer_id, 'Checkout Blocks order reaches Transfer UI and on-hold.' );
	await json( '/?bmfp_e2e_checkout=classic' );

	// ------------------------------------ misconfiguration and unsupported currency
	phase( 'misconfiguration and unsupported currency' );
	await json( '/?bmfp_e2e_config=secretless' );
	let offered = await methodOffered();
	assert( offered.classic === 0 && offered.blocks === 0, 'A misconfigured gateway is offered in neither checkout: ' + JSON.stringify( offered ) );
	await json( '/?bmfp_e2e_config=eur' );
	offered = await methodOffered();
	assert( offered.classic === 0 && offered.blocks === 0, 'Pay by Bank is not offered for EUR: ' + JSON.stringify( offered ) );
	await json( '/?bmfp_e2e_config=reset' );
	offered = await methodOffered();
	assert( offered.classic === 1 && offered.blocks === 1, 'Restored configuration is offered in both checkouts: ' + JSON.stringify( offered ) );

	// ------------------------------------------- already-paid and cancelled orders
	phase( 'already-paid and cancelled orders' );
	state = await json( '/?bmfp_e2e_order=' + firstPaidOrder );
	await page.goto( state.pay_url, { waitUntil: 'domcontentloaded' } );
	assert( ( await page.locator( '[data-bmfp-pay]' ).count() ) === 0 && ( await page.content() ).includes( 'cannot be paid' ), 'An already-paid order cannot be paid again.' );
	state = await json( '/?bmfp_e2e_order=' + unpaidOrder + '&cancel=1' );
	assert( state.status === 'cancelled', 'Precondition: order cancelled by the store.' );
	await page.goto( state.pay_url, { waitUntil: 'domcontentloaded' } );
	assert( ( await page.locator( '[data-bmfp-pay]' ).count() ) === 0 && ( await page.content() ).includes( 'cannot be paid' ), 'A cancelled order cannot be paid.' );

	// --------------------------------------- logged-in customer: Classic and Blocks
	phase( 'logged-in customer: Classic and Blocks' );
	const customer = await json( '/?bmfp_e2e_customer=1' );
	await page.context().clearCookies();
	await page.goto( baseUrl + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', customer.login );
	await page.fill( '#user_pass', customer.password );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	await classicCheckout();
	orderId = orderIdFromUrl();
	await page.locator( '[data-bmfp-pay]' ).waitFor();
	await page.evaluate( () => { window.__bmfpOutcome = 'success'; } );
	await page.locator( '[data-bmfp-pay]' ).click();
	await page.waitForURL( /order-received\/\d+/, { timeout: 30000 } );
	state = await json( '/?bmfp_e2e_order=' + orderId );
	assert( state.status === 'on-hold' && state.transfer_id, 'Logged-in Classic checkout reaches Transfer UI and on-hold.' );
	await json( '/?bmfp_e2e_checkout=blocks' );
	await blocksCheckout();
	orderId = orderIdFromUrl();
	await page.locator( '[data-bmfp-pay]' ).waitFor();
	await page.evaluate( () => { window.__bmfpOutcome = 'failed'; } );
	await page.locator( '[data-bmfp-pay]' ).click();
	await waitStatus( 'could not be authorized' );
	await page.evaluate( () => { window.__bmfpOutcome = 'exit'; } );
	await page.locator( '[data-bmfp-pay]' ).click();
	await waitStatus( 'closed before the payment was completed' );
	await page.evaluate( () => { window.__bmfpOutcome = 'success'; } );
	await page.locator( '[data-bmfp-pay]' ).click();
	await page.waitForURL( /order-received\/\d+/, { timeout: 30000 } );
	state = await json( '/?bmfp_e2e_order=' + orderId );
	assert( state.status === 'on-hold' && state.transfer_id, 'Logged-in Checkout Blocks order: failure, exit and retry, then success.' );
	await json( '/?bmfp_e2e_checkout=classic' );
	await page.context().clearCookies();

	// ---------------------------------------------- admin: native refunds and returns
	phase( 'admin: native refunds and returns' );
	await page.goto( baseUrl + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', 'bmfp_admin' );
	await page.fill( '#user_pass', 'local-test-password' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	state = await json( '/?bmfp_e2e_order=' + firstPaidOrder );
	assert( state.paid, 'Precondition: the first order is paid.' );
	await page.goto( state.edit_url, { waitUntil: 'domcontentloaded' } );
	await axe( '.bmfp-order-panel', 'order payment panel' );
	await page.locator( 'button.refund-items' ).click();
	await page.locator( '#refund_amount' ).fill( '5.00' );
	const refundButton = page.locator( 'button.do-api-refund' );
	assert( ( await refundButton.textContent() ).includes( 'via Buckmerce for Plaid' ), 'WooCommerce offers an automatic refund through the gateway.' );
	// WooCommerce asks "Are you sure…?" with window.confirm(). A native dialog would end the
	// playwright-cli run (it reports a "Modal state" instead of continuing), so the merchant's
	// confirmation is given in the page itself.
	await page.evaluate( () => {
		window.__bmfpConfirmed = [];
		window.confirm = ( message ) => {
			window.__bmfpConfirmed.push( String( message ) );
			return true;
		};
	} );
	await Promise.all( [ page.waitForNavigation( { timeout: 30000 } ), refundButton.click() ] );
	state = await json( '/?bmfp_e2e_order=' + firstPaidOrder );
	assert( state.refund_creates === 1, 'Exactly one Plaid refund from the WooCommerce admin refund.' );
	const panel = page.locator( '.bmfp-order-panel' );
	assert( ( await panel.textContent() ).includes( 'Refunds' ) && ( await panel.textContent() ).includes( 'pending' ), 'The payment panel lists the refund and its Plaid status.' );
	await json( '/?bmfp_e2e_order=' + firstPaidOrder + '&refund_event=1' );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	assert( ( await page.locator( '.bmfp-order-panel' ).textContent() ).includes( 'posted' ), 'Refund events update the panel.' );
	await axe( '.bmfp-order-panel', 'order payment panel with refunds' );

	state = await json( '/?bmfp_e2e_order=' + returningOrder + '&advance=1' );
	assert( state.paid, 'Precondition: the second order is paid.' );
	state = await json( '/?bmfp_e2e_order=' + returningOrder + '&return=1' );
	assert( state.payment_state === 'returned' && state.status === 'failed', 'ACH return → returned / Failed.' );
	await page.goto( baseUrl + '/wp-admin/admin.php?page=wc-orders', { waitUntil: 'domcontentloaded' } );
	if ( ( await page.locator( 'mark.bmfp-list-badge' ).count() ) === 0 ) {
		await page.goto( baseUrl + '/wp-admin/edit.php?post_type=shop_order', { waitUntil: 'domcontentloaded' } );
	}
	assert( ( await page.locator( 'mark.bmfp-list-badge--critical' ).first().textContent() ).includes( 'Bank payment returned (R01)' ), 'The orders list flags the returned bank payment explicitly.' );
	assert( ( await page.content() ).includes( 'was RETURNED' ), 'A persistent admin alert reports the return.' );
	await page.goto( state.edit_url, { waitUntil: 'domcontentloaded' } );
	assert( ( await page.locator( '.bmfp-order-panel' ).textContent() ).includes( 'Bank payment returned' ), 'The order panel explains the return and keeps the payment details.' );
	await axe( '.bmfp-order-panel', 'order payment panel (returned)' );
	await page.context().clearCookies();

	// ------------------------ no same-order bank debit after a return (ADR-0019)
	phase( 'blocked repayment after a return' );
	const beforeRepay = await json( '/?bmfp_e2e_order=' + returningOrder );
	assert( beforeRepay.needs_payment && beforeRepay.payment_state === 'returned', 'Precondition: a returned, unpaid order.' );
	await page.goto( beforeRepay.pay_url, { waitUntil: 'networkidle' } );
	assert( ( await page.locator( '#payment_method_buckmerce_plaid' ).count() ) === 0, 'Pay by Bank is not offered to pay a returned order again.' );
	const returnedNotice = page.locator( '.bmfp-returned-notice' );
	assert( await returnedNotice.isVisible(), 'The customer is told why Pay by Bank is unavailable.' );
	assert( ! ( await returnedNotice.textContent() ).match( /R0\d|R1\d/ ), 'The customer message carries no return codes.' );
	await axe( '.bmfp-returned-notice', 'returned payment notice (order-pay)' );
	const blockedRequests = linkTokenRequests;
	await page.goto( beforeRepay.receipt_url, { waitUntil: 'networkidle' } );
	assert( ( await page.locator( '[data-bmfp-pay]' ).count() ) === 0, 'The Buckmerce payment page offers no pay button for a returned order.' );
	const afterRepay = await json( '/?bmfp_e2e_order=' + returningOrder );
	assert( afterRepay.creates === beforeRepay.creates && afterRepay.link_tokens === beforeRepay.link_tokens && linkTokenRequests === blockedRequests, 'No Transfer Intent or Link token was created for the returned order.' );
	assert( afterRepay.transfer_id === beforeRepay.transfer_id && afterRepay.payment_state === 'returned', 'The returned transfer stays the order\'s auditable attempt.' );

	assert( consoleErrors.length === 0, 'No browser console errors: ' + consoleErrors.join( ' | ' ) );
	return 'Buckmerce browser smoke passed; accessibility scans: ' + accessibilityScans.join( ', ' ) + '.';
	} catch ( error ) {
		error.message = '[E2E phase: ' + currentPhase + ' | ' + page.url() + '] ' + error.message;
		throw error;
	}
}
