/**
 * Settings → FlavourSuite AI: connection-recipe builder.
 *
 * Renders the config block for whichever agent is selected, substituting the
 * endpoint URL and an Authorization header built from the username and
 * application password. The Basic credential is assembled here in the browser
 * and never sent to the server or stored.
 */
( function () {
	'use strict';

	var select = document.getElementById( 'fs-client' );
	if ( ! select ) {
		return;
	}

	var profiles = JSON.parse( select.getAttribute( 'data-profiles' ) || '[]' );
	var endpoint = select.getAttribute( 'data-endpoint' ) || '';

	var snippet     = document.getElementById( 'fs-snippet' );
	var noteEl      = document.getElementById( 'fs-client-note' );
	var fileLine    = document.getElementById( 'fs-file-line' );
	var fileValue   = document.getElementById( 'fs-file-value' );
	var credentials = document.getElementById( 'fs-credentials' );
	var errorEl     = document.getElementById( 'fs-token-error' );

	var PLACEHOLDER = 'Basic <base64 of username:application-password>';

	// Retained across profile switches so changing agent re-renders with the
	// credential already built, rather than dropping back to the placeholder.
	var authHeader = '';

	function current() {
		for ( var i = 0; i < profiles.length; i++ ) {
			if ( profiles[ i ].id === select.value ) {
				return profiles[ i ];
			}
		}
		return profiles[ 0 ];
	}

	function basicAuth( user, pass ) {
		var bytes = new TextEncoder().encode( user + ':' + pass );
		var bin   = '';
		bytes.forEach( function ( b ) {
			bin += String.fromCharCode( b );
		} );
		return 'Basic ' + btoa( bin );
	}

	function render() {
		var profile = current();
		if ( ! profile ) {
			return;
		}

		// Cloud clients run the OAuth flow instead of carrying a header, so
		// there is no credential to collect or show.
		var needsAuth = 'oauth' !== profile.auth;

		credentials.style.display = needsAuth ? '' : 'none';
		noteEl.textContent        = profile.note || '';

		// Clear as well as hide: leaving the previous profile's path in the DOM
		// would resurface it the moment this line is ever shown unconditionally.
		fileValue.textContent  = profile.file || '';
		fileLine.style.display = profile.file ? '' : 'none';

		snippet.textContent = profile.template
			.split( '__URL__' ).join( endpoint )
			.split( '__AUTH__' ).join( authHeader || PLACEHOLDER );
	}

	select.addEventListener( 'change', render );

	document.getElementById( 'fs-token-generate' ).addEventListener( 'click', function () {
		var user = document.getElementById( 'fs-token-user' ).value.trim();
		var pass = document.getElementById( 'fs-token-pass' ).value.trim();

		if ( ! user || ! pass ) {
			errorEl.style.display = '';
			return;
		}

		errorEl.style.display = 'none';
		authHeader            = basicAuth( user, pass );
		render();
	} );

	render();
} )();
