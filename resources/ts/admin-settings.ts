/** Copy-to-clipboard for the Buckmerce webhook URL on the gateway settings screen. */

interface AdminStrings {
	copied?: string;
	copyFailed?: string;
}

declare global {
	interface Window {
		buckmercePlaidAdmin?: AdminStrings;
	}
}

( function (): void {
	const strings = window.buckmercePlaidAdmin || {};
	const status = document.querySelector< HTMLElement >( '[data-bmfp-copy-status]' );
	const announce = ( text: string | undefined ): void => {
		if ( status && text ) {
			status.textContent = text;
		}
	};
	const fallback = ( input: HTMLInputElement ): void => {
		input.focus();
		input.select();
		announce( strings.copyFailed );
	};
	document.querySelectorAll< HTMLButtonElement >( '[data-bmfp-copy]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const target = document.getElementById( button.dataset.bmfpCopy || '' );
			if ( ! ( target instanceof HTMLInputElement ) ) {
				return;
			}
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( target.value ).then(
					() => announce( strings.copied ),
					() => fallback( target )
				);
				return;
			}
			fallback( target );
		} );
	} );
} )();

export {};
