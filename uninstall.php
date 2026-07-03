<?php
/**
 * Cleans up on plugin deletion (not deactivation).
 *
 * @package FlavourSuite\Ai
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'flavoursuite_ai_settings' );
delete_option( 'flavoursuite_ai_audit_log' );
