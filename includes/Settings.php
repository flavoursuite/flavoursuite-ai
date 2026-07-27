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

	private const REVOKE_ACTION       = 'flavoursuite_ai_revoke_client';
	private const CREATE_TOKEN_ACTION = 'flavoursuite_ai_create_token';
	private const REVOKE_TOKEN_ACTION = 'flavoursuite_ai_revoke_token';

	/** One-shot handoff of a freshly minted token; see handle_create_token(). */
	private const NEW_TOKEN_TRANSIENT = 'fs_new_token_';

	/**
	 * Hook suffix returned by add_options_page(); used to enqueue the
	 * connection-snippet script on this screen only.
	 */
	private static string $page_hook = '';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::REVOKE_ACTION, array( self::class, 'handle_revoke' ) );
		add_action( 'admin_post_' . self::CREATE_TOKEN_ACTION, array( self::class, 'handle_create_token' ) );
		add_action( 'admin_post_' . self::REVOKE_TOKEN_ACTION, array( self::class, 'handle_revoke_token' ) );
	}

	private static function page_url(): string {
		return admin_url( 'options-general.php?page=flavoursuite-ai' );
	}

	public static function is_enabled(): bool {
		$settings = self::get();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Per-minute request budget for authenticated MCP calls; 0 disables the
	 * limiter entirely.
	 */
	public static function rate_limit(): int {
		$settings = self::get();
		return isset( $settings['rate_limit'] )
			? max( 0, (int) $settings['rate_limit'] )
			: RateLimit::DEFAULT_LIMIT;
	}

	/**
	 * Revokes an OAuth client registration and every token issued to it.
	 */
	public static function handle_revoke(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to revoke agent access.', 'flavoursuite-ai' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::REVOKE_ACTION );

		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$revoked   = '' !== $client_id && OAuth\Store::delete_client( $client_id );

		wp_safe_redirect( add_query_arg( 'fs-revoked', $revoked ? '1' : '0', self::page_url() ) );
		exit;
	}

	/**
	 * Mints a connection token for the current user.
	 */
	public static function handle_create_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to create connection tokens.', 'flavoursuite-ai' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::CREATE_TOKEN_ACTION );

		$label = isset( $_POST['token_label'] ) ? sanitize_text_field( wp_unslash( $_POST['token_label'] ) ) : '';
		$ttl   = isset( $_POST['token_ttl'] ) ? absint( $_POST['token_ttl'] ) : 0;
		$ttl   = in_array( $ttl, array( 0, 30, 90, 365 ), true ) ? $ttl : 0;

		$plain = ConnectionTokens::create( $label, get_current_user_id(), $ttl );

		if ( null !== $plain ) {
			// Handed to the next request in a short-lived transient rather than
			// the redirect URL: a credential in a query string ends up in
			// browser history, server access logs and the Referer header.
			set_transient( self::NEW_TOKEN_TRANSIENT . get_current_user_id(), $plain, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'fs-token', null === $plain ? 'full' : 'created', self::page_url() ) );
		exit;
	}

	public static function handle_revoke_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to revoke connection tokens.', 'flavoursuite-ai' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::REVOKE_TOKEN_ACTION );

		$id      = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';
		$revoked = '' !== $id && ConnectionTokens::delete( $id );

		wp_safe_redirect( add_query_arg( 'fs-token', $revoked ? 'revoked' : 'missing', self::page_url() ) );
		exit;
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
		$hook = add_options_page(
			__( 'FlavourSuite AI', 'flavoursuite-ai' ),
			__( 'FlavourSuite AI', 'flavoursuite-ai' ),
			'manage_options',
			'flavoursuite-ai',
			array( self::class, 'render_page' )
		);

		self::$page_hook = is_string( $hook ) ? $hook : '';
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' === self::$page_hook || $hook_suffix !== self::$page_hook ) {
			return;
		}

		wp_enqueue_script(
			'flavoursuite-ai-settings',
			FLAVOURSUITE_AI_URL . 'assets/js/settings.js',
			array(),
			FLAVOURSUITE_AI_VERSION,
			true
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
			'enabled'    => empty( $input['enabled'] ) ? 0 : 1,
			// Capped rather than unbounded: a typo of 60000 would make the
			// limiter useless while looking configured.
			'rate_limit' => isset( $input['rate_limit'] )
				? max( 0, min( 6000, (int) $input['rate_limit'] ) )
				: self::rate_limit(),
			'tools'      => array(),
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

		// Plaintext of a token minted by the previous request, read and burned
		// here so it exists only as a local for the duration of this render. It
		// is shown once in the tokens section and prefilled into the recipe
		// builder below it; both are just reads of this variable.
		$new_token = self::take_new_token();

		// Recipes are handed to the browser as data; __URL__ and __AUTH__ are
		// substituted there so the credential never round-trips to the server.
		$profiles = ClientProfiles::all();
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
					<tr>
						<th scope="row">
							<label for="fs-rate-limit"><?php esc_html_e( 'Rate limit', 'flavoursuite-ai' ); ?></label>
						</th>
						<td>
							<input type="number" id="fs-rate-limit" class="small-text" min="0" max="6000" step="1"
								name="<?php echo esc_attr( self::OPTION ); ?>[rate_limit]"
								value="<?php echo esc_attr( (string) self::rate_limit() ); ?>" />
							<?php esc_html_e( 'requests per minute, per user', 'flavoursuite-ai' ); ?>
							<p class="description">
								<?php esc_html_e( 'Caps how fast a single agent can call tools; a runaway loop gets a 429 instead of exhausting the server. Set to 0 to disable. Unauthenticated OAuth requests are always limited more tightly, per IP.', 'flavoursuite-ai' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Connection tokens', 'flavoursuite-ai' ); ?></h2>
			<?php self::render_connection_tokens( $new_token ); ?>

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
				<?php esc_html_e( 'Pick your agent and copy the recipe. Which model it runs — Claude, GPT, Gemini, DeepSeek, Qwen, Kimi, GLM, Llama, or anything through OpenRouter — makes no difference; MCP is the same protocol either way.', 'flavoursuite-ai' ); ?>
			</p>
			<p>
				<label for="fs-client"><strong><?php esc_html_e( 'Agent', 'flavoursuite-ai' ); ?></strong></label><br />
				<select id="fs-client" class="regular-text" data-endpoint="<?php echo esc_attr( $endpoint ); ?>" data-profiles="<?php echo esc_attr( (string) wp_json_encode( $profiles ) ); ?>">
					<?php $group = ''; ?>
					<?php foreach ( $profiles as $profile ) : ?>
						<?php if ( $profile['group'] !== $group ) : ?>
							<?php echo '' === $group ? '' : '</optgroup>'; ?>
							<optgroup label="<?php echo esc_attr( $profile['group'] ); ?>">
							<?php $group = $profile['group']; ?>
						<?php endif; ?>
						<option value="<?php echo esc_attr( $profile['id'] ); ?>"><?php echo esc_html( $profile['label'] ); ?></option>
					<?php endforeach; ?>
					<?php echo '' === $group ? '' : '</optgroup>'; ?>
				</select>
			</p>

			<p id="fs-client-note" class="description"></p>

			<div id="fs-credentials">
				<p>
					<?php esc_html_e( 'The credential is turned into an Authorization header in your browser — it is never sent back to the server or saved anywhere.', 'flavoursuite-ai' ); ?>
				</p>
				<p>
					<label style="margin-right:16px;">
						<input type="radio" name="fs-auth-mode" value="token" checked />
						<?php esc_html_e( 'Connection token (recommended)', 'flavoursuite-ai' ); ?>
					</label>
					<label>
						<input type="radio" name="fs-auth-mode" value="basic" />
						<?php esc_html_e( 'Application password', 'flavoursuite-ai' ); ?>
					</label>
				</p>

				<div id="fs-auth-token">
					<p>
						<label>
							<?php esc_html_e( 'Connection token', 'flavoursuite-ai' ); ?><br />
							<input type="text" id="fs-token-value" class="large-text code" autocomplete="off" spellcheck="false"
								placeholder="fsai_…" value="<?php echo esc_attr( $new_token ); ?>" />
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'Create one in the section above. It only works on the MCP endpoint, so a leaked agent config cannot be replayed against the rest of the REST API.', 'flavoursuite-ai' ); ?>
					</p>
				</div>

				<div id="fs-auth-basic" style="display:none;">
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
					<p class="description">
						<?php esc_html_e( 'Create one under Users → Profile → Application Passwords. Note that it authenticates the agent against your entire account, not just MCP — a connection token is the safer choice.', 'flavoursuite-ai' ); ?>
					</p>
				</div>

				<p>
					<button type="button" id="fs-token-generate" class="button button-secondary"><?php esc_html_e( 'Build connection recipe', 'flavoursuite-ai' ); ?></button>
					<span id="fs-token-error" style="color:#b32d2e;display:none;"
						data-token="<?php esc_attr_e( 'Paste a connection token first.', 'flavoursuite-ai' ); ?>"
						data-basic="<?php esc_attr_e( 'Enter both the username and the application password.', 'flavoursuite-ai' ); ?>"></span>
				</p>
			</div>

			<p id="fs-file-line" style="display:none;">
				<?php esc_html_e( 'Save as:', 'flavoursuite-ai' ); ?>
				<code id="fs-file-value"></code>
			</p>
			<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;overflow:auto;"><code id="fs-snippet"></code></pre>
			<p class="description">
				<?php
				printf(
					/* translators: %s: docs URL. */
					esc_html__( 'Full per-client walkthroughs and troubleshooting: %s', 'flavoursuite-ai' ),
					'<a href="https://flavoursuite.github.io/docs/#connect" target="_blank" rel="noopener">flavoursuite.github.io/docs</a>'
				);
				?>
			</p>
			<hr />

			<h2><?php esc_html_e( 'Connected agents', 'flavoursuite-ai' ); ?></h2>
			<?php self::render_connected_agents(); ?>

			<hr />

			<h2><?php esc_html_e( 'Recent agent activity', 'flavoursuite-ai' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The last tool calls made through the MCP server. Arguments and results are never stored.', 'flavoursuite-ai' ); ?>
			</p>
			<?php AuditLog::render_table(); ?>
			<p>
				<a href="<?php echo esc_url( AuditLog::export_url() ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Export audit log (CSV)', 'flavoursuite-ai' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Collects the plaintext of a token minted by the previous request.
	 *
	 * Read and burn: whichever render gets there first receives the value, and a
	 * reload, a second tab, or another user's session receives nothing. The
	 * transient is keyed per user, so it is never readable across accounts.
	 *
	 * @return string Empty when no token is waiting.
	 */
	private static function take_new_token(): string {
		$key   = self::NEW_TOKEN_TRANSIENT . get_current_user_id();
		$plain = get_transient( $key );

		if ( ! is_string( $plain ) || '' === $plain ) {
			return '';
		}

		delete_transient( $key );

		return $plain;
	}

	/**
	 * Create and revoke MCP-scoped bearer tokens.
	 *
	 * Only a SHA-256 hash is stored, so the plaintext exists for exactly one
	 * render. Rather than trust the user to copy it, this section runs above the
	 * recipe builder and the caller prefills it there.
	 *
	 * @param string $new_token Plaintext just minted, or '' — see take_new_token().
	 */
	private static function render_connection_tokens( string $new_token ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flag from our own redirect.
		$status = isset( $_GET['fs-token'] ) ? sanitize_key( wp_unslash( $_GET['fs-token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'full'    => array(
				'error',
				__( 'Token limit reached. Revoke one below before creating another.', 'flavoursuite-ai' ),
			),
			'revoked' => array(
				'success',
				__( 'Connection token revoked. Any agent using it is locked out immediately.', 'flavoursuite-ai' ),
			),
			'missing' => array(
				'warning',
				__( 'That token had already been revoked.', 'flavoursuite-ai' ),
			),
		);

		if ( isset( $notices[ $status ] ) ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( $notices[ $status ][0] ),
				esc_html( $notices[ $status ][1] )
			);
		}
		?>
		<p>
			<?php esc_html_e( 'A connection token lets an agent act as your WordPress user, but only on the MCP endpoint — it is rejected everywhere else in the REST API. Prefer one of these over an Application Password, which would grant the agent your full account.', 'flavoursuite-ai' ); ?>
		</p>

		<?php if ( '' !== $new_token ) : ?>
			<div class="notice notice-success inline">
				<p><strong><?php esc_html_e( 'Copy this token now — it is never shown again.', 'flavoursuite-ai' ); ?></strong></p>
				<p>
					<label for="fs-new-token" class="screen-reader-text"><?php esc_html_e( 'New connection token', 'flavoursuite-ai' ); ?></label>
					<input type="text" id="fs-new-token" class="large-text code" readonly value="<?php echo esc_attr( $new_token ); ?>" />
				</p>
				<p class="description">
					<?php esc_html_e( 'It has already been filled into the connection recipe below. Only a hash is kept here, so if you lose it you will have to create a new one.', 'flavoursuite-ai' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( self::CREATE_TOKEN_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE_TOKEN_ACTION ); ?>" />
			<label for="fs-token-label" class="screen-reader-text"><?php esc_html_e( 'Token label', 'flavoursuite-ai' ); ?></label>
			<input type="text" id="fs-token-label" name="token_label" class="regular-text"
				placeholder="<?php esc_attr_e( 'What is it for? e.g. Claude Code on my laptop', 'flavoursuite-ai' ); ?>" />
			<label for="fs-token-ttl" class="screen-reader-text"><?php esc_html_e( 'Expiry', 'flavoursuite-ai' ); ?></label>
			<select id="fs-token-ttl" name="token_ttl">
				<option value="0"><?php esc_html_e( 'Never expires', 'flavoursuite-ai' ); ?></option>
				<option value="30"><?php esc_html_e( 'Expires in 30 days', 'flavoursuite-ai' ); ?></option>
				<option value="90"><?php esc_html_e( 'Expires in 90 days', 'flavoursuite-ai' ); ?></option>
				<option value="365"><?php esc_html_e( 'Expires in a year', 'flavoursuite-ai' ); ?></option>
			</select>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Create token', 'flavoursuite-ai' ); ?></button>
		</form>

		<?php
		$tokens = ConnectionTokens::all();

		if ( array() === $tokens ) {
			echo '<p><em>' . esc_html__( 'No connection tokens yet.', 'flavoursuite-ai' ) . '</em></p>';
			return;
		}

		$now = time();
		?>
		<table class="widefat striped" style="max-width:900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Acts as', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Created', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'flavoursuite-ai' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tokens as $token ) : ?>
					<?php
					$user    = get_user_by( 'id', (int) $token['user'] );
					$expires = (int) $token['expires'];
					$expired = $expires > 0 && $expires < $now;
					?>
					<tr>
						<td><strong><?php echo esc_html( (string) $token['label'] ); ?></strong></td>
						<td>
							<?php if ( $user ) : ?>
								<code><?php echo esc_html( $user->user_login ); ?></code>
							<?php else : ?>
								<em style="color:#b32d2e;"><?php esc_html_e( 'deleted user', 'flavoursuite-ai' ); ?></em>
							<?php endif; ?>
						</td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human time diff, e.g. "5 mins". */
									__( '%s ago', 'flavoursuite-ai' ),
									human_time_diff( (int) $token['created'] )
								)
							);
							?>
						</td>
						<td>
							<?php if ( (int) $token['last_used'] > 0 ) : ?>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: human time diff, e.g. "5 mins". */
										__( '%s ago', 'flavoursuite-ai' ),
										human_time_diff( (int) $token['last_used'] )
									)
								);
								?>
							<?php else : ?>
								<span style="color:#8c8f94;"><?php esc_html_e( 'Never', 'flavoursuite-ai' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 0 === $expires ) : ?>
								<span style="color:#8c8f94;"><?php esc_html_e( 'Never', 'flavoursuite-ai' ); ?></span>
							<?php elseif ( $expired ) : ?>
								<strong style="color:#b32d2e;"><?php esc_html_e( 'Expired', 'flavoursuite-ai' ); ?></strong>
							<?php else : ?>
								<?php echo esc_html( wp_date( (string) get_option( 'date_format' ), $expires ) ); ?>
							<?php endif; ?>
						</td>
						<td style="text-align:right;">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( self::REVOKE_TOKEN_ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_TOKEN_ACTION ); ?>" />
								<input type="hidden" name="token_id" value="<?php echo esc_attr( (string) $token['id'] ); ?>" />
								<button type="submit" class="button button-small button-link-delete">
									<?php esc_html_e( 'Revoke', 'flavoursuite-ai' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( '"Last used" is recorded at most once every five minutes, so it lags a busy agent slightly. Expired tokens stop working immediately but stay listed until you revoke them.', 'flavoursuite-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * OAuth clients registered against this site, with a revoke action.
	 *
	 * Registration is public by RFC 7591 — any agent may register itself — so
	 * this table is also how an administrator notices a registration they did
	 * not initiate, and ends it.
	 */
	private static function render_connected_agents(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flag from our own redirect.
		if ( isset( $_GET['fs-revoked'] ) ) {
			$ok = '1' === sanitize_key( wp_unslash( $_GET['fs-revoked'] ) );
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				$ok ? 'success' : 'warning',
				esc_html(
					$ok
						? __( 'Agent access revoked.', 'flavoursuite-ai' )
						: __( 'That agent had already been revoked.', 'flavoursuite-ai' )
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$clients = OAuth\Store::clients();

		if ( array() === $clients ) {
			echo '<p><em>' . esc_html__( 'No agents have connected over OAuth yet. This table covers cloud clients only — agents using a connection token are listed above, and agents using an Application Password are revoked under Users → Profile.', 'flavoursuite-ai' ) . '</em></p>';
			return;
		}

		uasort(
			$clients,
			static function ( array $a, array $b ): int {
				return (int) ( $b['created'] ?? 0 ) <=> (int) ( $a['created'] ?? 0 );
			}
		);
		?>
		<table class="widefat striped" style="max-width:760px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Agent', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Registered', 'flavoursuite-ai' ); ?></th>
					<th><?php esc_html_e( 'Status', 'flavoursuite-ai' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $clients as $client ) : ?>
					<?php $live = OAuth\Store::active_token_count( (string) $client['client_id'] ); ?>
					<tr>
						<td><strong><?php echo esc_html( (string) $client['name'] ); ?></strong></td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human time diff, e.g. "5 mins". */
									__( '%s ago', 'flavoursuite-ai' ),
									human_time_diff( (int) $client['created'] )
								)
							);
							?>
						</td>
						<td>
							<?php if ( $live > 0 ) : ?>
								<span style="color:#00701c;">&#9679;</span> <?php esc_html_e( 'Connected', 'flavoursuite-ai' ); ?>
							<?php else : ?>
								<span style="color:#8c8f94;">&#9675;</span> <?php esc_html_e( 'No active token', 'flavoursuite-ai' ); ?>
							<?php endif; ?>
						</td>
						<td style="text-align:right;">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( self::REVOKE_ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_ACTION ); ?>" />
								<input type="hidden" name="client_id" value="<?php echo esc_attr( (string) $client['client_id'] ); ?>" />
								<button type="submit" class="button button-small button-link-delete">
									<?php esc_html_e( 'Revoke', 'flavoursuite-ai' ); ?>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Revoking removes the registration and immediately invalidates every token issued to that agent — it must go through the consent screen again to reconnect.', 'flavoursuite-ai' ); ?>
		</p>
		<?php
	}
}
