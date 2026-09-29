async page => {
	// Real Plaid Sandbox: drives the genuine Plaid Transfer UI (no test doubles).
	// The configuration travels in the URL fragment, which browsers never send to a server or tunnel.
	const baseUrl = page.url().replace( /[?#].*$/, '' ).replace( /\/$/, '' );
	const config = JSON.parse( decodeURIComponent( ( page.url().match( /#pbfp_sandbox=([^&]+)/ ) || [] )[ 1 ] || '%7B%7D' ) );
	const assert = ( condition, message ) => {
		if ( ! condition ) {
			throw new Error( 'SANDBOX ASSERTION FAILED: ' + message );
		}
	};
	assert( config.checkout && config.products && config.username && config.password, 'Sandbox configuration is missing.' );
	if ( config.publicHost ) {
		// The store is served through an ngrok tunnel. Only requests to the store carry the header
		// that skips ngrok's browser warning page; Plaid's own requests are left untouched.
		await page.context().route(
			( url ) => url.hostname === config.publicHost,
			( route ) => route.continue( { headers: { ...route.request().headers(), 'ngrok-skip-browser-warning': '1' } } )
		);
		assert( baseUrl.startsWith( 'https://' + config.publicHost ), 'The store runs on its public HTTPS URL.' );
	}
	const results = {};
	const checkouts = {};
	const useCheckout = async ( kind ) => {
		const response = await page.goto( baseUrl + '/?pbfp_sandbox_checkout=' + kind, { waitUntil: 'domcontentloaded' } );
		const data = JSON.parse( await response.text() );
		assert( data.ok, 'Switched the store to the ' + kind + ' checkout page.' );
		return data.checkout;
	};
	for ( const [ amount, productId ] of Object.entries( config.products ) ) {
		// One payment goes through the Checkout block, the others through the Classic checkout.
		const kind = amount === config.blocksAmount ? 'blocks' : 'classic';
		checkouts[ amount ] = kind;
		await page.context().clearCookies();
		const checkoutUrl = await useCheckout( kind );
		await page.goto( baseUrl + '/?add-to-cart=' + productId, { waitUntil: 'domcontentloaded' } );
		await page.goto( checkoutUrl, { waitUntil: 'networkidle' } );
		if ( 'blocks' === kind ) {
			const field = ( name ) => page.getByRole( 'textbox', { name, exact: true } );
			await field( 'Email address' ).fill( 'anne@example.com' );
			await field( 'First name' ).fill( 'Anne' );
			await field( 'Last name' ).fill( 'Charleston' );
			await field( 'Address' ).fill( '100 Market Street' );
			await field( 'City' ).fill( 'San Francisco' );
			await field( 'ZIP Code' ).fill( '94103' );
			const method = page.getByRole( 'radio', { name: 'Pay by Bank' } );
			await method.waitFor( { timeout: 30000 } );
			await method.check();
			await Promise.all( [ page.waitForURL( /order-pay\/\d+/, { timeout: 60000 } ), page.getByRole( 'button', { name: 'Place Order' } ).click() ] );
		} else {
			await page.getByRole( 'textbox', { name: 'First name' } ).fill( 'Anne' );
			await page.getByRole( 'textbox', { name: 'Last name' } ).fill( 'Charleston' );
			await page.getByRole( 'textbox', { name: 'Street address' } ).fill( '100 Market Street' );
			await page.getByRole( 'textbox', { name: 'Town / City' } ).fill( 'San Francisco' );
			await page.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94103' );
			await page.getByRole( 'textbox', { name: 'Phone' } ).fill( '4155550100' );
			await page.getByRole( 'textbox', { name: 'Email address' } ).fill( 'anne@example.com' );
			await Promise.all( [ page.waitForURL( /order-pay\/\d+/, { timeout: 60000 } ), page.locator( '#place_order' ).click() ] );
		}
		const orderId = ( page.url().match( /order-pay\/(\d+)/ ) || [] )[ 1 ];
		const summary = await page.locator( '.pbfp-payment__summary' ).textContent();
		assert( summary.includes( amount ), 'Payment page shows ' + amount );
		await page.locator( '[data-pbfp-pay]' ).click();
		const link = page.frameLocator( 'iframe[id^="plaid-link-iframe"]' );
		await link.getByRole( 'button', { name: 'Continue without phone number' } ).click( { timeout: 60000 } );
		await link.getByRole( 'textbox', { name: 'Search' } ).fill( 'First Platypus Bank' );
		await link.getByText( 'First Platypus Bank', { exact: false } ).first().click();
		await link.getByText( 'First Platypus Bank', { exact: true } ).last().click();
		await link.getByRole( 'textbox', { name: 'Username' } ).fill( config.username );
		await link.getByRole( 'textbox', { name: 'Password' } ).fill( config.password );
		await link.getByRole( 'button', { name: 'Submit' } ).click();
		await link.getByText( 'Plaid Checking', { exact: false } ).first().click( { timeout: 60000 } );
		await link.getByRole( 'button', { name: 'Continue' } ).click();
		const confirmation = await link.getByText( /transfer/i ).first().textContent( { timeout: 60000 } );
		const shown = ( await link.locator( 'body' ).innerText() ).match( /\$([0-9.,]+) transfer/ );
		assert( shown && shown[ 1 ] === amount, 'Plaid Transfer UI shows the server-side amount $' + amount + ' (got ' + ( shown ? shown[ 1 ] : confirmation ) + ').' );
		await link.getByRole( 'button', { name: 'Confirm' } ).click();
		await link.getByRole( 'button', { name: 'Continue' } ).click( { timeout: 60000 } );
		await page.waitForURL( /order-received\/\d+/, { timeout: 60000 } );
		results[ amount ] = orderId;
	}
	await useCheckout( 'classic' );
	return 'SANDBOX_ORDERS=' + JSON.stringify( results ) + ' SANDBOX_CHECKOUTS=' + JSON.stringify( checkouts );
}
