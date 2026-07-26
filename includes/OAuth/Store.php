<?php
/**
 * OAuth persistence (M0: options + transients; M1 will move hot paths to
 * custom tables). Tokens and codes are stored ONLY as SHA-256 hashes —
 * a database dump never yields a usable credential.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\OAuth;

defined( 'ABSPATH' ) || exit;

final class Store {

	private const CLIENTS_OPTION = 'flavoursuite_ai_oauth_clients';
	private const TOKENS_OPTION  = 'flavoursuite_ai_oauth_tokens';

	private const MAX_CLIENTS       = 50;
	private const CODE_TTL          = 120;
	private const ACCESS_TTL        = HOUR_IN_SECONDS;
	private const REFRESH_TTL       = 30 * DAY_IN_SECONDS;

	// ------------------------------------------------------------ Clients.

	/**
	 * RFC 7591 dynamic registration. Public clients only (PKCE, no secret).
	 *
	 * @param string       $name          Client display name.
	 * @param list<string> $redirect_uris Validated absolute URIs.
	 * @return array|null Client record, or null when the registry is full.
	 */
	public static function create_client( string $name, array $redirect_uris ): ?array {
		$clients = self::clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			return null;
		}

		$client = array(
			'client_id'     => wp_generate_uuid4(),
			'name'          => sanitize_text_field( $name ),
			'redirect_uris' => array_values( $redirect_uris ),
			'created'       => time(),
		);

		$clients[ $client['client_id'] ] = $client;
		update_option( self::CLIENTS_OPTION, $clients, false );

		return $client;
	}

	public static function get_client( string $client_id ): ?array {
		$clients = self::clients();
		return $clients[ $client_id ] ?? null;
	}

	/**
	 * @return array<string, array>
	 */
	public static function clients(): array {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * Revokes a client: the registration itself plus every token ever issued
	 * to it, so access ends on the next request rather than at token expiry.
	 *
	 * @return bool False when the client was already gone.
	 */
	public static function delete_client( string $client_id ): bool {
		$clients = self::clients();
		if ( ! isset( $clients[ $client_id ] ) ) {
			return false;
		}

		unset( $clients[ $client_id ] );
		update_option( self::CLIENTS_OPTION, $clients, false );

		$tokens  = self::tokens();
		$revoked = false;
		foreach ( $tokens as $hash => $row ) {
			if ( isset( $row['client'] ) && $row['client'] === $client_id ) {
				unset( $tokens[ $hash ] );
				$revoked = true;
			}
		}
		if ( $revoked ) {
			update_option( self::TOKENS_OPTION, $tokens, false );
		}

		return true;
	}

	/**
	 * Live access tokens for a client — lets the settings screen distinguish a
	 * genuinely connected agent from an abandoned registration.
	 */
	public static function active_token_count( string $client_id ): int {
		$now   = time();
		$count = 0;

		foreach ( self::tokens() as $row ) {
			if ( 'access' === ( $row['type'] ?? '' )
				&& ( $row['client'] ?? '' ) === $client_id
				&& ( $row['exp'] ?? 0 ) >= $now
			) {
				++$count;
			}
		}

		return $count;
	}

	// -------------------------------------------------- Authorization codes.

	/**
	 * @param array $data {user, client_id, redirect_uri, challenge}.
	 * @return string The single-use code (only ever returned here).
	 */
	public static function create_code( array $data ): string {
		$code = self::random_token();
		set_transient( 'fs_oauth_code_' . self::hash( $code ), $data, self::CODE_TTL );
		return $code;
	}

	/**
	 * Consumes the code: it is deleted on first read, valid or not.
	 */
	public static function consume_code( string $code ): ?array {
		$key  = 'fs_oauth_code_' . self::hash( $code );
		$data = get_transient( $key );
		delete_transient( $key );
		return is_array( $data ) ? $data : null;
	}

	// -------------------------------------------------------------- Tokens.

	/**
	 * Issues an access + refresh token pair for a user/client grant.
	 *
	 * @return array {access_token, refresh_token, expires_in}
	 */
	public static function issue_tokens( int $user_id, string $client_id ): array {
		$access  = self::random_token();
		$refresh = self::random_token();
		$now     = time();

		$tokens = self::tokens();

		// Opportunistic prune keeps the option bounded without a cron (M0).
		foreach ( $tokens as $hash => $row ) {
			if ( $row['exp'] < $now ) {
				unset( $tokens[ $hash ] );
			}
		}

		$tokens[ self::hash( $access ) ]  = array(
			'type'   => 'access',
			'user'   => $user_id,
			'client' => $client_id,
			'exp'    => $now + self::ACCESS_TTL,
		);
		$tokens[ self::hash( $refresh ) ] = array(
			'type'   => 'refresh',
			'user'   => $user_id,
			'client' => $client_id,
			'exp'    => $now + self::REFRESH_TTL,
		);

		update_option( self::TOKENS_OPTION, $tokens, false );

		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_in'    => self::ACCESS_TTL,
		);
	}

	/**
	 * @param string $type 'access' or 'refresh'.
	 * @return array|null {user, client, exp} when valid and unexpired.
	 */
	public static function find_token( string $token, string $type = 'access' ): ?array {
		$tokens = self::tokens();
		$row    = $tokens[ self::hash( $token ) ] ?? null;

		if ( ! is_array( $row ) || $row['type'] !== $type || $row['exp'] < time() ) {
			return null;
		}

		// A token outlives its client only if something went wrong; treat the
		// client registration as the source of truth so revocation is absolute.
		if ( null === self::get_client( (string) ( $row['client'] ?? '' ) ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Rotation: the presented refresh token is revoked before reissue.
	 */
	public static function rotate_refresh( string $refresh ): ?array {
		$row = self::find_token( $refresh, 'refresh' );
		if ( null === $row ) {
			return null;
		}

		$tokens = self::tokens();
		unset( $tokens[ self::hash( $refresh ) ] );
		update_option( self::TOKENS_OPTION, $tokens, false );

		return self::issue_tokens( (int) $row['user'], (string) $row['client'] );
	}

	// ------------------------------------------------------------- Helpers.

	private static function tokens(): array {
		$tokens = get_option( self::TOKENS_OPTION, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	private static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}

	private static function random_token(): string {
		// 32 random bytes, base64url — matches OAuth token entropy guidance.
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}
}
