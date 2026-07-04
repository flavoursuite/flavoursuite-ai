<?php
/**
 * OAuth 2.1 authorization-server endpoints for the MCP route, so cloud MCP
 * clients (claude.ai, Claude Desktop connectors, ChatGPT) can connect via
 * their native OAuth flows instead of Application Passwords.
 *
 * Deliberately narrow: one resource (our MCP route), authorization_code +
 * refresh_token grants only, PKCE S256 mandatory, public clients only
 * (RFC 7591 dynamic registration). A bearer token maps to ONE WordPress
 * user; every ability still re-checks that user's capabilities per call —
 * OAuth changes how the user is identified, never what they may do.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\OAuth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Server {

	public const REST_NS = 'flavoursuite-ai/oauth';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );

		// Priority 30: core's Application Password check runs at 20; Bearer
		// only fills the gap when nothing else authenticated the request.
		add_filter( 'determine_current_user', array( self::class, 'authenticate_bearer' ), 30 );

		// 401s on the MCP route advertise where to find the OAuth metadata
		// (RFC 9728 WWW-Authenticate), which is how MCP clients discover us.
		add_filter( 'rest_post_dispatch', array( self::class, 'advertise_oauth' ), 10, 3 );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NS,
			'/metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'metadata' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NS,
			'/resource-metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'resource_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NS,
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'register_client' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NS,
			'/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// ------------------------------------------------------------ Metadata.

	public static function issuer(): string {
		return untrailingslashit( home_url( '/' ) );
	}

	/**
	 * RFC 8414 authorization-server metadata.
	 */
	public static function metadata(): array {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => admin_url( 'profile.php?page=flavoursuite-oauth-authorize' ),
			'token_endpoint'                        => rest_url( self::REST_NS . '/token' ),
			'registration_endpoint'                 => rest_url( self::REST_NS . '/register' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( 'mcp' ),
		);
	}

	/**
	 * RFC 9728 protected-resource metadata.
	 */
	public static function resource_metadata(): array {
		return array(
			'resource'                 => rest_url( 'flavoursuite-ai/mcp' ),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => get_bloginfo( 'name' ) . ' — FlavourSuite AI',
		);
	}

	// ----------------------------------------------- Dynamic registration.

	/**
	 * RFC 7591. Public clients only; redirect URIs must be https (or
	 * localhost for native clients).
	 */
	public static function register_client( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return self::oauth_error( 'invalid_client_metadata', 'JSON body required.' );
		}

		$uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
		$uris = array_values( array_filter( array_map( array( self::class, 'validate_redirect_uri' ), $uris ) ) );
		if ( array() === $uris ) {
			return self::oauth_error( 'invalid_redirect_uri', 'At least one https or localhost redirect_uri is required.' );
		}

		$client = Store::create_client(
			isset( $body['client_name'] ) ? (string) $body['client_name'] : 'MCP client',
			$uris
		);
		if ( null === $client ) {
			return self::oauth_error( 'invalid_client_metadata', 'Client registry is full — revoke unused clients in FlavourSuite AI settings.' );
		}

		return new WP_REST_Response(
			array(
				'client_id'                  => $client['client_id'],
				'client_name'                => $client['name'],
				'redirect_uris'              => $client['redirect_uris'],
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'client_id_issued_at'        => $client['created'],
			),
			201
		);
	}

	/**
	 * @return string|null Normalized URI or null when unacceptable.
	 */
	public static function validate_redirect_uri( $uri ): ?string {
		if ( ! is_string( $uri ) ) {
			return null;
		}
		$parts = wp_parse_url( $uri );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$is_local = in_array( $parts['host'], array( 'localhost', '127.0.0.1', '[::1]' ), true );
		if ( 'https' !== $parts['scheme'] && ! ( 'http' === $parts['scheme'] && $is_local ) ) {
			return null;
		}
		return esc_url_raw( $uri );
	}

	// --------------------------------------------------------------- Token.

	public static function token( WP_REST_Request $request ) {
		$grant = (string) $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant ) {
			return self::token_from_code( $request );
		}
		if ( 'refresh_token' === $grant ) {
			return self::token_from_refresh( $request );
		}

		return self::oauth_error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
	}

	private static function token_from_code( WP_REST_Request $request ) {
		$code     = (string) $request->get_param( 'code' );
		$verifier = (string) $request->get_param( 'code_verifier' );
		$client   = (string) $request->get_param( 'client_id' );

		if ( '' === $code || '' === $verifier || '' === $client ) {
			return self::oauth_error( 'invalid_request', 'code, code_verifier and client_id are required.' );
		}

		// Single-use: consumed even when the rest of validation fails.
		$data = Store::consume_code( $code );
		if ( null === $data || $data['client_id'] !== $client ) {
			return self::oauth_error( 'invalid_grant', 'Unknown or expired authorization code.' );
		}

		$redirect = (string) $request->get_param( 'redirect_uri' );
		if ( '' !== (string) $data['redirect_uri'] && $redirect !== $data['redirect_uri'] ) {
			return self::oauth_error( 'invalid_grant', 'redirect_uri does not match the authorization request.' );
		}

		// PKCE S256: BASE64URL(SHA256(verifier)) must equal the stored challenge.
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( (string) $data['challenge'], $computed ) ) {
			return self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
		}

		return self::token_response( Store::issue_tokens( (int) $data['user'], $client ) );
	}

	private static function token_from_refresh( WP_REST_Request $request ) {
		$refresh = (string) $request->get_param( 'refresh_token' );
		if ( '' === $refresh ) {
			return self::oauth_error( 'invalid_request', 'refresh_token is required.' );
		}

		$issued = Store::rotate_refresh( $refresh );
		if ( null === $issued ) {
			return self::oauth_error( 'invalid_grant', 'Unknown or expired refresh token.' );
		}

		return self::token_response( $issued );
	}

	private static function token_response( array $issued ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'access_token'  => $issued['access_token'],
				'token_type'    => 'Bearer',
				'expires_in'    => $issued['expires_in'],
				'refresh_token' => $issued['refresh_token'],
				'scope'         => 'mcp',
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private static function oauth_error( string $code, string $description ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			'invalid_grant' === $code ? 400 : 400
		);
	}

	// ---------------------------------------------------- Authentication.

	/**
	 * Resolves "Authorization: Bearer …" to a WP user — but ONLY for our own
	 * REST namespace, so this never becomes a site-wide login mechanism.
	 *
	 * @param int|false $user_id Result of earlier authentication filters.
	 * @return int|false
	 */
	public static function authenticate_bearer( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- path match only.
		if ( false === strpos( $uri, 'flavoursuite-ai/' ) ) {
			return $user_id;
		}

		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed below.
		if ( ! preg_match( '/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)$/i', trim( $header ), $matches ) ) {
			return $user_id;
		}

		$token = Store::find_token( $matches[1] );

		return null === $token ? $user_id : (int) $token['user'];
	}

	/**
	 * Adds the RFC 9728 discovery pointer to 401 responses on the MCP route.
	 *
	 * @param WP_REST_Response $result  Dispatch result.
	 * @param mixed            $server  REST server (unused).
	 * @param WP_REST_Request  $request Original request.
	 * @return WP_REST_Response
	 */
	public static function advertise_oauth( $result, $server, $request ) {
		if ( $result instanceof WP_REST_Response
			&& 401 === $result->get_status()
			&& 0 === strpos( $request->get_route(), '/flavoursuite-ai/mcp' )
		) {
			$result->header(
				'WWW-Authenticate',
				'Bearer realm="flavoursuite-ai", resource_metadata="' . esc_url_raw( rest_url( self::REST_NS . '/resource-metadata' ) ) . '"'
			);
		}
		return $result;
	}
}
