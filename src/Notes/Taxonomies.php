<?php
/**
 * The note taxonomies.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes;

/**
 * Registers the three axes a note is filed under.
 *
 * All three are authored by the user, which is the opposite of `hp_metric`:
 * that taxonomy mirrors a code-defined registry, so it denies `assign_terms`
 * outright to remove core's second write path. Nothing about a note has a
 * schema behind it, so here the term screens are the feature.
 *
 * `query_var` is off on all three, matching the privacy posture of the post
 * type. That means `WP_Query` will not parse `?hp_note_kind=transcript` from a
 * URL — verified, not assumed — so `Admin\List_Table` translates these
 * parameters into an explicit `tax_query` itself. That single handler is what
 * makes the filter dropdowns *and* the taxonomy columns' filter links work.
 */
final class Taxonomies {

	/**
	 * What sort of document the note is. One per note.
	 */
	public const KIND = 'hp_note_kind';

	/**
	 * Who the note came from — a doctor, a clinic, a nurse line.
	 */
	public const PROVIDER = 'hp_note_provider';

	/**
	 * Ad-hoc keywords.
	 */
	public const TAG = 'hp_note_tag';

	/**
	 * Registers all three taxonomies. Hooked to `init` at the default priority,
	 * before the post type, so the post type's `taxonomies` argument resolves.
	 */
	public function register(): void {
		/*
		 * Hierarchical purely as a submission mechanism, not because a kind has
		 * a parent. `wp_insert_post()` reads `tax_input` as term *IDs* for a
		 * hierarchical taxonomy and as comma-separated *names* for a flat one,
		 * and the Kind metabox submits IDs. Term rows are stored identically
		 * either way, so this costs nothing and saves an entire save handler.
		 *
		 * `meta_box_cb` is false because core's checkbox list would allow
		 * several kinds; the metabox in Admin\Kind_Metabox is a single select.
		 */
		register_taxonomy(
			self::KIND,
			array( Post_Type::SLUG ),
			$this->args(
				array(
					'name'          => __( 'Kinds', 'healthpress' ),
					'singular_name' => __( 'Kind', 'healthpress' ),
					'menu_name'     => __( 'Kinds', 'healthpress' ),
					'all_items'     => __( 'All Kinds', 'healthpress' ),
					'search_items'  => __( 'Search Kinds', 'healthpress' ),
					'not_found'     => __( 'No kinds found.', 'healthpress' ),
				),
				true,
				false
			)
		);

		// Flat, so core renders its stock tag box — which is the wanted UI.
		register_taxonomy(
			self::PROVIDER,
			array( Post_Type::SLUG ),
			$this->args(
				array(
					'name'          => __( 'Providers', 'healthpress' ),
					'singular_name' => __( 'Provider', 'healthpress' ),
					'menu_name'     => __( 'Providers', 'healthpress' ),
					'all_items'     => __( 'All Providers', 'healthpress' ),
					'search_items'  => __( 'Search Providers', 'healthpress' ),
					'not_found'     => __( 'No providers found.', 'healthpress' ),
				)
			)
		);

		register_taxonomy(
			self::TAG,
			array( Post_Type::SLUG ),
			$this->args(
				array(
					'name'          => __( 'Note Tags', 'healthpress' ),
					'singular_name' => __( 'Note Tag', 'healthpress' ),
					'menu_name'     => __( 'Tags', 'healthpress' ),
					'all_items'     => __( 'All Tags', 'healthpress' ),
					'search_items'  => __( 'Search Tags', 'healthpress' ),
					'not_found'     => __( 'No tags found.', 'healthpress' ),
				)
			)
		);
	}

	/**
	 * Builds the arguments shared by all three taxonomies.
	 *
	 * @param array<string, string> $labels       Taxonomy labels.
	 * @param bool                  $hierarchical Whether to register as hierarchical.
	 * @param bool|null             $meta_box_cb  Metabox callback; false suppresses core's.
	 *
	 * @return array<string, mixed>
	 */
	private function args( array $labels, bool $hierarchical = false, ?bool $meta_box_cb = null ): array {
		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_in_nav_menus'  => false,

			// The term screens are the point: providers and kinds get renamed.
			'show_ui'            => true,

			// Gives each a list-table column with a filter link, wired in List_Table.
			'show_admin_column'  => true,

			// Off for the same reason as readings: this plugin owns its write paths.
			'show_in_rest'       => false,
			'hierarchical'       => $hierarchical,
			'rewrite'            => false,
			'query_var'          => false,

			/*
			 * Everything in this plugin is gated on `manage_options`, including
			 * every REST route. `assign_terms` is granted rather than denied —
			 * the inverse of `hp_metric` — because these terms are authored.
			 */
			'capabilities'       => array(
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'manage_options',
			),
		);

		if ( null !== $meta_box_cb ) {
			$args['meta_box_cb'] = $meta_box_cb;
		}

		return $args;
	}
}
