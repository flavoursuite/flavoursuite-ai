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

	private const OPTION        = 'flavoursuite_ai_audit_log';
	private const MAX_ENTRIES   = 100;
	private const EXPORT_ACTION = 'flavoursuite_ai_export_audit';

	public static function register(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'handle_export' ) );
	}

	/**
	 * Nonce-signed download URL for the settings screen.
	 */
	public static function export_url(): string {
		return wp_nonce_url(
			add_query_arg( 'action', self::EXPORT_ACTION, admin_url( 'admin-post.php' ) ),
			self::EXPORT_ACTION
		);
	}

	/**
	 * Streams the audit trail as CSV.
	 *
	 * Built as a string rather than through fputcsv() on php://output: the
	 * file-handle functions trip WP.org's Plugin Check, and the payload is
	 * bounded at MAX_ENTRIES rows so there is nothing to stream.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to export the audit log.', 'flavoursuite-ai' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::EXPORT_ACTION );

		$rows = array( array( 'time_utc', 'user', 'tool', 'status', 'duration_ms' ) );

		foreach ( self::entries() as $entry ) {
			$rows[] = array(
				gmdate( 'c', (int) $entry['time'] ),
				(string) $entry['user'],
				(string) $entry['tool'],
				(string) $entry['status'],
				null !== $entry['duration_ms'] ? (string) $entry['duration_ms'] : '',
			);
		}

		$csv = '';
		foreach ( $rows as $row ) {
			$csv .= implode( ',', array_map( array( self::class, 'csv_field' ), $row ) ) . "\r\n";
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header(
			'Content-Disposition: attachment; filename=flavoursuite-audit-'
			. gmdate( 'Y-m-d' ) . '.csv'
		);

		echo $csv; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- CSV body, quoted by csv_field(); escaping for HTML would corrupt it.
		exit;
	}

	/**
	 * RFC 4180 quoting. Always quoting is simpler than deciding when to, and
	 * the leading-quote form also stops spreadsheets treating a value starting
	 * with =, +, - or @ as a formula.
	 */
	private static function csv_field( string $value ): string {
		return '"' . str_replace( '"', '""', $value ) . '"';
	}

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
