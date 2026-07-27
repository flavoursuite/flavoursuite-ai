<?php
/**
 * Cleans up on plugin deletion (not deactivation).
 *
 * @package FlavourSuite\Ai
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'flavoursuite_ai_settings' );
delete_option( 'flavoursuite_ai_audit_log' );

// Agent credentials. Leaving these behind would mean a reinstall silently
// restored access for every previously connected agent.
delete_option( 'flavoursuite_ai_connection_tokens' );
delete_option( 'flavoursuite_ai_oauth_clients' );
delete_option( 'flavoursuite_ai_oauth_tokens' );

// Change requests (fs_change CPT) and their meta.
$flavoursuite_ai_change_ids = get_posts(
	array(
		'post_type'      => 'fs_change',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $flavoursuite_ai_change_ids as $flavoursuite_ai_change_id ) {
	wp_delete_post( $flavoursuite_ai_change_id, true );
}
