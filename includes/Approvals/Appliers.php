<?php
/**
 * Appliers: the ONLY code that turns an approved change request into a live
 * site change (and back). Never reachable from an MCP tool — the admin page
 * calls it inside a nonce-checked, capability-checked POST handler.
 *
 * Every apply/rollback re-verifies two things at decision time:
 *  1. Capability of the DECIDING user (not the proposing agent).
 *  2. Staleness — the target must still match the state captured at proposal
 *     (or at apply, for rollbacks). If a human edited it in between, we refuse
 *     rather than overwrite their work.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\Approvals;

use FlavourSuite\Ai\Log;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Appliers {

	public const TYPE_CSS     = 'css';
	public const TYPE_CONTENT = 'content';

	/**
	 * Whether the current user may apply/rollback this change request.
	 * Per-type: proposing needed the same capability, but the decider is a
	 * different person at a different time, so it must be re-checked here.
	 *
	 * @param array $request Normalized request from ChangeRequests::get().
	 */
	public static function current_user_can_decide( array $request ): bool {
		switch ( $request['type'] ) {
			case self::TYPE_CSS:
				return current_user_can( 'edit_theme_options' );
			case self::TYPE_CONTENT:
				$post_id = isset( $request['payload']['post_id'] ) ? (int) $request['payload']['post_id'] : 0;
				return $post_id > 0 && current_user_can( 'edit_post', $post_id );
		}
		return false;
	}

	/**
	 * @param array $request Normalized request from ChangeRequests::get().
	 * @return true|WP_Error
	 */
	public static function apply( array $request ) {
		switch ( $request['type'] ) {
			case self::TYPE_CSS:
				return self::apply_css( $request['payload'] );
			case self::TYPE_CONTENT:
				return self::apply_content( $request );
		}
		return new WP_Error( 'fs_unknown_type', __( 'Unknown change type.', 'flavoursuite-ai' ) );
	}

	/**
	 * @param array $request Normalized request from ChangeRequests::get().
	 * @return true|WP_Error
	 */
	public static function rollback( array $request ) {
		switch ( $request['type'] ) {
			case self::TYPE_CSS:
				return self::rollback_css( $request['payload'] );
			case self::TYPE_CONTENT:
				return self::rollback_content( $request );
		}
		return new WP_Error( 'fs_unknown_type', __( 'Unknown change type.', 'flavoursuite-ai' ) );
	}

	// ---------------------------------------------------------------- CSS.

	/**
	 * Load core's custom CSS setting class on its own.
	 *
	 * Core only requires this class from WP_Customize_Manager::__construct(),
	 * which we must not run outside the Customizer: it unhooks wp_cron and the
	 * core update check, and spins up the widget/nav-menu subsystems. The two
	 * class files have no such side effects.
	 */
	private static function load_custom_css_setting(): bool {
		if ( class_exists( 'WP_Customize_Custom_CSS_Setting' ) ) {
			return true;
		}

		$parent = ABSPATH . WPINC . '/class-wp-customize-setting.php';
		$child  = ABSPATH . WPINC . '/customize/class-wp-customize-custom-css-setting.php';
		if ( ! is_readable( $parent ) || ! is_readable( $child ) ) {
			return false;
		}

		require_once $parent;
		require_once $child;

		return class_exists( 'WP_Customize_Custom_CSS_Setting' );
	}

	/**
	 * Validate CSS exactly as Appearance → Additional CSS does.
	 *
	 * wp_update_custom_css_post() stores without validating — the checks live
	 * in the Customizer setting — so anything writing custom CSS outside the
	 * Customizer has to invoke them itself. This rejects CSS that would break
	 * out of the enclosing STYLE element, including the partial-closing-tag
	 * forms core guards against.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_css( string $css ) {
		if ( self::load_custom_css_setting() ) {
			try {
				// validate() never dereferences the manager, so we can skip
				// constructing one; the id must carry a single stylesheet key.
				$setting  = new \WP_Customize_Custom_CSS_Setting( null, 'custom_css[' . get_stylesheet() . ']' );
				$validity = $setting->validate( $css );

				return is_wp_error( $validity ) && $validity->has_errors() ? $validity : true;
			} catch ( \Throwable $e ) {
				// Core's setting API changed shape — fall through.
				Log::debug( 'custom CSS validation via core setting failed: ' . $e->getMessage() );
			}
		}

		// Reduced stand-in for a broken install: catches a real closing tag but
		// not the trailing-partial forms core also rejects.
		if ( preg_match( '#</style#i', $css ) ) {
			return new WP_Error( 'illegal_markup', __( 'The CSS must not contain "</style".', 'flavoursuite-ai' ) );
		}

		return true;
	}

	/**
	 * Payload: { stylesheet, before_css, after_css }.
	 * before_css was captured server-side at proposal time, so an exact
	 * comparison against wp_get_custom_css() is a reliable staleness check.
	 *
	 * @return true|WP_Error
	 */
	private static function apply_css( array $payload ) {
		if ( get_stylesheet() !== ( $payload['stylesheet'] ?? '' ) ) {
			return new WP_Error( 'fs_stale', __( 'The active theme changed since this was proposed. Reject it and ask the agent to propose again.', 'flavoursuite-ai' ) );
		}

		if ( wp_get_custom_css() !== (string) ( $payload['before_css'] ?? '' ) ) {
			return new WP_Error( 'fs_stale', __( 'The Additional CSS changed since this was proposed. Reject it and ask the agent to propose again.', 'flavoursuite-ai' ) );
		}

		// Re-validated at apply time: the stored payload could predate the
		// propose-time check (or a future format change).
		$validity = self::validate_css( (string) ( $payload['after_css'] ?? '' ) );
		if ( is_wp_error( $validity ) ) {
			return $validity;
		}

		$result = wp_update_custom_css_post( (string) ( $payload['after_css'] ?? '' ) );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function rollback_css( array $payload ) {
		if ( get_stylesheet() !== ( $payload['stylesheet'] ?? '' ) ) {
			return new WP_Error( 'fs_stale', __( 'The active theme changed since this was applied.', 'flavoursuite-ai' ) );
		}

		if ( wp_get_custom_css() !== (string) ( $payload['after_css'] ?? '' ) ) {
			return new WP_Error( 'fs_stale', __( 'The Additional CSS was modified after this change was applied — rolling back would overwrite that edit. Adjust it manually under Appearance → Additional CSS.', 'flavoursuite-ai' ) );
		}

		$result = wp_update_custom_css_post( (string) ( $payload['before_css'] ?? '' ) );

		return is_wp_error( $result ) ? $result : true;
	}

	// ------------------------------------------------------------ Content.

	/**
	 * Payload: { post_id, baseline_modified_gmt, before: {title, content},
	 * after: {title?, content?} }. The post's modified time is the staleness
	 * token; core bumps it on every save, including edits by other plugins.
	 *
	 * @param array $request Full request — apply stores the post-apply
	 *                       modified time on the CR for rollback checks.
	 * @return true|WP_Error
	 */
	private static function apply_content( array $request ) {
		$payload = $request['payload'];
		$post    = get_post( (int) ( $payload['post_id'] ?? 0 ) );

		if ( ! $post ) {
			return new WP_Error( 'fs_gone', __( 'The target post no longer exists.', 'flavoursuite-ai' ) );
		}

		if ( $post->post_modified_gmt !== ( $payload['baseline_modified_gmt'] ?? '' ) ) {
			return new WP_Error( 'fs_stale', __( 'The post was edited since this was proposed. Reject it and ask the agent to propose again from the current version.', 'flavoursuite-ai' ) );
		}

		$update = array( 'ID' => $post->ID );
		$after  = isset( $payload['after'] ) && is_array( $payload['after'] ) ? $payload['after'] : array();
		if ( isset( $after['title'] ) ) {
			$update['post_title'] = (string) $after['title'];
		}
		if ( isset( $after['content'] ) ) {
			$update['post_content'] = (string) $after['content'];
		}

		// wp_update_post unslashes; protect literal backslashes in the content.
		$result = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Remember what the post looked like right after our write — rollback
		// refuses if anyone edited the post after that point.
		$fresh = get_post( $post->ID );
		if ( $fresh ) {
			update_post_meta( $request['id'], '_fs_applied_modified_gmt', $fresh->post_modified_gmt );
		}

		return true;
	}

	/**
	 * @param array $request Full request (needs the CR id for applied-state meta).
	 * @return true|WP_Error
	 */
	private static function rollback_content( array $request ) {
		$payload = $request['payload'];
		$post    = get_post( (int) ( $payload['post_id'] ?? 0 ) );

		if ( ! $post ) {
			return new WP_Error( 'fs_gone', __( 'The target post no longer exists.', 'flavoursuite-ai' ) );
		}

		$applied_gmt = (string) get_post_meta( $request['id'], '_fs_applied_modified_gmt', true );
		if ( '' === $applied_gmt || $post->post_modified_gmt !== $applied_gmt ) {
			return new WP_Error( 'fs_stale', __( 'The post was edited after this change was applied — rolling back would overwrite that edit. Restore it from the post’s revision history instead.', 'flavoursuite-ai' ) );
		}

		$before = isset( $payload['before'] ) && is_array( $payload['before'] ) ? $payload['before'] : array();
		$after  = isset( $payload['after'] ) && is_array( $payload['after'] ) ? $payload['after'] : array();

		$update = array( 'ID' => $post->ID );
		if ( isset( $after['title'] ) ) {
			$update['post_title'] = (string) ( $before['title'] ?? '' );
		}
		if ( isset( $after['content'] ) ) {
			$update['post_content'] = (string) ( $before['content'] ?? '' );
		}

		$result = wp_update_post( wp_slash( $update ), true );

		return is_wp_error( $result ) ? $result : true;
	}
}
