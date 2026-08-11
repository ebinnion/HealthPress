<?php
/**
 * The note kind metabox.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes\Admin;

use HealthPress\Notes\Post_Type;
use HealthPress\Notes\Taxonomies;
use WP_Post;

/**
 * Offers the note's kind as a single select.
 *
 * The taxonomy registers `meta_box_cb => false` because core's checkbox list
 * would let a note be two kinds at once, and a note is one sort of document.
 *
 * Nothing here saves. The select submits `tax_input[hp_note_kind][]`, which
 * `wp_insert_post()` handles itself — reading the values as term IDs, because
 * the taxonomy is hierarchical — and it replaces the note's terms wholesale, so
 * a single-valued select can only ever leave a single term. Core gates that
 * path on the `assign_terms` capability, which `Taxonomies` grants to
 * `manage_options`. That is the entire save path, and it is core's.
 */
final class Kind_Metabox {

	/**
	 * Registers the metabox.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . Post_Type::SLUG, array( $this, 'add' ) );
	}

	/**
	 * Adds the metabox to the sidebar.
	 */
	public function add(): void {
		add_meta_box(
			'hp-note-kind',
			__( 'Kind', 'healthpress' ),
			array( $this, 'render' ),
			Post_Type::SLUG,
			'side',
			'default'
		);
	}

	/**
	 * Renders the select.
	 *
	 * @param WP_Post $post The note being edited.
	 */
	public function render( WP_Post $post ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => Taxonomies::KIND,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || array() === $terms ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No kinds are defined yet.', 'healthpress' )
			);

			return;
		}

		$current = wp_get_object_terms( $post->ID, Taxonomies::KIND, array( 'fields' => 'ids' ) );
		$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );

		printf(
			'<label class="screen-reader-text" for="hp-note-kind-select">%s</label>',
			esc_html__( 'Kind', 'healthpress' )
		);

		echo '<select id="hp-note-kind-select" name="tax_input[' . esc_attr( Taxonomies::KIND ) . '][]" class="widefat">';

		/*
		 * '0' rather than '': `wp_insert_post()` runs `array_filter()` over an
		 * array `tax_input` value, and '0' is falsy, so submitting this option
		 * leaves an empty array — which `wp_set_post_terms()` reads as "clear
		 * this note's terms". An empty string would filter out identically, but
		 * '0' also survives `sanitize_title()` intact, so Query_Filters can
		 * recognise the same value coming back as a filter parameter.
		 */
		printf(
			'<option value="0">%s</option>',
			esc_html__( '— None —', 'healthpress' )
		);

		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $term->term_id,
				selected( in_array( (int) $term->term_id, $current, true ), true, false ),
				esc_html( $term->name )
			);
		}

		echo '</select>';
	}
}
