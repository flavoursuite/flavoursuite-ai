<?php
/**
 * Change-request store: agents propose, humans decide.
 *
 * Each request is a private custom post — payload as JSON in post_content,
 * type/status in meta. Nothing here touches the live site; that's Appliers,
 * and only from an authenticated admin action.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\Approvals;

defined( 'ABSPATH' ) || exit;

final class ChangeRequests {

	public const POST_TYPE = 'fs_change';

	public const STATUS_PENDING     = 'pending';
	public const STATUS_APPLIED     = 'applied';
	public const STATUS_REJECTED    = 'rejected';
	public const STATUS_ROLLED_BACK = 'rolled_back';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'    => __( 'Agent change requests', 'flavoursuite-ai' ),
				'public'   => false,
				'show_ui'  => false,
				'supports' => array( 'title', 'author' ),
				'rewrite'  => false,
			)
		);
	}

	/**
	 * @param string $type    Change type handled by Appliers (css|content).
	 * @param array  $payload Type-specific before/after data.
	 * @param string $summary One-line human summary shown in the review list.
	 * @return int|\WP_Error Change request ID.
	 */
	public static function create( string $type, array $payload, string $summary ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => sanitize_text_field( $summary ),
				// wp_insert_post unslashes; protect the JSON.
				'post_content' => wp_slash( (string) wp_json_encode( $payload ) ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_fs_change_type', sanitize_key( $type ) );
		update_post_meta( $post_id, '_fs_change_status', self::STATUS_PENDING );

		return $post_id;
	}

	/**
	 * @return array|null Normalized change request, or null if not one.
	 */
	public static function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$payload = json_decode( $post->post_content, true );

		return array(
			'id'         => $post->ID,
			'type'       => (string) get_post_meta( $post->ID, '_fs_change_type', true ),
			'status'     => (string) get_post_meta( $post->ID, '_fs_change_status', true ),
			'summary'    => $post->post_title,
			'payload'    => is_array( $payload ) ? $payload : array(),
			'author'     => $post->post_author ? get_the_author_meta( 'user_login', $post->post_author ) : '',
			'created'    => $post->post_date_gmt,
			'decided_by' => (string) get_post_meta( $post->ID, '_fs_decided_by', true ),
			'decided_at' => (string) get_post_meta( $post->ID, '_fs_decided_at', true ),
		);
	}

	/**
	 * @param string|null $status Filter by status, or null for all.
	 * @return list<array> Newest first.
	 */
	public static function items( ?string $status = null, int $limit = 50 ): array {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => max( 1, min( 200, $limit ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( null !== $status ) {
			$args['meta_key']   = '_fs_change_status'; // phpcs:ignore WordPress.DB.SlowDBQuery -- small private CPT.
			$args['meta_value'] = sanitize_key( $status ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$items = array();
		foreach ( get_posts( $args ) as $post ) {
			$item = self::get( $post->ID );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	public static function set_status( int $id, string $status ): void {
		update_post_meta( $id, '_fs_change_status', sanitize_key( $status ) );
		update_post_meta( $id, '_fs_decided_by', wp_get_current_user()->user_login );
		update_post_meta( $id, '_fs_decided_at', gmdate( 'Y-m-d H:i:s' ) );
	}
}
