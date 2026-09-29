/**
 * PayBridge payment page: launches Plaid Transfer UI for the order's stored
 * Transfer Intent and asks the server to verify the result.
 *
 * The browser never decides the amount, the Transfer Intent or whether the
 * payment succeeded: it only requests a Link token for its order and, after
 * Link onSuccess, asks the server to check Plaid (/transfer/intent/get).
 */

interface PaymentConfig {
	orderId: number;
	orderKey: string;
	paymentNonce: string;
	restNonce: string;
	linkTokenUrl: string;
	completeUrl: string;
	returnUrl: string;
	i18n: Record< string, string >;
}

interface LinkHandler {
	open: () => void;
	exit: ( options?: Record< string, unknown > ) => void;
	destroy: () => void;
}

interface PlaidGlobal {
	create: ( config: {
		token: string;
		onSuccess: ( publicToken: string, metadata: Record< string, unknown > ) => void;
		onExit: ( error: Record< string, unknown > | null, metadata: Record< string, unknown > ) => void;
		onEvent?: ( eventName: string, metadata: Record< string, unknown > ) => void;
	} ) => LinkHandler;
}

interface ServerResponse {
	status?: string;
	link_token?: string;
	redirect?: string;
	reason?: string;
	code?: string;
	message?: string;
}

declare global {
	interface Window {
		paybridgePlaidPayment?: PaymentConfig;
		Plaid?: PlaidGlobal;
	}
}

const MAX_VERIFY_ATTEMPTS = 6;
const VERIFY_DELAY_MS = 4000;

( function (): void {
	const config = window.paybridgePlaidPayment;
	const root = document.querySelector< HTMLElement >( '.pbfp-payment' );
	if ( ! config || ! root ) {
		return;
	}
	const button = root.querySelector< HTMLButtonElement >( '[data-pbfp-pay]' );
	const status = root.querySelector< HTMLElement >( '[data-pbfp-status]' );
	if ( ! button || ! status ) {
		return;
	}
	const idleLabel = button.textContent || '';
	let busy = false;
	let handler: LinkHandler | null = null;

	const message = ( key: string ): string => config.i18n[ key ] || '';

	const setStatus = ( key: string, isError = false ): void => {
		status.textContent = message( key );
		status.classList.toggle( 'pbfp-payment__status--error', isError );
		root.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	};

	const setBusy = ( value: boolean ): void => {
		busy = value;
		button.disabled = value;
		root.setAttribute( 'aria-busy', value ? 'true' : 'false' );
	};

	const allowRetry = ( key: string ): void => {
		setBusy( false );
		button.textContent = message( 'retry' ) || idleLabel;
		setStatus( key, true );
		button.focus();
	};

	const post = async ( url: string ): Promise< ServerResponse > => {
		const response = await fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: config.restNonce
				? { 'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce }
				: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				order_id: config.orderId,
				order_key: config.orderKey,
				payment_nonce: config.paymentNonce,
			} ),
		} );
		let body: ServerResponse = {};
		try {
			body = ( await response.json() ) as ServerResponse;
		} catch ( error ) {
			body = {};
		}
		if ( ! response.ok ) {
			throw new Error( body.code || String( response.status ) );
		}
		return body;
	};

	const redirect = ( url?: string ): void => {
		setStatus( 'submitted' );
		window.location.assign( url || config.returnUrl );
	};

	const verify = async ( attempt: number ): Promise< void > => {
		setStatus( 'verifying' );
		let result: ServerResponse;
		try {
			result = await post( config.completeUrl );
		} catch ( error ) {
			result = { status: 'unverified' };
		}
		switch ( result.status ) {
			case 'submitted':
				redirect( result.redirect );
				return;
			case 'failed':
				allowRetry( 'failed' );
				return;
			case 'incomplete':
				if ( 'MANUAL_REVIEW' === result.reason ) {
					setBusy( true );
					setStatus( 'review', true );
					return;
				}
				allowRetry( 'NSF' === result.reason ? 'insufficient' : 'incomplete' );
				return;
			default:
				if ( attempt + 1 < MAX_VERIFY_ATTEMPTS ) {
					setStatus( 'unverified' );
					window.setTimeout( () => {
						void verify( attempt + 1 );
					}, VERIFY_DELAY_MS );
					return;
				}
				allowRetry( 'error' );
		}
	};

	const start = async (): Promise< void > => {
		if ( busy ) {
			return;
		}
		if ( ! window.Plaid ) {
			allowRetry( 'unavailable' );
			return;
		}
		setBusy( true );
		setStatus( 'preparing' );
		let result: ServerResponse;
		try {
			result = await post( config.linkTokenUrl );
		} catch ( error ) {
			allowRetry( 'error' );
			return;
		}
		if ( 'submitted' === result.status ) {
			redirect( result.redirect );
			return;
		}
		if ( 'ready' !== result.status || ! result.link_token ) {
			allowRetry( 'error' );
			return;
		}
		if ( handler ) {
			handler.destroy();
		}
		handler = window.Plaid.create( {
			token: result.link_token,
			onSuccess: () => {
				// The public token and metadata are intentionally not sent: the server checks Plaid directly.
				void verify( 0 );
			},
			onExit: ( error ) => {
				// Transfer UI may exit after a declined attempt; the server decides.
				if ( error ) {
					allowRetry( 'exited' );
					return;
				}
				void verify( MAX_VERIFY_ATTEMPTS - 1 ).then( () => undefined );
			},
		} );
		setStatus( 'opening' );
		handler.open();
	};

	button.addEventListener( 'click', () => {
		void start();
	} );
	setBusy( false );
	status.textContent = '';
} )();

export {};
