<?php
/**
 * Fixed-window rate limiting for the MCP and OAuth routes.
 *
 * Two distinct risks are covered:
 *
 * 1. Authenticated MCP traffic — a looping agent can hammer tools/call and
 *    exhaust PHP workers. Counted per WordPress user.
 * 2. Unauthenticated OAuth traffic — /register and /token are public by
 *    specification (RFC 7591/6749), so they are the only endpoints an
 *    anonymous caller can reach. /register in particular is capped at
 *    Store::MAX_CLIENTS, meaning unlimited registration would let anyone fill
 *    the registry and permanently deny new agents. Counted per IP, with a
 *    tighter budget.
 *
 * Counters live in transients so a persistent object cache absorbs them; on a
 * plain install they land in the options table and expire on their own.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	/** Window length in seconds. */
	private const WINDOW = MINUTE_IN_SECONDS;

	/** Requests per window for authenticated MCP calls. */
	public const DEFAULT_LIMIT = 60;

	/** Requests per window for the public OAuth endpoints. */
	private const ANON_LIMIT = 10;

	/**
	 * Seconds the caller should wait, stashed by check() so the header can be
	 * attached after dispatch (WP_Error carries a status, not headers).
	 */
	private static ?int $retry_after = null;

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'check' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'add_retry_header' ), 10, 3 );
	}

	/**
	 * Configured budget for authenticated MCP calls. 0 disables limiting.
	 */
	public static function limit(): int {
		/**
		 * Filters the per-minute request budget for authenticated MCP calls.
		 *
		 * @param int $limit Requests per minute; 0 disables rate limiting.
		 */
		return max( 0, (int) apply_filters( 'flavoursuite/ai/rate_limit', Settings::rate_limit() ) );
	}

	/**
	 * Short-circuits the REST dispatch with a 429 once a bucket is spent.
	 *
	 * @param mixed            $result  Existing short-circuit result, if any.
	 * @param mixed            $server  REST server (unused).
	 * @param WP_REST_Request  $request Incoming request.
	 * @return mixed
	 */
	public static function check( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$route = $request->get_route();
		$is_mcp   = 0 === strpos( $route, '/flavoursuite-ai/mcp' );
		$is_oauth = 0 === strpos( $route, '/' . OAuth\Server::REST_NS );

		if ( ! $is_mcp && ! $is_oauth ) {
			return $result;
		}

		$user_id = get_current_user_id();

		// Anonymous callers (the OAuth endpoints, or an MCP request that failed
		// authentication) are bucketed by IP on the tighter budget.
		if ( $user_id > 0 ) {
			$bucket = 'u' . $user_id;
			$limit  = self::limit();
		} else {
			$bucket = 'i' . self::client_ip();
			$limit  = min( self::ANON_LIMIT, self::limit() > 0 ? self::limit() : self::ANON_LIMIT );
		}

		if ( 0 === $limit ) {
			return $result;
		}

		$now    = time();
		$window = (int) floor( $now / self::WINDOW );
		$key    = 'fs_rl_' . md5( $bucket . '|' . ( $is_oauth ? 'oauth' : 'mcp' ) . '|' . $window );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			self::$retry_after = max( 1, ( ( $window + 1 ) * self::WINDOW ) - $now );

			return new WP_Error(
				'flavoursuite_rate_limited',
				__( 'Too many requests. Slow down and try again shortly.', 'flavoursuite-ai' ),
				array( 'status' => 429 )
			);
		}

		// Two windows of TTL so a counter written at the very end of a window
		// still outlives it rather than expiring early and resetting the budget.
		set_transient( $key, $count + 1, self::WINDOW * 2 );

		return $result;
	}

	/**
	 * Attaches Retry-After to the 429 produced above.
	 *
	 * @param WP_REST_Response $response Dispatch result.
	 * @param mixed            $server   REST server (unused).
	 * @param mixed            $request  Original request (unused).
	 * @return WP_REST_Response
	 */
	public static function add_retry_header( $response, $server, $request ) {
		if ( $response instanceof WP_REST_Response && 429 === $response->get_status() && null !== self::$retry_after ) {
			$response->header( 'Retry-After', (string) self::$retry_after );
		}
		return $response;
	}

	/**
	 * REMOTE_ADDR only. Forwarded-for headers are attacker-controlled, so
	 * trusting them would let a single caller mint unlimited buckets — the
	 * exact thing this limiter exists to prevent. Sites behind a reverse proxy
	 * or CDN should resolve the real client IP themselves via the filter.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		$ip = filter_var( wp_unslash( $ip ), FILTER_VALIDATE_IP );

		/**
		 * Filters the client IP used to bucket unauthenticated requests.
		 *
		 * Only override this when a trusted proxy sits in front of WordPress and
		 * you validate the forwarded header yourself.
		 *
		 * @param string $ip Client IP, or '' when REMOTE_ADDR was unusable.
		 */
		return (string) apply_filters( 'flavoursuite/ai/client_ip', false === $ip ? '' : $ip );
	}
}
