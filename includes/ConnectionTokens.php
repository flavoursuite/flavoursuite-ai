<?php
/**
 * Named, revocable bearer tokens scoped to the MCP route.
 *
 * These exist because an Application Password is a blunt instrument: it
 * authenticates its user against the *entire* REST API, so handing one to an
 * agent grants far more than MCP access. A connection token authenticates the
 * same user but is refused everywhere except /flavoursuite-ai/mcp, which makes
 * the blast radius of a leaked agent credential the tool list rather than the
 * whole site.
 *
 * Tokens are stored only as SHA-256 hashes and shown to the user exactly once,
 * at creation — a database dump never yields a usable credential.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

defined( 'ABSPATH' ) || exit;

final class ConnectionTokens {

	private const OPTION = 'flavoursuite_ai_connection_tokens';

	/**
	 * Marks a token as ours on sight, so this and the OAuth bearer check can
	 * share the determine_current_user filter without either doing a pointless
	 * lookup on the other's credentials.
	 */
	private const PREFIX = 'fsai_';

	private const MAX_TOKENS = 50;

	/** Bound how often a request writes to the options table. */
	private const TOUCH_INTERVAL = 5 * MINUTE_IN_SECONDS;

	public static function register(): void {
		// Priority 30 matches core's Application Password check at 20: bearer
		// auth only fills the gap when nothing else authenticated the request.
		add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 30 );
	}

	// -------------------------------------------------------------- Lifecycle.

	/**
	 * @param string $label    Human label, shown in settings.
	 * @param int    $user_id  The WordPress user the agent will act as.
	 * @param int    $ttl_days Days until expiry; 0 for no expiry.
	 * @return string|null The plaintext token — returned here and never again.
	 */
	public static function create( string $label, int $user_id, int $ttl_days = 0 ): ?string {
		$tokens = self::tokens();
		if ( count( $tokens ) >= self::MAX_TOKENS ) {
			return null;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return null;
		}

		$plain = self::PREFIX . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );

		$tokens[ self::hash( $plain ) ] = array(
			'id'        => wp_generate_uuid4(),
			'label'     => sanitize_text_field( '' === trim( $label ) ? __( 'Agent', 'flavoursuite-ai' ) : $label ),
			'user'      => $user_id,
			'created'   => time(),
			'last_used' => 0,
			'expires'   => $ttl_days > 0 ? time() + ( $ttl_days * DAY_IN_SECONDS ) : 0,
		);

		self::save( $tokens );

		return $plain;
	}

	/**
	 * @return bool False when the token was already gone.
	 */
	public static function delete( string $id ): bool {
		$tokens = self::tokens();

		foreach ( $tokens as $hash => $row ) {
			if ( ( $row['id'] ?? '' ) === $id ) {
				unset( $tokens[ $hash ] );
				self::save( $tokens );
				return true;
			}
		}

		return false;
	}

	/**
	 * Newest first, for the settings table. Hashes are stripped — nothing that
	 * reaches a template should carry credential material.
	 *
	 * @return list<array{id:string,label:string,user:int,created:int,last_used:int,expires:int}>
	 */
	public static function all(): array {
		$rows = array_values( self::tokens() );

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return (int) ( $b['created'] ?? 0 ) <=> (int) ( $a['created'] ?? 0 );
			}
		);

		return $rows;
	}

	// --------------------------------------------------------- Authentication.

	/**
	 * Resolves "Authorization: Bearer fsai_…" to a WordPress user, but only on
	 * the MCP route — that restriction is the entire point of this class.
	 *
	 * @param int|false $user_id Result of earlier authentication filters.
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- path match only.
		if ( false === strpos( $uri, 'flavoursuite-ai/mcp' ) ) {
			return $user_id;
		}

		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] )
			? (string) $_SERVER['HTTP_AUTHORIZATION']
			: ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed below.

		if ( ! preg_match( '/^Bearer\s+(' . self::PREFIX . '[A-Za-z0-9_-]+)$/i', trim( $header ), $matches ) ) {
			return $user_id;
		}

		$hash   = self::hash( $matches[1] );
		$tokens = self::tokens();
		$row    = $tokens[ $hash ] ?? null;

		if ( ! is_array( $row ) ) {
			return $user_id;
		}

		if ( $row['expires'] > 0 && $row['expires'] < time() ) {
			return $user_id;
		}

		// A token must never outlive the account it acts as.
		if ( ! get_user_by( 'id', (int) $row['user'] ) ) {
			return $user_id;
		}

		self::touch( $hash, $tokens );

		return (int) $row['user'];
	}

	// ----------------------------------------------------------------- Helpers.

	/**
	 * Records last use, but at most once per TOUCH_INTERVAL: an agent can make
	 * hundreds of calls a minute and every write here would be an options-table
	 * write on the request path.
	 *
	 * @param array<string, array> $tokens Already-loaded token set.
	 */
	private static function touch( string $hash, array $tokens ): void {
		$now = time();

		if ( ( $now - (int) ( $tokens[ $hash ]['last_used'] ?? 0 ) ) < self::TOUCH_INTERVAL ) {
			return;
		}

		$tokens[ $hash ]['last_used'] = $now;
		self::save( $tokens );
	}

	/**
	 * @return array<string, array>
	 */
	private static function tokens(): array {
		$tokens = get_option( self::OPTION, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * @param array<string, array> $tokens Token set to persist.
	 */
	private static function save( array $tokens ): void {
		// Never autoload: read on the settings screen and on MCP requests only.
		update_option( self::OPTION, $tokens, false );
	}

	private static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}
}
