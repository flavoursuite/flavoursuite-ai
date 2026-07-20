<?php
/**
 * Approval-flow abilities: agents PROPOSE, humans decide.
 *
 * The propose tools never touch the live site — they create a pending change
 * request and hand back a review URL. Applying happens exclusively in
 * AdminPage (nonce + capability checked), via Appliers.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\Approvals;

use FlavourSuite\Ai\Log;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Abilities {

	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	public static function register_abilities(): void {
		self::register_get_custom_css();
		self::register_propose_css();
		self::register_propose_content_edit();
		self::register_list_change_requests();
	}

	// ------------------------------------------------------------- Reads.

	private static function register_get_custom_css(): void {
		self::guard(
			'flavoursuite/get-custom-css',
			wp_register_ability(
				'flavoursuite/get-custom-css',
				array(
					'label'               => __( 'Get custom CSS', 'flavoursuite-ai' ),
					'description'         => 'Read the site\'s Additional CSS (Appearance → Additional CSS) for the active theme. Use this before propose-css so a proposal starts from the current stylesheet.',
					'category'            => 'flavoursuite',
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'stylesheet' => array( 'type' => 'string' ),
							'css'        => array( 'type' => 'string' ),
						),
					),
					// Custom CSS is emitted on every public page — 'read' suffices.
					'permission_callback' => static fn ( $input = null ): bool => current_user_can( 'read' ),
					'execute_callback'    => static function (): array {
						return array(
							'stylesheet' => get_stylesheet(),
							'css'        => wp_get_custom_css(),
						);
					},
					'meta'                => array(
						'annotations' => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			)
		);
	}

	private static function register_list_change_requests(): void {
		self::guard(
			'flavoursuite/list-change-requests',
			wp_register_ability(
				'flavoursuite/list-change-requests',
				array(
					'label'               => __( 'List change requests', 'flavoursuite-ai' ),
					'description'         => 'List proposed changes and their review status (pending, applied, rejected, rolled_back). Lets an agent check whether a human approved its proposals.',
					'category'            => 'flavoursuite',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'status' => array(
								'type' => 'string',
								'enum' => array( 'pending', 'applied', 'rejected', 'rolled_back' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'review_url' => array( 'type' => 'string' ),
							'items'      => array( 'type' => 'array' ),
						),
					),
					'permission_callback' => static fn ( $input = null ): bool => current_user_can( 'edit_posts' ),
					'execute_callback'    => static function ( $input = null ): array {
						$status  = is_array( $input ) && isset( $input['status'] ) ? (string) $input['status'] : null;
						$user_id = get_current_user_id();
						$items   = array();
						foreach ( ChangeRequests::items( $status ) as $item ) {
							// Per-request visibility: only requests this user
							// authored or could decide (edit_theme_options for
							// CSS, edit_post on the target for content). An
							// unattributed request (author_id 0) is never
							// "mine", whoever is asking.
							$mine = $user_id > 0 && (int) $item['author_id'] === $user_id;
							if ( ! $mine && ! Appliers::current_user_can_decide( $item ) ) {
								continue;
							}
							// Payloads stay server-side: they can be large and
							// the agent already knows what it proposed.
							unset( $item['payload'], $item['author_id'] );
							$items[] = $item;
						}
						return array(
							'review_url' => AdminPage::url(),
							'items'      => $items,
						);
					},
					'meta'                => array(
						'annotations' => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			)
		);
	}

	// ---------------------------------------------------------- Proposals.

	private static function register_propose_css(): void {
		self::guard(
			'flavoursuite/propose-css',
			wp_register_ability(
				'flavoursuite/propose-css',
				array(
					'label'               => __( 'Propose custom CSS change', 'flavoursuite-ai' ),
					'description'         => 'Propose replacing the site\'s Additional CSS. Nothing changes on the live site: this creates a pending change request that a human reviews as a diff and approves or rejects in wp-admin. Provide the FULL desired stylesheet (call get-custom-css first and include existing rules you want to keep).',
					'category'            => 'flavoursuite',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'after_css', 'summary' ),
						'properties' => array(
							'after_css' => array(
								'type'        => 'string',
								'description' => 'The complete Additional CSS after the change.',
							),
							'summary'   => array(
								'type'        => 'string',
								'description' => 'One line for the human reviewer: what changes and why.',
							),
						),
					),
					'output_schema'       => self::proposal_output_schema(),
					'permission_callback' => static fn ( $input = null ): bool => current_user_can( 'edit_theme_options' ),
					'execute_callback'    => static function ( $input ) {
						$after   = (string) ( $input['after_css'] ?? '' );
						$summary = trim( (string) ( $input['summary'] ?? '' ) );
						if ( '' === $summary ) {
							return new WP_Error( 'fs_missing_summary', 'A summary is required — the human reviewer needs to know what this change does.' );
						}

						$before = wp_get_custom_css();
						if ( $before === $after ) {
							return new WP_Error( 'fs_no_change', 'The proposed CSS is identical to the current CSS — nothing to review.' );
						}

						// Core's Customizer validation (rejects markup etc.) —
						// fail at propose time so the agent can fix it, not the
						// human reviewer.
						$validity = Appliers::validate_css( $after );
						if ( is_wp_error( $validity ) ) {
							return $validity;
						}

						// before_css is captured HERE, server-side, so apply-time
						// staleness checks compare against ground truth.
						return self::proposal_result(
							ChangeRequests::create(
								Appliers::TYPE_CSS,
								array(
									'stylesheet' => get_stylesheet(),
									'before_css' => $before,
									'after_css'  => $after,
								),
								$summary
							)
						);
					},
					'meta'                => array(
						'annotations' => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
				)
			)
		);
	}

	private static function register_propose_content_edit(): void {
		self::guard(
			'flavoursuite/propose-content-edit',
			wp_register_ability(
				'flavoursuite/propose-content-edit',
				array(
					'label'               => __( 'Propose content edit', 'flavoursuite-ai' ),
					'description'         => 'Propose a new title and/or content for a post or page. Nothing changes on the live site: this creates a pending change request that a human reviews as a diff and approves or rejects in wp-admin. Provide the FULL replacement content, not a fragment.',
					'category'            => 'flavoursuite',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'post_id', 'summary' ),
						'properties' => array(
							'post_id' => array(
								'type'        => 'integer',
								'description' => 'ID of the post or page to change.',
							),
							'title'   => array(
								'type'        => 'string',
								'description' => 'Proposed new title (omit to keep the current one).',
							),
							'content' => array(
								'type'        => 'string',
								'description' => 'Proposed new content, complete (omit to keep the current one).',
							),
							'summary' => array(
								'type'        => 'string',
								'description' => 'One line for the human reviewer: what changes and why.',
							),
						),
					),
					'output_schema'       => self::proposal_output_schema(),
					'permission_callback' => static function ( $input = null ): bool {
						if ( ! current_user_can( 'edit_posts' ) ) {
							return false;
						}
						// Per-post check: schema validation rejects a missing
						// post_id later, so only enforce when it's present.
						$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
						return 0 === $post_id || current_user_can( 'edit_post', $post_id );
					},
					'execute_callback'    => array( self::class, 'propose_content_edit' ),
					'meta'                => array(
						'annotations' => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
				)
			)
		);
	}

	/**
	 * @param array $input Validated tool input.
	 * @return array|WP_Error
	 */
	public static function propose_content_edit( $input ) {
		$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
		if ( ! $post || ChangeRequests::POST_TYPE === $post->post_type ) {
			return new WP_Error( 'fs_not_found', 'No editable post with that ID.' );
		}

		$summary = trim( (string) ( $input['summary'] ?? '' ) );
		if ( '' === $summary ) {
			return new WP_Error( 'fs_missing_summary', 'A summary is required — the human reviewer needs to know what this change does.' );
		}

		$after = array();
		if ( isset( $input['title'] ) && (string) $input['title'] !== $post->post_title ) {
			$after['title'] = (string) $input['title'];
		}
		if ( isset( $input['content'] ) && (string) $input['content'] !== $post->post_content ) {
			$after['content'] = (string) $input['content'];
		}
		if ( array() === $after ) {
			return new WP_Error( 'fs_no_change', 'Provide a title and/or content that differs from the current post — nothing to review.' );
		}

		return self::proposal_result(
			ChangeRequests::create(
				Appliers::TYPE_CONTENT,
				array(
					'post_id'               => $post->ID,
					// Staleness token: core bumps this on every save.
					'baseline_modified_gmt' => $post->post_modified_gmt,
					'before'                => array(
						'title'   => $post->post_title,
						'content' => $post->post_content,
					),
					'after'                 => $after,
				),
				$summary
			)
		);
	}

	// ------------------------------------------------------------ Helpers.

	private static function proposal_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'change_request_id' => array( 'type' => 'integer' ),
				'status'            => array( 'type' => 'string' ),
				'review_url'        => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * @param int|WP_Error $created Result of ChangeRequests::create().
	 * @return array|WP_Error
	 */
	private static function proposal_result( $created ) {
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return array(
			'change_request_id' => $created,
			'status'            => ChangeRequests::STATUS_PENDING,
			'review_url'        => AdminPage::url(),
		);
	}

	/**
	 * @param \WP_Ability|null $ability Result of wp_register_ability().
	 */
	private static function guard( string $name, $ability ): void {
		if ( null === $ability ) {
			Log::debug( "failed to register {$name} ability." );
		}
	}
}
