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
		define( 'FLAVOURSUITE_AI_VERSION', '0.2.0' );
		define( 'FLAVOURSUITE_AI_DIR', plugin_dir_path( $main_file ) );
		define( 'FLAVOURSUITE_AI_URL', plugin_dir_url( $main_file ) );

		// Jetpack Autoloader: mcp-adapter is shared infrastructure (global hook
		// names, singleton) — the newest copy on the site must win. WooCommerce
		// ships the same package the same way.
		$autoload_packages = FLAVOURSUITE_AI_DIR . 'vendor/autoload_packages.php';
		if ( is_readable( $autoload_packages ) ) {
			require_once $autoload_packages;
		} elseif ( is_readable( FLAVOURSUITE_AI_DIR . 'vendor/autoload.php' ) ) {
			require_once FLAVOURSUITE_AI_DIR . 'vendor/autoload.php';
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
		Approvals\ChangeRequests::register();
		Approvals\Abilities::register();
		Approvals\AdminPage::register();
		Settings::register();
		Mcp::register();

		// OAuth endpoints share the master switch: no MCP route, no issuer.
		if ( Settings::is_enabled() ) {
			OAuth\Server::register();
			OAuth\Consent::register();
		}

		// Priority 20: every plugin has loaded, so vendor detection is reliable.
		add_action( 'plugins_loaded', array( Integrations\IntegrationRegistry::class, 'boot' ), 20 );

		do_action( 'flavoursuite/ai/ready' );
	}

	public static function requires_wp_69_notice(): void {
		wp_admin_notice(
			__( 'FlavourSuite AI requires WordPress 6.9 or newer (Abilities API).', 'flavoursuite-ai' ),
			array( 'type' => 'error' )
		);
	}
}
