const fs = require( 'node:fs' );
const http = require( 'node:http' );
const https = require( 'node:https' );

const [ backendPortValue, listenPortValue, certificatePath, keyPath, rewriteFrom = '', rewriteTo = '' ] = process.argv.slice( 2 );
const backendPort = Number.parseInt( backendPortValue || '', 10 );
const listenPort = Number.parseInt( listenPortValue || '', 10 );

if ( ! Number.isInteger( backendPort ) || ! Number.isInteger( listenPort ) || ! certificatePath || ! keyPath ) {
	throw new Error( 'Usage: node https-reverse-proxy.js <backend-port> <listen-port> <certificate> <key> [rewrite-from] [rewrite-to]' );
}

const server = https.createServer(
	{
		cert: fs.readFileSync( certificatePath ),
		key: fs.readFileSync( keyPath ),
	},
	( request, response ) => {
		const forwarded = http.request(
			{
				hostname: '127.0.0.1',
				port: backendPort,
				method: request.method,
				path: request.url,
				headers: {
					...request.headers,
					'x-forwarded-proto': 'https',
					'x-forwarded-host': request.headers.host || '',
				},
			},
			upstream => {
				const headers = { ...upstream.headers };
				const isPayKassaBrowserReturn = /[?&]wc-api=wc_gateway_paykassa_(?:return|cancel)(?:&|$)/.test( request.url || '' );
				if (
					rewriteFrom &&
					rewriteTo &&
					! isPayKassaBrowserReturn &&
					typeof headers.location === 'string'
				) {
					headers.location = headers.location.split( rewriteFrom ).join( rewriteTo );
				}
				const contentType = String( upstream.headers[ 'content-type' ] || '' ).toLowerCase();
				const contentEncoding = String( upstream.headers[ 'content-encoding' ] || '' ).toLowerCase();
				const rewriteBody = !! rewriteFrom && !! rewriteTo && ! contentEncoding && (
					contentType.startsWith( 'text/' ) ||
					contentType.includes( 'json' ) ||
					contentType.includes( 'javascript' ) ||
					contentType.includes( 'xml' )
				);
				if ( ! rewriteBody ) {
					response.writeHead( upstream.statusCode || 502, headers );
					upstream.pipe( response );
					return;
				}

				const chunks = [];
				upstream.on( 'data', chunk => chunks.push( chunk ) );
				upstream.on( 'end', () => {
					const body = Buffer.concat( chunks ).toString( 'utf8' ).split( rewriteFrom ).join( rewriteTo );
					delete headers[ 'content-length' ];
					response.writeHead( upstream.statusCode || 502, headers );
					response.end( body );
				} );
			}
		);
		forwarded.on( 'error', () => {
			if ( ! response.headersSent ) {
				response.writeHead( 502, { 'content-type': 'text/plain; charset=utf-8' } );
			}
			response.end( 'Proxy error' );
		} );
		request.pipe( forwarded );
	}
);

server.listen( listenPort, '0.0.0.0' );
