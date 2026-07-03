<?php
/**
 * MCP server registration via the official wordpress/mcp-adapter.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Transport\HttpTransport;

defined( 'ABSPATH' ) || exit;

final class Mcp {

	public static function register(): void {
		// Vendor may be absent (source checkout without composer install) or another
		// plugin may ship a conflicting copy — degrade gracefully, never fatal.
		if ( ! class_exists( McpAdapter::class ) ) {
			add_action( 'admin_notices', array( self::class, 'adapter_missing_notice' ) );
			return;
		}

		McpAdapter::instance();
		add_action( 'mcp_adapter_init', array( self::class, 'create_server' ) );
	}

	/**
	 * Registers the FlavourSuite MCP server.
	 * Endpoint: /wp-json/flavoursuite-ai/mcp (Streamable HTTP).
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The adapter singleton.
	 */
	public static function create_server( McpAdapter $adapter ): void {
		$result = $adapter->create_server(
			'flavoursuite-ai',
			'flavoursuite-ai',
			'mcp',
			'FlavourSuite AI',
			'Read-only FlavourSuite tools exposing this WordPress site to AI agents.',
			FLAVOURSUITE_AI_VERSION,
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			null,
			array(
				'flavoursuite/site-overview',
			)
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'FlavourSuite AI: MCP server registration failed — ' . $result->get_error_message() );
		}
	}

	public static function adapter_missing_notice(): void {
		wp_admin_notice(
			__( 'FlavourSuite AI: the MCP adapter library is not available; agent connectivity is disabled.', 'flavoursuite-ai' ),
			array( 'type' => 'warning' )
		);
	}
}
