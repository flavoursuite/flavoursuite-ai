<?php
/**
 * Debug-only logging.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

defined( 'ABSPATH' ) || exit;

final class Log {

	/**
	 * Writes to the PHP error log only when WP_DEBUG is on, so production
	 * sites stay silent. Single call site for the development-functions sniff.
	 */
	public static function debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'FlavourSuite AI: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-guarded.
		}
	}
}
