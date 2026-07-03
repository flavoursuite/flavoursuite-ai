<?php
/**
 * Ability registrations (core Abilities API, WP 6.9+).
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			'flavoursuite',
			array(
				'label'       => __( 'FlavourSuite', 'flavoursuite-ai' ),
				'description' => __( 'Abilities provided by the FlavourSuite plugins.', 'flavoursuite-ai' ),
			)
		);
	}

	public static function register_abilities(): void {
		$ability = wp_register_ability(
			'flavoursuite/site-overview',
			array(
				'label'               => __( 'Site overview', 'flavoursuite-ai' ),
				'description'         => 'Read-only snapshot of this WordPress site: name, URLs, software versions, active theme, plugin inventory, and content counts. Use it to orient yourself before other operations.',
				'category'            => 'flavoursuite',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'site'    => array( 'type' => 'object' ),
						'software' => array( 'type' => 'object' ),
						'plugins' => array( 'type' => 'object' ),
						'content' => array( 'type' => 'object' ),
					),
				),
				'permission_callback' => static fn ( $input = null ): bool => current_user_can( 'manage_options' ),
				'execute_callback'    => array( self::class, 'site_overview' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		// wp_register_ability() returns null on failure (e.g. the WooCommerce
		// bundled-adapter race, mcp-adapter#135) — never assume it succeeded.
		if ( null === $ability ) {
			error_log( 'FlavourSuite AI: failed to register flavoursuite/site-overview ability.' );
		}
	}

	/**
	 * Execute callback for flavoursuite/site-overview. Read-only.
	 */
	public static function site_overview(): array {
		$theme = wp_get_theme();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins    = get_plugins();
		$active_plugins = array();
		foreach ( $all_plugins as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$active_plugins[] = array(
					'name'    => $data['Name'],
					'version' => $data['Version'],
				);
			}
		}

		$post_counts = wp_count_posts( 'post' );
		$page_counts = wp_count_posts( 'page' );

		return array(
			'site'     => array(
				'name'     => get_bloginfo( 'name' ),
				'tagline'  => get_bloginfo( 'description' ),
				'url'      => home_url(),
				'language' => get_locale(),
				'timezone' => wp_timezone_string(),
			),
			'software' => array(
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'theme'     => array(
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
				),
			),
			'plugins'  => array(
				'installed' => count( $all_plugins ),
				'active'    => $active_plugins,
			),
			'content'  => array(
				'published_posts' => (int) ( $post_counts->publish ?? 0 ),
				'published_pages' => (int) ( $page_counts->publish ?? 0 ),
			),
		);
	}
}
