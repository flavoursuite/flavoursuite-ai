<?php
/**
 * Well-known discovery documents at the site root.
 *
 * Server::metadata() and Server::resource_metadata() already produce the RFC
 * 8414 / RFC 9728 payloads, but they live under our REST namespace — a URL no
 * client can guess. MCP clients (claude.ai, ChatGPT, Claude Desktop) probe
 * https://example.com/.well-known/oauth-protected-resource first and only fall
 * back to the WWW-Authenticate hint. Without these paths the OAuth flow is
 * effectively undiscoverable.
 *
 * Served from parse_request rather than register_rest_route because /.well-known
 * is outside the REST namespace, and via a rewrite-free path match so no
 * permalink flush is required on activation.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\OAuth;

defined( 'ABSPATH' ) || exit;

final class Discovery {

	private const AUTH_SERVER        = '.well-known/oauth-authorization-server';
	private const PROTECTED_RESOURCE = '.well-known/oauth-protected-resource';

	public static function register(): void {
		add_action( 'parse_request', array( self::class, 'maybe_serve' ) );
	}

	/**
	 * Emits the matching metadata document and exits, or returns silently so
	 * WordPress continues its normal 404 handling.
	 */
	public static function maybe_serve(): void {
		$path = self::request_path();

		if ( 0 === strpos( $path, self::AUTH_SERVER ) ) {
			self::send( Server::metadata() );
		}

		if ( 0 === strpos( $path, self::PROTECTED_RESOURCE ) ) {
			self::send( Server::resource_metadata() );
		}
	}

	/**
	 * Request path relative to the WordPress home path, without surrounding
	 * slashes. Derived from REQUEST_URI rather than $wp->request so it works
	 * under plain permalinks too, where $wp->request is not populated.
	 *
	 * RFC 8414 places the metadata for a subdirectory issuer at
	 * /.well-known/oauth-authorization-server/subdir — that form does not start
	 * with the home path, so it survives the prefix strip below and still
	 * matches via strpos().
	 */
	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- path match only, never echoed.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return trim( $path, '/' );
	}

	/**
	 * These documents are public by specification and carry no credentials, so
	 * they are safe to expose cross-origin — browser-based MCP clients need
	 * that to read them at all.
	 *
	 * @param array $document Metadata payload.
	 */
	private static function send( array $document ): void {
		if ( ! headers_sent() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Cache-Control: public, max-age=3600' );
		}

		// Sets the JSON content type, prints, and exits.
		wp_send_json( $document );
	}
}
