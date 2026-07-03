<?php
/**
 * Plugin Name:       FlavourSuite AI
 * Plugin URI:        https://github.com/flavoursuite/flavoursuite-ai
 * Description:       Make your WordPress site agent-ready: safely expose site data and actions to AI agents (Claude, ChatGPT, Cursor) via the Model Context Protocol.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            FlavourSuite
 * Author URI:        https://flavoursuite.github.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flavoursuite-ai
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'FLAVOURSUITE_AI_LOADED' ) ) {
	return;
}
define( 'FLAVOURSUITE_AI_LOADED', true );

require __DIR__ . '/includes/Lifecycle.php';

\FlavourSuite\Ai\Lifecycle::boot( __FILE__ );
