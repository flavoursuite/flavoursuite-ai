<?php
/**
 * Tools → Agent Changes: the human side of the approval flow.
 *
 * Renders pending change requests as core wp_text_diff() tables with
 * Approve & apply / Reject buttons, plus a decided list with Roll back.
 * All actions are nonce-checked POSTs handled on admin_init, and Appliers
 * re-verifies per-type capabilities and staleness before touching anything.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\Approvals;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	public const SLUG = 'flavoursuite-approvals';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'handle_action' ) );
	}

	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	public static function add_page(): void {
		add_management_page(
			__( 'Agent Changes', 'flavoursuite-ai' ),
			__( 'Agent Changes', 'flavoursuite-ai' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	// ------------------------------------------------------------ Actions.

	public static function handle_action(): void {
		if ( ! isset( $_POST['fs_approvals_action'], $_POST['fs_change_id'] ) ) {
			return;
		}

		$id     = (int) $_POST['fs_change_id'];
		$action = sanitize_key( wp_unslash( $_POST['fs_approvals_action'] ) );

		check_admin_referer( 'fs_change_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to review agent changes.', 'flavoursuite-ai' ) );
		}

		$request = ChangeRequests::get( $id );
		if ( null === $request ) {
			self::redirect( 'error', __( 'Change request not found.', 'flavoursuite-ai' ) );
		}

		// Appliers re-checks the type-specific capability for the DECIDING
		// user — manage_options alone is not enough to edit someone's post.
		if ( ! Appliers::current_user_can_decide( $request ) ) {
			wp_die( esc_html__( 'You are not allowed to decide this type of change.', 'flavoursuite-ai' ) );
		}

		switch ( $action ) {
			case 'approve':
				if ( ChangeRequests::STATUS_PENDING !== $request['status'] ) {
					self::redirect( 'error', __( 'Only pending requests can be approved.', 'flavoursuite-ai' ) );
				}
				$result = Appliers::apply( $request );
				if ( is_wp_error( $result ) ) {
					self::redirect( 'error', $result->get_error_message() );
				}
				ChangeRequests::set_status( $id, ChangeRequests::STATUS_APPLIED );
				self::redirect( 'applied', __( 'Change applied to the live site.', 'flavoursuite-ai' ) );
				break;

			case 'reject':
				if ( ChangeRequests::STATUS_PENDING !== $request['status'] ) {
					self::redirect( 'error', __( 'Only pending requests can be rejected.', 'flavoursuite-ai' ) );
				}
				ChangeRequests::set_status( $id, ChangeRequests::STATUS_REJECTED );
				self::redirect( 'rejected', __( 'Change rejected. The live site was not touched.', 'flavoursuite-ai' ) );
				break;

			case 'rollback':
				if ( ChangeRequests::STATUS_APPLIED !== $request['status'] ) {
					self::redirect( 'error', __( 'Only applied changes can be rolled back.', 'flavoursuite-ai' ) );
				}
				$result = Appliers::rollback( $request );
				if ( is_wp_error( $result ) ) {
					self::redirect( 'error', $result->get_error_message() );
				}
				ChangeRequests::set_status( $id, ChangeRequests::STATUS_ROLLED_BACK );
				self::redirect( 'rolled_back', __( 'Change rolled back to its before state.', 'flavoursuite-ai' ) );
				break;
		}
	}

	/**
	 * Messages can be long (staleness explanations), so they travel via a
	 * short-lived per-user transient instead of the URL.
	 *
	 * @return never
	 */
	private static function redirect( string $notice, string $message ): void {
		set_transient(
			'fs_approvals_notice_' . get_current_user_id(),
			array(
				'type'    => 'error' === $notice ? 'error' : 'success',
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( self::url() );
		exit;
	}

	// ------------------------------------------------------------- Render.

	public static function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Agent Changes', 'flavoursuite-ai' ) . '</h1>';
		echo '<p>' . esc_html__( 'AI agents can only propose changes — nothing below touches your site until you approve it here. Applied changes can be rolled back.', 'flavoursuite-ai' ) . '</p>';

		self::render_notice();
		self::render_styles();

		$pending = ChangeRequests::items( ChangeRequests::STATUS_PENDING );
		echo '<h2>' . esc_html__( 'Pending review', 'flavoursuite-ai' ) . '</h2>';
		if ( array() === $pending ) {
			echo '<p>' . esc_html__( 'No pending proposals.', 'flavoursuite-ai' ) . '</p>';
		}
		foreach ( $pending as $request ) {
			self::render_request( $request );
		}

		$decided = array_values(
			array_filter(
				ChangeRequests::items( null, 100 ),
				static function ( array $request ): bool {
					return ChangeRequests::STATUS_PENDING !== $request['status'];
				}
			)
		);
		$decided = array_slice( $decided, 0, 20 );

		if ( array() !== $decided ) {
			echo '<h2>' . esc_html__( 'Decided', 'flavoursuite-ai' ) . '</h2>';
			foreach ( $decided as $request ) {
				self::render_request( $request );
			}
		}

		echo '</div>';
	}

	private static function render_notice(): void {
		$key    = 'fs_approvals_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		wp_admin_notice(
			(string) $notice['message'],
			array(
				'type'        => 'error' === $notice['type'] ? 'error' : 'success',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Minimal styling for core's wp_text_diff() table — its rules live in
	 * revisions.css, which only the revisions screen enqueues.
	 */
	private static function render_styles(): void {
		echo '<style>
			.fs-change { border: 1px solid #c3c4c7; background: #fff; margin: 16px 0; padding: 12px 16px; max-width: 960px; }
			.fs-change table.diff { width: 100%; border-collapse: collapse; table-layout: fixed; }
			.fs-change table.diff td { padding: 4px 8px; font-family: Consolas, Monaco, monospace; font-size: 12px; vertical-align: top; word-wrap: break-word; white-space: pre-wrap; }
			.fs-change table.diff .diff-deletedline { background: #fdd; }
			.fs-change table.diff .diff-addedline { background: #dfd; }
			.fs-change table.diff .diff-deletedline del { background: #f99; text-decoration: none; }
			.fs-change table.diff .diff-addedline ins { background: #9f9; text-decoration: none; }
			.fs-change .fs-change-meta { color: #646970; margin: 0 0 8px; }
			.fs-change .fs-status { font-weight: 600; text-transform: uppercase; font-size: 11px; padding: 2px 8px; border-radius: 3px; background: #f0f0f1; }
			.fs-change .fs-status-applied { background: #d5e8d5; }
			.fs-change .fs-status-rejected, .fs-change .fs-status-rolled_back { background: #f1d2d2; }
			.fs-change form { display: inline-block; margin-right: 8px; }
		</style>';
	}

	private static function render_request( array $request ): void {
		echo '<div class="fs-change">';

		echo '<h3>' . esc_html( $request['summary'] ) . ' <span class="fs-status fs-status-' . esc_attr( $request['status'] ) . '">' . esc_html( $request['status'] ) . '</span></h3>';

		echo '<p class="fs-change-meta">';
		printf(
			/* translators: 1: change type, 2: proposing user, 3: UTC datetime. */
			esc_html__( '#%1$d · %2$s · proposed by %3$s · %4$s UTC', 'flavoursuite-ai' ),
			(int) $request['id'],
			esc_html( self::type_label( $request ) ),
			esc_html( $request['author'] ),
			esc_html( $request['created'] )
		);
		if ( '' !== $request['decided_by'] ) {
			printf(
				/* translators: 1: deciding user, 2: UTC datetime. */
				esc_html__( ' · decided by %1$s · %2$s UTC', 'flavoursuite-ai' ),
				esc_html( $request['decided_by'] ),
				esc_html( $request['decided_at'] )
			);
		}
		echo '</p>';

		if ( ChangeRequests::STATUS_PENDING === $request['status'] ) {
			self::render_diffs( $request );
		}

		self::render_buttons( $request );

		echo '</div>';
	}

	private static function type_label( array $request ): string {
		if ( Appliers::TYPE_CSS === $request['type'] ) {
			return __( 'Additional CSS', 'flavoursuite-ai' );
		}
		if ( Appliers::TYPE_CONTENT === $request['type'] ) {
			$post_id = isset( $request['payload']['post_id'] ) ? (int) $request['payload']['post_id'] : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
			return $post
				/* translators: 1: post title, 2: post ID. */
				? sprintf( __( 'Content: “%1$s” (#%2$d)', 'flavoursuite-ai' ), $post->post_title, $post_id )
				: __( 'Content (post deleted)', 'flavoursuite-ai' );
		}
		return $request['type'];
	}

	private static function render_diffs( array $request ): void {
		$payload = $request['payload'];

		if ( Appliers::TYPE_CSS === $request['type'] ) {
			self::render_diff( __( 'Additional CSS', 'flavoursuite-ai' ), (string) ( $payload['before_css'] ?? '' ), (string) ( $payload['after_css'] ?? '' ) );
			return;
		}

		if ( Appliers::TYPE_CONTENT === $request['type'] ) {
			$before = isset( $payload['before'] ) && is_array( $payload['before'] ) ? $payload['before'] : array();
			$after  = isset( $payload['after'] ) && is_array( $payload['after'] ) ? $payload['after'] : array();

			if ( isset( $after['title'] ) ) {
				self::render_diff( __( 'Title', 'flavoursuite-ai' ), (string) ( $before['title'] ?? '' ), (string) $after['title'] );
			}
			if ( isset( $after['content'] ) ) {
				self::render_diff( __( 'Content', 'flavoursuite-ai' ), (string) ( $before['content'] ?? '' ), (string) $after['content'] );
			}
		}
	}

	private static function render_diff( string $title, string $before, string $after ): void {
		$diff = wp_text_diff(
			$before,
			$after,
			array(
				'title_left'  => __( 'Current', 'flavoursuite-ai' ),
				'title_right' => __( 'Proposed', 'flavoursuite-ai' ),
			)
		);

		echo '<h4>' . esc_html( $title ) . '</h4>';
		if ( '' === $diff ) {
			echo '<p>' . esc_html__( 'No visible difference.', 'flavoursuite-ai' ) . '</p>';
			return;
		}
		// wp_text_diff() output is core-generated, escaped table markup.
		echo $diff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function render_buttons( array $request ): void {
		$status = $request['status'];

		if ( ChangeRequests::STATUS_PENDING === $status ) {
			self::render_button( $request['id'], 'approve', __( 'Approve & apply', 'flavoursuite-ai' ), 'primary' );
			self::render_button( $request['id'], 'reject', __( 'Reject', 'flavoursuite-ai' ), 'secondary' );
		} elseif ( ChangeRequests::STATUS_APPLIED === $status ) {
			self::render_button( $request['id'], 'rollback', __( 'Roll back', 'flavoursuite-ai' ), 'secondary' );
		}
	}

	private static function render_button( int $id, string $action, string $label, string $style ): void {
		echo '<form method="post" action="' . esc_url( self::url() ) . '">';
		wp_nonce_field( 'fs_change_' . $id );
		echo '<input type="hidden" name="fs_change_id" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="fs_approvals_action" value="' . esc_attr( $action ) . '" />';
		submit_button( $label, $style, 'submit', false );
		echo '</form>';
	}
}
