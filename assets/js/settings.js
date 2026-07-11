/**
 * Settings → FlavourSuite AI: connection-snippet builder.
 *
 * Builds the Basic auth header from username + application password
 * entirely in the browser — the credentials are never sent or saved.
 */
( function () {
	'use strict';

	var btn = document.getElementById( 'fs-token-generate' );
	if ( ! btn ) {
		return;
	}

	btn.addEventListener( 'click', function () {
		var user = document.getElementById( 'fs-token-user' ).value.trim();
		var pass = document.getElementById( 'fs-token-pass' ).value.trim();
		var err  = document.getElementById( 'fs-token-error' );

		if ( ! user || ! pass ) {
			err.style.display = '';
			return;
		}
		err.style.display = 'none';

		var bytes = new TextEncoder().encode( user + ':' + pass );
		var bin   = '';
		bytes.forEach( function ( b ) {
			bin += String.fromCharCode( b );
		} );
		var auth = 'Basic ' + btoa( bin );

		document.getElementById( 'fs-auth-value' ).textContent    = auth;
		document.getElementById( 'fs-auth-line' ).style.display   = '';

		var snippet = document.getElementById( 'fs-snippet' );
		snippet.textContent = snippet.getAttribute( 'data-template' ).replace( '__AUTH__', auth );
	} );
} )();
