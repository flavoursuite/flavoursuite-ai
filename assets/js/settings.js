/**
 * Settings → FlavourSuite AI: connection-recipe builder.
 *
 * Renders the config block for whichever agent is selected, substituting the
 * endpoint URL and an Authorization header built from either a connection token
 * (Bearer) or a WordPress application password (Basic). The credential is
 * assembled here in the browser and never sent to the server or stored.
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

	var tokenPane = document.getElementById( 'fs-auth-token' );
	var basicPane = document.getElementById( 'fs-auth-basic' );
	var tokenIn   = document.getElementById( 'fs-token-value' );
	var userIn    = document.getElementById( 'fs-token-user' );
	var passIn    = document.getElementById( 'fs-token-pass' );
	var modes     = document.querySelectorAll( 'input[name="fs-auth-mode"]' );

	var PLACEHOLDER = {
		token: 'Bearer <connection token>',
		basic: 'Basic <base64 of username:application-password>'
	};

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

	function mode() {
		for ( var i = 0; i < modes.length; i++ ) {
			if ( modes[ i ].checked ) {
				return modes[ i ].value;
			}
		}
		return 'token';
	}

	function basicAuth( user, pass ) {
		var bytes = new TextEncoder().encode( user + ':' + pass );
		var bin   = '';
		bytes.forEach( function ( b ) {
			bin += String.fromCharCode( b );
		} );
		return 'Basic ' + btoa( bin );
	}

	/**
	 * @return {string} Header value, or '' when the fields are incomplete.
	 */
	function buildHeader() {
		if ( 'token' === mode() ) {
			var token = tokenIn.value.trim();
			return token ? 'Bearer ' + token : '';
		}

		var user = userIn.value.trim();
		var pass = passIn.value.trim();

		return user && pass ? basicAuth( user, pass ) : '';
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
			.split( '__AUTH__' ).join( authHeader || PLACEHOLDER[ mode() ] );
	}

	select.addEventListener( 'change', render );

	for ( var i = 0; i < modes.length; i++ ) {
		modes[ i ].addEventListener( 'change', function () {
			var isToken = 'token' === mode();

			tokenPane.style.display = isToken ? '' : 'none';
			basicPane.style.display = isToken ? 'none' : '';
			errorEl.style.display   = 'none';

			// Drop any header built in the other mode: leaving a stale Basic
			// value on screen under a "connection token" heading would be a
			// straightforwardly wrong instruction.
			authHeader = '';
			render();
		} );
	}

	document.getElementById( 'fs-token-generate' ).addEventListener( 'click', function () {
		var header = buildHeader();

		if ( ! header ) {
			errorEl.textContent   = errorEl.getAttribute( 'data-' + mode() ) || '';
			errorEl.style.display = '';
			return;
		}

		errorEl.style.display = 'none';
		authHeader            = header;
		render();
	} );

	// A token minted on the previous request arrives prefilled; build the recipe
	// straight away so the page lands ready to copy.
	if ( tokenIn && tokenIn.value.trim() ) {
		authHeader = buildHeader();
	}

	// One-shot display of a new token: clicking it selects the whole value,
	// because a partial copy fails in a way that is tedious to diagnose.
	var newToken = document.getElementById( 'fs-new-token' );
	if ( newToken ) {
		newToken.addEventListener( 'focus', function () {
			newToken.select();
		} );
	}

	render();
} )();
