<?php
/**
 * The reading edit screen.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Admin;

use HealthPress\Storage\Post_Type;
use WP_Post;

/**
 * Wires the Reading metabox, the refusal notice, and the screen's assets.
 *
 * The stock Publish box is left exactly as core renders it: `post_date` is the
 * measurement time, so its date control edits the right value with no sync
 * code, and its Save Draft button is honoured as "hold this reading back".
 */
final class Reading_Screen {

	/**
	 * A refused submission for the post being edited, read once on load.
	 *
	 * @var Rejected_Submission|null
	 */
	private ?Rejected_Submission $rejected = null;

	/**
	 * Wires the screen's collaborators.
	 *
	 * @param Reading_Form     $form  Renders the metabox.
	 * @param Submission_Store $store Flash storage for refusals.
	 */
	public function __construct(
		private readonly Reading_Form $form,
		private readonly Submission_Store $store,
	) {}

	/**
	 * Registers the screen's hooks.
	 */
	public function register(): void {
		add_action( 'load-post.php', array( $this, 'load' ) );
		add_action( 'add_meta_boxes_' . Post_Type::SLUG, array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'post_updated_messages', array( $this, 'messages' ) );
	}

	/**
	 * Takes any refused submission into memory, once.
	 *
	 * `admin_notices` fires from admin-header.php *before* `do_meta_boxes()`, so
	 * the notice and the form must share one copy — whichever read the
	 * read-once transient second would otherwise get nothing.
	 */
	public function load(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which post is being viewed.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( 0 === $post_id || Post_Type::SLUG !== get_post_type( $post_id ) ) {
			return;
		}

		$this->rejected = $this->store->take( $post_id );
	}

	/**
	 * Adds the Reading box.
	 *
	 * @param WP_Post $post The reading being edited.
	 */
	public function add_meta_boxes( WP_Post $post ): void {
		unset( $post );

		add_meta_box(
			'hp_reading',
			__( 'Reading', 'healthpress' ),
			function ( WP_Post $post ): void {
				$this->form->render( $post, $this->rejected );
			},
			Post_Type::SLUG,
			'normal',
			'high'
		);
	}

	/**
	 * Explains a refusal.
	 */
	public function render_notice(): void {
		if ( null === $this->rejected || array() === $this->rejected->violations ) {
			return;
		}

		$items = '';

		foreach ( $this->rejected->violations as $violation ) {
			// Escaped here; messages can embed a submitted field name.
			$items .= sprintf( '<li>%s</li>', esc_html( $violation->message ) );
		}

		/*
		 * The two outcomes need different wording. A brand-new reading is
		 * demoted to a draft; one that already held valid values keeps them and
		 * stays exactly where it was, and saying "left as a draft" there would
		 * be simply untrue.
		 */
		$heading = $this->rejected->quarantined
			? __( 'This reading was not saved, and has been left as a draft:', 'healthpress' )
			: __( 'This change was not saved. The stored reading is unchanged:', 'healthpress' );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><ul class="hp-violations">%s</ul></div>',
			esc_html( $heading ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each message is escaped above.
			$items
		);
	}

	/**
	 * Rewords the post-save messages for a reading.
	 *
	 * Core's "Post draft updated." directly contradicts the error notice sitting
	 * above it when a save was refused.
	 *
	 * @param array<string, array<int, string>> $messages Message sets, keyed by post type.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function messages( array $messages ): array {
		/*
		 * Core's write succeeded even when ours refused the data, so it would
		 * otherwise report success directly above our error. Blanking the whole
		 * set leaves the refusal as the only thing on screen.
		 */
		if ( null !== $this->rejected ) {
			$messages[ Post_Type::SLUG ] = array_fill( 0, 11, '' );

			return $messages;
		}

		$saved = __( 'Reading saved.', 'healthpress' );

		$messages[ Post_Type::SLUG ] = array(
			0  => '',
			1  => $saved,
			4  => $saved,
			6  => $saved,
			7  => $saved,
			10 => __( 'Reading saved as a draft.', 'healthpress' ),
		);

		return $messages;
	}

	/**
	 * Loads the screen's assets.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || Post_Type::SLUG !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'healthpress-reading-form',
			plugins_url( 'assets/admin/reading-form.js', HEALTHPRESS_FILE ),
			array(),
			HEALTHPRESS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_enqueue_style(
			'healthpress-reading-form',
			plugins_url( 'assets/admin/reading-form.css', HEALTHPRESS_FILE ),
			array(),
			HEALTHPRESS_VERSION
		);
	}
}
