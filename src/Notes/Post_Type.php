<?php
/**
 * The note post type.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes;

use HealthPress\Storage\Post_Type as Reading_Post_Type;

/**
 * Registers `hp_note`, the post type one document is stored as.
 *
 * Notes carry the same privacy posture as readings — not public, not queryable,
 * not in front-end search — but differ from them in one way that decides the
 * whole design: a note is *authored*. A reading's title is generated and its
 * values live in per-metric meta, so `hp_reading` registers `supports => false`
 * and supplies its own screen. A note is a title, a body, and a date, which is
 * exactly what core's editor chrome already renders.
 *
 * `editor` is nonetheless absent. Notes hold pasted transcripts, and the block
 * editor would chop one into paragraph blocks and wrap it in comment
 * delimiters. Admin\Body_Metabox supplies a plain textarea instead, so the body
 * is stored as the flat text it was pasted as — line breaks and all — rather
 * than as block markup.
 *
 * Not byte-exact, though: the body is sanitised with
 * `sanitize_textarea_field()`, which HTML-encodes a lone `<` and drops anything
 * that parses as a tag, so `HbA1c <5.7%` is stored as `HbA1c &lt;5.7%`. That is
 * a deliberate trade — no stored note can carry markup even if something later
 * renders it without escaping.
 *
 * `revisions` stays on: the body is the single source of truth, so being able
 * to recover the version before an accidental paste-over is cheap insurance.
 *
 * `exclude_from_search` does not affect the admin. It only removes the type
 * from the set `WP_Query` searches when no post type is given, and `edit.php`
 * always names one — which is why full-text note search needs no code at all.
 */
final class Post_Type {

	/**
	 * The post type name. Post type names are capped at 20 characters.
	 */
	public const SLUG = 'hp_note';

	/**
	 * Registers the post type. Hooked to `init` at the default priority.
	 */
	public function register(): void {
		register_post_type(
			self::SLUG,
			array(
				'labels'              => array(
					'name'               => __( 'Notes', 'healthpress' ),
					'singular_name'      => __( 'Note', 'healthpress' ),
					'menu_name'          => __( 'Notes', 'healthpress' ),
					'add_new'            => __( 'Add Note', 'healthpress' ),
					'add_new_item'       => __( 'Add Note', 'healthpress' ),
					'edit_item'          => __( 'Edit Note', 'healthpress' ),
					'view_item'          => __( 'View Note', 'healthpress' ),
					'search_items'       => __( 'Search Notes', 'healthpress' ),
					'not_found'          => __( 'No notes found.', 'healthpress' ),
					'not_found_in_trash' => __( 'No notes found in Trash.', 'healthpress' ),
					'all_items'          => __( 'All Notes', 'healthpress' ),
				),
				'public'              => false,
				'show_ui'             => true,

				/*
				 * Nested under the existing HealthPress menu rather than adding a
				 * second top-level item. Readings and notes are one feature.
				 */
				'show_in_menu'        => 'edit.php?post_type=' . Reading_Post_Type::SLUG,
				'show_in_nav_menus'   => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,

				// The admin screens and the CLI are the whole surface; core's REST is not part of it.
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'revisions' ),
				'map_meta_cap'        => true,
				'capability_type'     => 'post',
				'taxonomies'          => array( Taxonomies::KIND, Taxonomies::PROVIDER, Taxonomies::TAG ),
			)
		);
	}
}
