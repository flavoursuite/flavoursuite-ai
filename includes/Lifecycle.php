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
		define( 'FLAVOURSUITE_AI_VERSION', '0.3.0' );
		define( 'FLAVOURSUITE_AI_DIR', plugin_dir_path( $main_file ) );
		define( 'FLAVOURSUITE_AI_URL', plugin_dir_url( $main_file ) );

		// Our own classes first, resolved straight from the filesystem.
		//
		// The Jetpack classmap below is compiled at composer time, so a class
		// added under includes/ without a subsequent `composer dump-autoload`
		// is simply absent from it and fatals on boot. FlavourSuite\Ai\* is
		// never shared with another plugin, so it needs none of Jetpack's
		// version arbitration — a direct PSR-4 lookup is both correct and
		// immune to a stale map.
		self::register_autoloader();

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

	/**
	 * PSR-4 loader for this plugin's own namespace.
	 *
	 * Prepended so it always answers before the Jetpack classmap: our classes
	 * live in exactly one place, and resolving them from disk means adding a
	 * file never requires regenerating anything.
	 */
	private static function register_autoloader(): void {
		spl_autoload_register(
			static function ( $class ): void {
				$prefix = 'FlavourSuite\\Ai\\';

				if ( 0 !== strpos( (string) $class, $prefix ) ) {
					return;
				}

				$relative = substr( (string) $class, strlen( $prefix ) );

				// Class names cannot contain traversal sequences, but the input
				// reaches us from a global hook — validate rather than assume.
				if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
					return;
				}

				$path = FLAVOURSUITE_AI_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $path ) ) {
					require_once $path;
				}
			},
			true,
			true
		);
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
		// Outside the master switch: the trail stays exportable after the
		// server is turned off, which is when an audit is most likely.
		AuditLog::register();
		Mcp::register();

		// OAuth endpoints share the master switch: no MCP route, no issuer.
		if ( Settings::is_enabled() ) {
			OAuth\Server::register();
			OAuth\Consent::register();
			OAuth\Discovery::register();
			RateLimit::register();
		}

		// Priority 20: every plugin has loaded, so vendor detection is reliable.
		add_action( 'plugins_loaded', array( Integrations\IntegrationRegistry::class, 'boot' ), 20 );

		do_action( 'flavoursuite/ai/ready' );
	}

	public static function requires_wp_69_notice(): void {
		// Only users who can act on it (upgrade core / deactivate the plugin).
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		wp_admin_notice(
			__( 'FlavourSuite AI requires WordPress 6.9 or newer (Abilities API).', 'flavoursuite-ai' ),
			array( 'type' => 'error' )
		);
	}
}
