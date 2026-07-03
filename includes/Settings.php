<?php
/**
 * Admin settings: master switch, per-tool toggles, connection info.
 *
 * Security posture (see docs/11): the MCP endpoint is OFF by default.
 * Tools default ON only when their ability is annotated readonly; anything
 * that writes must be enabled deliberately.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

defined( 'ABSPATH' ) || exit;

final class Settings {

	private const OPTION = 'flavoursuite_ai_settings';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	public static function is_enabled(): bool {
		$settings = self::get();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Saved preference wins; otherwise readonly-annotated abilities default ON,
	 * everything else OFF.
	 */
	public static function is_tool_enabled( string $name ): bool {
		$settings = self::get();
		if ( isset( $settings['tools'][ $name ] ) ) {
			return (bool) $settings['tools'][ $name ];
		}
		return self::is_readonly( $name );
	}

	public static function is_readonly( string $name ): bool {
		$ability = wp_get_ability( $name );
		if ( null === $ability ) {
			return false;
		}
		$annotations = (array) $ability->get_meta_item( 'annotations', array() );
		return ! empty( $annotations['readonly'] );
	}

	private static function get(): array {
		$settings = get_option( self::OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

	public static function add_page(): void {
		add_options_page(
			__( 'FlavourSuite AI', 'flavoursuite-ai' ),
			__( 'FlavourSuite AI', 'flavoursuite-ai' ),
			'manage_options',
			'flavoursuite-ai',
			array( self::class, 'render_page' )
		);
	}

	public static function register_setting(): void {
		register_setting(
			'flavoursuite_ai',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Only known tool names are accepted from POST; saved preferences for
	 * tools whose integration is currently inactive are preserved untouched.
	 *
	 * @param mixed $input Raw option value from options.php.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = self::get();

		$clean = array(
			'enabled' => empty( $input['enabled'] ) ? 0 : 1,
			'tools'   => array(),
		);

		if ( isset( $existing['tools'] ) && is_array( $existing['tools'] ) ) {
			foreach ( $existing['tools'] as $name => $value ) {
				$clean['tools'][ (string) $name ] = empty( $value ) ? 0 : 1;
			}
		}

		foreach ( Mcp::tool_names() as $name ) {
			if ( isset( $input['tools'][ $name ] ) ) {
				$clean['tools'][ $name ] = empty( $input['tools'][ $name ] ) ? 0 : 1;
			}
		}

		return $clean;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tools    = Mcp::tool_names();
		$endpoint = rest_url( 'flavoursuite-ai/mcp' );
		$snippet  = wp_json_encode(
			array(
				'mcpServers' => array(
					'flavoursuite' => array(
						'type'    => 'http',
						'url'     => $endpoint,
						'headers' => array(
							'Authorization' => 'Basic <base64 of username:application-password>',
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FlavourSuite AI', 'flavoursuite-ai' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'flavoursuite_ai' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'MCP server', 'flavoursuite-ai' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( self::is_enabled() ); ?> />
								<?php esc_html_e( 'Expose this site to AI agents over MCP', 'flavoursuite-ai' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. Every tool call also requires an authenticated WordPress user with the matching capability — enabling this never opens anonymous access.', 'flavoursuite-ai' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tools', 'flavoursuite-ai' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Enabled tools', 'flavoursuite-ai' ); ?></legend>
								<?php foreach ( $tools as $name ) : ?>
									<?php
									$ability = wp_get_ability( $name );
									$label   = $ability ? $ability->get_label() : $name;
									?>
									<label style="display:block;margin-bottom:6px;">
										<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[tools][<?php echo esc_attr( $name ); ?>]" value="0" />
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[tools][<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( self::is_tool_enabled( $name ) ); ?> />
										<?php echo esc_html( $label ); ?>
										<code><?php echo esc_html( $name ); ?></code>
										<?php if ( ! self::is_readonly( $name ) ) : ?>
											<strong style="color:#b32d2e;"><?php esc_html_e( '(writes data)', 'flavoursuite-ai' ); ?></strong>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">
								<?php esc_html_e( 'Read-only tools are on by default; anything that writes data stays off until you enable it here.', 'flavoursuite-ai' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Connect an agent', 'flavoursuite-ai' ); ?></h2>
			<?php if ( ! self::is_enabled() ) : ?>
				<p><em><?php esc_html_e( 'The MCP server is currently disabled — enable it above first.', 'flavoursuite-ai' ); ?></em></p>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'Endpoint (Streamable HTTP):', 'flavoursuite-ai' ); ?>
				<code><?php echo esc_url( $endpoint ); ?></code>
			</p>
			<p>
				<?php esc_html_e( 'Authenticate with an Application Password (create one under Users → Profile) via HTTP Basic auth. Example client configuration:', 'flavoursuite-ai' ); ?>
			</p>
			<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;overflow:auto;"><code><?php echo esc_html( $snippet ); ?></code></pre>

			<hr />

			<h2><?php esc_html_e( 'Recent agent activity', 'flavoursuite-ai' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The last tool calls made through the MCP server. Arguments and results are never stored.', 'flavoursuite-ai' ); ?>
			</p>
			<?php AuditLog::render_table(); ?>
		</div>
		<?php
	}
}
