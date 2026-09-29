async page => {
	// Real Plaid Sandbox: drives the genuine Plaid Transfer UI (no test doubles).
	const baseUrl = page.url().replace( /\/\?.*$/, '' ).replace( /\/$/, '' );
	const config = JSON.parse( decodeURIComponent( ( page.url().match( /[?&]pbfp_sandbox=([^&]+)/ ) || [] )[ 1 ] || '%7B%7D' ) );
	const assert = ( condition, message ) => {
		if ( ! condition ) {
			throw new Error( 'SANDBOX ASSERTION FAILED: ' + message );
		}
	};
	assert( config.checkout && config.products && config.username && config.password, 'Sandbox configuration is missing.' );
	const results = {};
	for ( const [ amount, productId ] of Object.entries( config.products ) ) {
		await page.context().clearCookies();
		await page.goto( baseUrl + '/?add-to-cart=' + productId, { waitUntil: 'domcontentloaded' } );
		await page.goto( config.checkout, { waitUntil: 'networkidle' } );
		await page.getByRole( 'textbox', { name: 'First name' } ).fill( 'Anne' );
		await page.getByRole( 'textbox', { name: 'Last name' } ).fill( 'Charleston' );
		await page.getByRole( 'textbox', { name: 'Street address' } ).fill( '100 Market Street' );
		await page.getByRole( 'textbox', { name: 'Town / City' } ).fill( 'San Francisco' );
		await page.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94103' );
		await page.getByRole( 'textbox', { name: 'Phone' } ).fill( '4155550100' );
		await page.getByRole( 'textbox', { name: 'Email address' } ).fill( 'anne@example.com' );
		await Promise.all( [ page.waitForURL( /order-pay\/\d+/, { timeout: 60000 } ), page.locator( '#place_order' ).click() ] );
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
	return 'SANDBOX_ORDERS=' + JSON.stringify( results );
}
