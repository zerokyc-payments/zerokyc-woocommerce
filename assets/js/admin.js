/**
 * "Test connection" button on the gateway settings page (WooCommerce →
 * Settings → Payments → ZeroKYC Pay). Receives ajaxUrl and nonce via the
 * inline `zerokycPayAdmin` object added next to this script server-side.
 *
 * @package zerokyc-pay
 */

( function () {
	'use strict';

	var config = window.zerokycPayAdmin || {};
	var button = document.getElementById( 'zerokyc-ping' );
	if ( ! button || ! config.ajaxUrl ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var result = document.getElementById( 'zerokyc-ping-result' );
		if ( ! result ) {
			return;
		}
		result.textContent = '…';

		var body = new window.FormData();
		body.append( 'action', 'zerokyc_ping' );
		body.append( 'nonce', config.nonce );

		window
			.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				var data = json && json.data ? json.data : {};
				if ( json && json.success ) {
					result.textContent = data.environment + ': OK' + ( data.chain_mode ? ' (' + data.chain_mode + ')' : '' );
				} else {
					result.textContent = 'Failed: ' + ( data.message || 'unknown error' );
				}
			} )
			.catch( function () {
				result.textContent = 'Request failed';
			} );
	} );
} )();
