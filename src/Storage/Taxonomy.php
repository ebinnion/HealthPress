<?php
/**
 * The metric taxonomy.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Storage;

/**
 * Registers `hp_metric`, the taxonomy that says which metric a reading measures.
 *
 * Purely a storage and query mechanism: it is how a reading is filtered by
 * metric without a meta join. Terms mirror a code-defined registry rather than
 * being authored, so the taxonomy carries no user interface of its own — no
 * term screen, no metabox. The only surface it keeps is the Metrics column on
 * the readings list, which is genuinely useful and needs none of the rest.
 */
final class Taxonomy {

	/**
	 * The taxonomy name.
	 */
	public const SLUG = 'hp_metric';

	/**
	 * Registers the taxonomy. Hooked to `init` at the default priority.
	 */
	public function register(): void {
		register_taxonomy(
			self::SLUG,
			array( Post_Type::SLUG ),
			array(
				'labels'             => array(
					'name'          => __( 'Metrics', 'healthpress' ),
					'singular_name' => __( 'Metric', 'healthpress' ),
					'menu_name'     => __( 'Metrics', 'healthpress' ),
					'all_items'     => __( 'All Metrics', 'healthpress' ),
					'search_items'  => __( 'Search Metrics', 'healthpress' ),
					'not_found'     => __( 'No metrics found.', 'healthpress' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_in_nav_menus'  => false,

				/*
				 * No term-management screen at all.
				 *
				 * The terms mirror a code-defined registry: they cannot be
				 * created, renamed, or deleted, so edit-tags.php would be a list
				 * of nine rows with nothing to do to them. Turning `show_ui` off
				 * removes the Metrics submenu *and* makes the URL inaccessible —
				 * edit-tags.php checks `show_ui` and dies — rather than merely
				 * hiding a link that still works when typed.
				 *
				 * `show_in_menu` and `show_in_quick_edit` both default to
				 * `show_ui`, so they follow automatically.
				 */
				'show_ui'            => false,

				/*
				 * The one piece of UI worth keeping. The readings list table
				 * selects its taxonomy columns purely on `show_admin_column`, and
				 * the column's filter link keys off `query_var` — neither
				 * consults `show_ui`, so the Metrics column and filtering both
				 * survive the screen being gone.
				 */
				'show_admin_column'  => true,

				// Off for the same reason as the post type: one write path.
				'show_in_rest'       => false,

				/*
				 * A reading measures exactly one thing, so the Reading metabox
				 * offers a single-select instead of core's checkbox list.
				 */
				'meta_box_cb'        => false,

				/*
				 * Terms mirror a code-defined registry, so nothing may author
				 * them.
				 *
				 * `assign_terms` is the load-bearing one. `wp_insert_post()`
				 * gates the whole `tax_input` path on it, so denying it removes
				 * core's second write path structurally rather than by hiding
				 * the control that feeds it. Nothing here is affected:
				 * `wp_set_object_terms()`, `wp_insert_term()` and `get_terms()`
				 * carry no capability checks, and the `show_admin_column`
				 * metric column and its filter link are ungated.
				 *
				 * With `show_ui` off these are belt to that braces, but they are
				 * kept because they describe the intent independently of whether
				 * a screen happens to exist to enforce it against.
				 */
				'capabilities'       => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'do_not_allow',
					'delete_terms' => 'do_not_allow',
					'assign_terms' => 'do_not_allow',
				),

				/*
				 * Flat. Hierarchy was only ever here to make core render a
				 * checkbox list rather than a tag box, and there is no core
				 * metabox any more — metrics themselves have no parents.
				 * Term rows are stored identically either way, and every
				 * tax_query already passes `include_children => false`.
				 */
				'hierarchical'       => false,
				'rewrite'            => false,
				'query_var'          => false,
			)
		);
	}
}
