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
		self::register_site_overview();
		self::register_list_recent_posts();
		self::register_search_content();
	}

	/**
	 * wp_register_ability() returns null on failure (e.g. the WooCommerce
	 * bundled-adapter race, mcp-adapter#135) — never assume it succeeded.
	 */
	private static function guard( ?\WP_Ability $ability, string $name ): void {
		if ( null === $ability ) {
			Log::debug( sprintf( 'failed to register %s ability.', $name ) );
		}
	}

	private static function register_site_overview(): void {
		self::guard(
			wp_register_ability(
				'flavoursuite/site-overview',
				array(
					'label'               => __( 'Site overview', 'flavoursuite-ai' ),
					'description'         => 'Read-only snapshot of this WordPress site: name, URLs, software versions, active theme, plugin inventory, and content counts. Use it to orient yourself before other operations.',
					'category'            => 'flavoursuite',
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'site'     => array( 'type' => 'object' ),
							'software' => array( 'type' => 'object' ),
							'plugins'  => array( 'type' => 'object' ),
							'content'  => array( 'type' => 'object' ),
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
			),
			'flavoursuite/site-overview'
		);
	}

	private static function register_list_recent_posts(): void {
		self::guard(
			wp_register_ability(
				'flavoursuite/list-recent-posts',
				array(
					'label'               => __( 'List recent posts', 'flavoursuite-ai' ),
					'description'         => 'List the most recently published entries of a public post type (default "post"). Returns id, title, url, publish date, and a short excerpt for each item.',
					'category'            => 'flavoursuite',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array(
								'type'        => 'string',
								'description' => 'Public post type slug, e.g. "post" or "page". Default "post".',
							),
							'limit'     => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 50,
								'description' => 'Maximum number of items to return. Default 10.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array( 'type' => 'string' ),
							'count'     => array( 'type' => 'integer' ),
							'items'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'permission_callback' => static fn ( $input = null ): bool => current_user_can( 'read' ),
					'execute_callback'    => array( self::class, 'list_recent_posts' ),
					'meta'                => array(
						'annotations' => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			),
			'flavoursuite/list-recent-posts'
		);
	}

	private static function register_search_content(): void {
		self::guard(
			wp_register_ability(
				'flavoursuite/search-content',
				array(
					'label'               => __( 'Search content', 'flavoursuite-ai' ),
					'description'         => 'Full-text search across published content on this site. Returns matching items with id, type, title, url, and a text snippet.',
					'category'            => 'flavoursuite',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'query'      => array(
								'type'        => 'string',
								'minLength'   => 2,
								'description' => 'Search terms.',
							),
							'post_types' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Restrict to these public post type slugs. Default: all public types.',
							),
							'limit'      => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 50,
								'description' => 'Maximum number of results. Default 10.',
							),
						),
						'required'   => array( 'query' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'query'       => array( 'type' => 'string' ),
							'total_found' => array( 'type' => 'integer' ),
							'results'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'permission_callback' => static fn ( $input = null ): bool => current_user_can( 'read' ),
					'execute_callback'    => array( self::class, 'search_content' ),
					'meta'                => array(
						'annotations' => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			),
			'flavoursuite/search-content'
		);
	}

	/* ---------------------------------------------------------------------
	 * Execute callbacks — all read-only.
	 * ------------------------------------------------------------------- */

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

	/**
	 * @param mixed $input Validated against input_schema by the Abilities API.
	 * @return array|\WP_Error
	 */
	public static function list_recent_posts( $input = null ) {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';
		$limit     = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 10;

		$type_object = get_post_type_object( $post_type );
		if ( null === $type_object || ! is_post_type_viewable( $type_object ) ) {
			return new \WP_Error(
				'flavoursuite_invalid_post_type',
				sprintf( 'Unknown or non-public post type: %s', $post_type )
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'      => $post->ID,
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
				'date'    => get_post_time( 'c', true, $post ),
				'excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 ),
			);
		}

		return array(
			'post_type' => $post_type,
			'count'     => count( $items ),
			'items'     => $items,
		);
	}

	/**
	 * @param mixed $input Validated against input_schema by the Abilities API.
	 * @return array|\WP_Error
	 */
	public static function search_content( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$terms = trim( (string) ( $input['query'] ?? '' ) );
		$limit = isset( $input['limit'] ) ? max( 1, min( 50, (int) $input['limit'] ) ) : 10;

		if ( mb_strlen( $terms ) < 2 ) {
			return new \WP_Error( 'flavoursuite_query_too_short', 'Search query must be at least 2 characters.' );
		}

		$public_types = array_values( array_filter( get_post_types( array( 'public' => true ) ), 'is_post_type_viewable' ) );
		$post_types   = $public_types;
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$requested  = array_map( 'sanitize_key', $input['post_types'] );
			$post_types = array_values( array_intersect( $requested, $public_types ) );
			if ( empty( $post_types ) ) {
				return new \WP_Error( 'flavoursuite_invalid_post_types', 'None of the requested post types are public.' );
			}
		}

		$query = new \WP_Query(
			array(
				's'                   => $terms,
				'post_type'           => $post_types,
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'ignore_sticky_posts' => true,
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			$results[] = array(
				'id'      => $post->ID,
				'type'    => $post->post_type,
				'title'   => get_the_title( $post ),
				'url'     => get_permalink( $post ),
				'snippet' => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 25 ),
			);
		}

		return array(
			'query'       => $terms,
			'total_found' => (int) $query->found_posts,
			'results'     => $results,
		);
	}
}
