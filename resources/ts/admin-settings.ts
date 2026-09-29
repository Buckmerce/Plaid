/** Copy-to-clipboard for the PayBridge webhook URL on the gateway settings screen. */

interface AdminStrings {
	copied?: string;
	copyFailed?: string;
}

declare global {
	interface Window {
		paybridgePlaidAdmin?: AdminStrings;
	}
}

( function (): void {
	const strings = window.paybridgePlaidAdmin || {};
	const status = document.querySelector< HTMLElement >( '[data-pbfp-copy-status]' );
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
	document.querySelectorAll< HTMLButtonElement >( '[data-pbfp-copy]' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const target = document.getElementById( button.dataset.pbfpCopy || '' );
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
