<?php
/**
 * Audit trail for MCP tool calls, fed by the adapter's observability hook.
 *
 * Records WHO called WHICH tool and whether it succeeded — deliberately not
 * the arguments or results, so no query text or content ends up in the DB.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

defined( 'ABSPATH' ) || exit;

final class AuditLog implements McpObservabilityHandlerInterface {

	private const OPTION      = 'flavoursuite_ai_audit_log';
	private const MAX_ENTRIES = 100;

	/**
	 * Called by the adapter for every MCP event; we persist tool calls only —
	 * initialize/list traffic is protocol noise, not agent activity.
	 *
	 * @param string     $event       Event name (adapter emits 'mcp.request').
	 * @param array      $tags        Event tags (method, tool_name, status…).
	 * @param float|null $duration_ms Request duration in milliseconds.
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		if ( 'mcp.request' !== $event || 'tools/call' !== ( $tags['method'] ?? '' ) ) {
			return;
		}

		$user = wp_get_current_user();

		$entry = array(
			'time'        => time(),
			'user'        => $user->exists() ? $user->user_login : '(unauthenticated)',
			'tool'        => isset( $tags['tool_name'] ) && is_string( $tags['tool_name'] ) ? $tags['tool_name'] : '(unknown)',
			'status'      => isset( $tags['status'] ) && is_string( $tags['status'] ) ? $tags['status'] : '(unknown)',
			'duration_ms' => null !== $duration_ms ? (int) round( $duration_ms ) : null,
		);

		$entries = self::entries();
		array_unshift( $entries, $entry );
		$entries = array_slice( $entries, 0, self::MAX_ENTRIES );

		// Never autoload: this option is only read on the settings screen.
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Newest first.
	 *
	 * @return list<array{time:int,user:string,tool:string,status:string,duration_ms:?int}>
	 */
	public static function entries(): array {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Read-only table for the settings screen.
	 */
	public static function render_table( int $limit = 20 ): void {
		$entries = array_slice( self::entries(), 0, $limit );

		if ( array() === $entries ) {
			echo '<p><em>' . esc_html__( 'No agent activity recorded yet.', 'flavoursuite-ai' ) . '</em></p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:760px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'User', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Tool', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Status', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'flavoursuite-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human time diff, e.g. "5 mins". */
									__( '%s ago', 'flavoursuite-ai' ),
									human_time_diff( (int) $entry['time'] )
								)
							);
							?>
						</td>
						<td><?php echo esc_html( (string) $entry['user'] ); ?></td>
						<td><code><?php echo esc_html( (string) $entry['tool'] ); ?></code></td>
						<td><?php echo esc_html( (string) $entry['status'] ); ?></td>
						<td><?php echo esc_html( null !== $entry['duration_ms'] ? $entry['duration_ms'] . ' ms' : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
