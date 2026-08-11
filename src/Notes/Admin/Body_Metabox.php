<?php
/**
 * The note body metabox.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes\Admin;

use HealthPress\Notes\Post_Type;
use WP_Post;

/**
 * Renders the note body as a plain textarea and writes it to `post_content`.
 *
 * The post type does not support `editor`, so nothing else supplies the body.
 * A textarea rather than the block editor because notes hold pasted
 * transcripts: the block editor would split one into paragraph blocks wrapped
 * in comment delimiters, so what is stored would be block markup rather than
 * the flat text that was pasted.
 *
 * Mapping happens on `wp_insert_post_data` rather than `save_post`. That hook
 * is the last point before the row is written, so the body lands in the same
 * single UPDATE as the rest of the post — no second write, and no recursion
 * guard needed, which a `wp_update_post()` call inside `save_post` would
 * require.
 *
 * A note on the alternative: naming the field `content` and letting
 * `_wp_translate_postdata()` promote it to `post_content` does work, but that
 * mapping is incidental to core's form handling rather than a contract. This
 * filter is explicit, and `Storage\Publish_Guard` already establishes it as the
 * pattern this plugin uses for late post-data changes.
 */
final class Body_Metabox {

	/**
	 * The textarea's field name.
	 */
	private const FIELD = 'hp_note_body';

	/**
	 * The nonce field's name.
	 */
	private const NONCE = 'hp_note_body_nonce';

	/**
	 * The nonce action.
	 */
	private const ACTION = 'hp_note_save_body';

	/**
	 * Registers the metabox and the save path.
	 *
	 * Deliberately not gated on `is_admin()`, matching
	 * `Admin\Reading_Save_Handler`: `is_admin()` is false under WP-CLI, which
	 * would make this untestable from the integration suite. The mapper
	 * discriminates on its own nonce, so registering it everywhere is safe.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . Post_Type::SLUG, array( $this, 'add' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'map_body' ), 10, 2 );
	}

	/**
	 * Adds the metabox where the editor would have been.
	 */
	public function add(): void {
		add_meta_box(
			'hp-note-body',
			__( 'Note', 'healthpress' ),
			array( $this, 'render' ),
			Post_Type::SLUG,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the import control and the textarea.
	 *
	 * @param WP_Post $post The note being edited.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::ACTION, self::NONCE );

		printf(
			'<p class="hp-note-import"><label for="hp-note-import-file">%s</label> <input type="file" id="hp-note-import-file" accept=".txt,.md,text/plain,text/markdown"> <span class="description">%s</span></p>',
			esc_html__( 'Import a text or markdown file:', 'healthpress' ),
			esc_html__( 'The file is read in your browser and never uploaded.', 'healthpress' )
		);

		printf(
			'<textarea id="hp-note-body" name="%1$s" class="hp-note-body widefat" rows="24" spellcheck="true">%2$s</textarea>',
			esc_attr( self::FIELD ),
			esc_textarea( $post->post_content )
		);
	}

	/**
	 * Writes the submitted body into the post data about to be saved.
	 *
	 * Every guard here is load-bearing: this filter runs on every post save on
	 * the site, so anything short of "this is a note, submitted through my own
	 * form, by someone allowed to edit it" must leave `$data` untouched.
	 *
	 * `sanitize_textarea_field()` keeps newlines and tabs but is not byte-exact
	 * — see `Notes\Post_Type` for what that costs and why it is worth it.
	 *
	 * `$data` arrives slashed and is written to the database as given, so the
	 * sanitised value is re-slashed on the way back in. Without that, every
	 * apostrophe in a transcript loses a backslash on each save.
	 *
	 * @param array<string, mixed> $data    Slashed, sanitised post data about to be written.
	 * @param array<string, mixed> $postarr The submitted post array.
	 *
	 * @return array<string, mixed>
	 */
	public function map_body( array $data, array $postarr ): array {
		if ( Post_Type::SLUG !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		// `isset`, not `empty`: emptying the textarea has to be possible.
		if ( ! isset( $_POST[ self::FIELD ], $_POST[ self::NONCE ] ) ) {
			return $data;
		}

		/*
		 * sanitize_key() rather than sanitize_text_field(): a nonce from
		 * wp_create_nonce() is ten lowercase hex characters, which the narrower
		 * of the two passes through untouched.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verifying it is the next line.
		$nonce = sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) );

		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );

		/*
		 * On a new note there is no ID to check yet, so the capability checked
		 * is the type's own — core has already run its own check by this point,
		 * but this filter is reachable from anywhere `wp_insert_post()` is.
		 *
		 * `edit_posts` is the right name for that fallback rather than a generic
		 * stand-in: `hp_note` registers `capability_type => 'post'`, so it is
		 * literally the type's own create capability.
		 */
		$allowed = $post_id > 0
			? current_user_can( 'edit_post', $post_id )
			: current_user_can( 'edit_posts' );

		if ( ! $allowed ) {
			return $data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$body = sanitize_textarea_field( wp_unslash( $_POST[ self::FIELD ] ) );

		$data['post_content'] = wp_slash( $body );

		return $data;
	}
}
