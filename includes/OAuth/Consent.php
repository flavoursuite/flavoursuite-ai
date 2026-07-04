<?php
/**
 * The OAuth consent screen: a wp-admin page (so WordPress login is enforced
 * by core) where the user approves or denies an agent's connection request.
 * Registered under profile.php so every logged-in user can grant access for
 * their OWN account — the same trust model as Application Passwords.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\OAuth;

defined( 'ABSPATH' ) || exit;

final class Consent {

	public const SLUG = 'flavoursuite-oauth-authorize';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'handle_decision' ) );
	}

	public static function add_page(): void {
		add_submenu_page(
			'profile.php',
			__( 'Authorize agent', 'flavoursuite-ai' ),
			__( 'Authorize agent', 'flavoursuite-ai' ),
			'read',
			self::SLUG,
			array( self::class, 'render' )
		);
		// Reachable by URL only — not a destination anyone browses to.
		remove_submenu_page( 'profile.php', self::SLUG );
	}

	/**
	 * Extracts and validates the OAuth request parameters from the URL.
	 *
	 * @return array|\WP_Error {client, redirect_uri, state, challenge}
	 */
	private static function request_params() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth authorize request; the decision POST is nonce-checked separately.
		$client_id = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$redirect  = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$state     = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$challenge = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
		$method    = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '';
		$type      = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$client = Store::get_client( $client_id );
		if ( null === $client ) {
			return new \WP_Error( 'fs_oauth', __( 'Unknown client. The agent must register before requesting authorization.', 'flavoursuite-ai' ) );
		}
		if ( ! in_array( $redirect, $client['redirect_uris'], true ) ) {
			return new \WP_Error( 'fs_oauth', __( 'The redirect address does not match what this client registered.', 'flavoursuite-ai' ) );
		}
		if ( 'code' !== $type ) {
			return new \WP_Error( 'fs_oauth', __( 'Unsupported response type.', 'flavoursuite-ai' ) );
		}
		if ( '' === $challenge || 'S256' !== $method ) {
			return new \WP_Error( 'fs_oauth', __( 'This server requires PKCE (S256).', 'flavoursuite-ai' ) );
		}

		return array(
			'client'    => $client,
			'redirect'  => $redirect,
			'state'     => $state,
			'challenge' => $challenge,
		);
	}

	/**
	 * Approve/deny POST — handled on admin_init so redirects happen before
	 * any admin chrome is sent.
	 */
	public static function handle_decision(): void {
		if ( ! isset( $_POST['fs_oauth_decision'] ) ) {
			return;
		}

		check_admin_referer( 'fs_oauth_consent' );

		$params = self::request_params();
		if ( is_wp_error( $params ) ) {
			wp_die( esc_html( $params->get_error_message() ) );
		}

		$decision = sanitize_key( wp_unslash( $_POST['fs_oauth_decision'] ) );

		if ( 'approve' === $decision ) {
			$code = Store::create_code(
				array(
					'user'         => get_current_user_id(),
					'client_id'    => $params['client']['client_id'],
					'redirect_uri' => $params['redirect'],
					'challenge'    => $params['challenge'],
				)
			);
			$args = array( 'code' => $code );
		} else {
			$args = array( 'error' => 'access_denied' );
		}

		if ( '' !== $params['state'] ) {
			$args['state'] = $params['state'];
		}

		// Registered client redirect target, validated against the exact
		// registration — deliberately NOT wp_safe_redirect (external host).
		wp_redirect( add_query_arg( $args, $params['redirect'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public static function render(): void {
		$params = self::request_params();

		echo '<div class="wrap" style="max-width:560px;">';
		echo '<h1>' . esc_html__( 'Connect an AI agent', 'flavoursuite-ai' ) . '</h1>';

		if ( is_wp_error( $params ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $params->get_error_message() ) . '</p></div></div>';
			return;
		}

		$user = wp_get_current_user();

		echo '<p style="font-size:14px;">';
		printf(
			/* translators: 1: client name, 2: user login, 3: site name. */
			esc_html__( '%1$s is asking to connect to %3$s as the WordPress user %2$s.', 'flavoursuite-ai' ),
			'<strong>' . esc_html( $params['client']['name'] ) . '</strong>',
			'<strong>' . esc_html( $user->user_login ) . '</strong>',
			'<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
		);
		echo '</p>';

		echo '<ul style="list-style:disc;padding-left:20px;color:#50575e;">';
		echo '<li>' . esc_html__( 'It can only use the tools enabled under Settings → FlavourSuite AI, limited to what your account may do.', 'flavoursuite-ai' ) . '</li>';
		echo '<li>' . esc_html__( 'Site changes still require approval under Tools → Agent Changes.', 'flavoursuite-ai' ) . '</li>';
		echo '<li>' . esc_html__( 'Every tool call is recorded in the audit log.', 'flavoursuite-ai' ) . '</li>';
		echo '</ul>';

		echo '<form method="post" action="">';
		wp_nonce_field( 'fs_oauth_consent' );
		echo '<button type="submit" name="fs_oauth_decision" value="approve" class="button button-primary button-hero">' . esc_html__( 'Allow connection', 'flavoursuite-ai' ) . '</button> ';
		echo '<button type="submit" name="fs_oauth_decision" value="deny" class="button button-hero">' . esc_html__( 'Deny', 'flavoursuite-ai' ) . '</button>';
		echo '</form>';

		echo '</div>';
	}
}
