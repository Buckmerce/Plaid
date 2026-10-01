/**
 * Buckmerce payment page: launches Plaid Transfer UI for the order's stored
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
		buckmercePlaidPayment?: PaymentConfig;
		Plaid?: PlaidGlobal;
	}
}

const MAX_VERIFY_ATTEMPTS = 6;
const VERIFY_DELAY_MS = 4000;

( function (): void {
	const config = window.buckmercePlaidPayment;
	const root = document.querySelector< HTMLElement >( '.bmfp-payment' );
	if ( ! config || ! root ) {
		return;
	}
	const button = root.querySelector< HTMLButtonElement >( '[data-bmfp-pay]' );
	const status = root.querySelector< HTMLElement >( '[data-bmfp-status]' );
	if ( ! button || ! status ) {
		return;
	}
	const idleLabel = button.textContent || '';
	let busy = false;
	let handler: LinkHandler | null = null;

	const message = ( key: string ): string => config.i18n[ key ] || '';

	const setStatus = ( key: string, isError = false ): void => {
		status.textContent = message( key );
		status.classList.toggle( 'bmfp-payment__status--error', isError );
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
		// Return focus to the action after Plaid's window closed or an error was announced.
		button.focus();
	};

	/** A payment that cannot continue from this page: announce why and keep the button disabled. */
	const stop = ( key: string ): void => {
		setBusy( true );
		button.setAttribute( 'aria-disabled', 'true' );
		setStatus( key, true );
	};

	/** Server error codes that deserve a specific message. */
	const errorKey = ( error: unknown ): string => {
		const code = error instanceof Error ? error.message : '';
		switch ( code ) {
			case 'buckmerce_unavailable':
				return 'notPayable';
			case 'buckmerce_not_payable':
				return 'returned';
			case 'buckmerce_rate_limited':
				return 'rateLimited';
			case 'buckmerce_missing_name':
				return 'missingName';
			default:
				return 'error';
		}
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

	/**
	 * Asks the server whether Plaid created a transfer. $exited: the customer closed Plaid Link
	 * without an error (Plaid then calls onExit(null)); an incomplete result is reported as such.
	 */
	const verify = async ( attempt: number, exited = false ): Promise< void > => {
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
				if ( 'NSF' === result.reason ) {
					allowRetry( 'insufficient' );
					return;
				}
				allowRetry( exited ? 'exited' : 'incomplete' );
				return;
			default:
				if ( attempt + 1 < MAX_VERIFY_ATTEMPTS ) {
					setStatus( 'unverified' );
					window.setTimeout( () => {
						void verify( attempt + 1, exited );
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
			const key = errorKey( error );
			if ( 'missingName' === key || 'returned' === key ) {
				stop( key );
				return;
			}
			allowRetry( key );
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
				// The customer closed Link: confirm with the server (a transfer may exist), then explain.
				void verify( MAX_VERIFY_ATTEMPTS - 1, true ).then( () => undefined );
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
