<?php
/**
 * Boot lifecycle: constants, autoload, deferred kernel.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {

	/**
	 * Called from the main plugin file. Cheap work only — the kernel is
	 * deferred to plugins_loaded so nothing heavy runs at file load.
	 */
	public static function boot( string $main_file ): void {
		define( 'FLAVOURSUITE_AI_VERSION', '0.1.0' );
		define( 'FLAVOURSUITE_AI_DIR', plugin_dir_path( $main_file ) );
		define( 'FLAVOURSUITE_AI_URL', plugin_dir_url( $main_file ) );

		$autoload = FLAVOURSUITE_AI_DIR . 'vendor/autoload.php';
		if ( is_readable( $autoload ) ) {
			require_once $autoload;
		}

		add_action( 'plugins_loaded', array( self::class, 'boot_kernel' ), 5 );
	}

	public static function boot_kernel(): void {
		// Abilities API landed in core in WP 6.9; without it there is nothing to expose.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action( 'admin_notices', array( self::class, 'requires_wp_69_notice' ) );
			return;
		}

		Abilities::register();
		Mcp::register();

		do_action( 'flavoursuite/ai/ready' );
	}

	public static function requires_wp_69_notice(): void {
		wp_admin_notice(
			__( 'FlavourSuite AI requires WordPress 6.9 or newer (Abilities API).', 'flavoursuite-ai' ),
			array( 'type' => 'error' )
		);
	}
}
