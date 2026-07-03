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
		return true === self::readonly_annotation( $name );
	}

	/**
	 * Core defaults the readonly annotation to null ("not declared"), which
	 * is a different trust level than an explicit false — third-party
	 * abilities often declare nothing.
	 */
	public static function readonly_annotation( string $name ): ?bool {
		$ability = wp_get_ability( $name );
		if ( null === $ability ) {
			return null;
		}
		$annotations = (array) $ability->get_meta_item( 'annotations', array() );
		return isset( $annotations['readonly'] ) ? (bool) $annotations['readonly'] : null;
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

		// __AUTH__ is swapped client-side by the token generator below; the
		// displayed default keeps a human-readable placeholder.
		$snippet_template = (string) wp_json_encode(
			array(
				'mcpServers' => array(
					'flavoursuite' => array(
						'type'    => 'http',
						'url'     => $endpoint,
						'headers' => array(
							'Authorization' => '__AUTH__',
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		$snippet          = str_replace( '__AUTH__', 'Basic <base64 of username:application-password>', $snippet_template );
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
										<?php $readonly = self::readonly_annotation( $name ); ?>
										<?php if ( false === $readonly ) : ?>
											<strong style="color:#b32d2e;"><?php esc_html_e( '(writes data)', 'flavoursuite-ai' ); ?></strong>
										<?php elseif ( null === $readonly ) : ?>
											<em style="color:#996800;"><?php esc_html_e( '(read-only not declared by vendor — off by default)', 'flavoursuite-ai' ); ?></em>
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
				<?php esc_html_e( 'Agents authenticate with an Application Password (create one under Users → Profile → Application Passwords). Paste it below to build a ready-to-use connection snippet — the token is computed in your browser and never sent or saved anywhere.', 'flavoursuite-ai' ); ?>
			</p>
			<p>
				<label>
					<?php esc_html_e( 'WordPress username', 'flavoursuite-ai' ); ?><br />
					<input type="text" id="fs-token-user" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_login ); ?>" autocomplete="off" spellcheck="false" />
				</label>
			</p>
			<p>
				<label>
					<?php esc_html_e( 'Application password', 'flavoursuite-ai' ); ?><br />
					<input type="password" id="fs-token-pass" class="regular-text" autocomplete="off" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" />
				</label>
			</p>
			<p>
				<button type="button" id="fs-token-generate" class="button button-secondary"><?php esc_html_e( 'Build connection snippet', 'flavoursuite-ai' ); ?></button>
				<span id="fs-token-error" style="color:#b32d2e;display:none;"><?php esc_html_e( 'Enter both the username and the application password.', 'flavoursuite-ai' ); ?></span>
			</p>
			<p id="fs-auth-line" style="display:none;">
				<?php esc_html_e( 'Authorization header:', 'flavoursuite-ai' ); ?>
				<code id="fs-auth-value"></code>
			</p>
			<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;overflow:auto;"><code id="fs-snippet" data-template="<?php echo esc_attr( $snippet_template ); ?>"><?php echo esc_html( $snippet ); ?></code></pre>
			<p class="description">
				<?php
				printf(
					/* translators: %s: docs URL. */
					esc_html__( 'Per-client setup guides (Claude, ChatGPT, Cursor, VS Code, Codex): %s', 'flavoursuite-ai' ),
					'<a href="https://flavoursuite.github.io/docs/#connect" target="_blank" rel="noopener">flavoursuite.github.io/docs</a>'
				);
				?>
			</p>
			<?php
			wp_print_inline_script_tag(
				'(function () {
					var btn = document.getElementById( "fs-token-generate" );
					if ( ! btn ) { return; }
					btn.addEventListener( "click", function () {
						var user = document.getElementById( "fs-token-user" ).value.trim();
						var pass = document.getElementById( "fs-token-pass" ).value.trim();
						var err  = document.getElementById( "fs-token-error" );
						if ( ! user || ! pass ) { err.style.display = ""; return; }
						err.style.display = "none";
						var bytes = new TextEncoder().encode( user + ":" + pass );
						var bin = "";
						bytes.forEach( function ( b ) { bin += String.fromCharCode( b ); } );
						var auth = "Basic " + btoa( bin );
						document.getElementById( "fs-auth-value" ).textContent = auth;
						document.getElementById( "fs-auth-line" ).style.display = "";
						var snippet = document.getElementById( "fs-snippet" );
						snippet.textContent = snippet.getAttribute( "data-template" ).replace( "__AUTH__", auth );
					} );
				})();'
			);
			?>

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
