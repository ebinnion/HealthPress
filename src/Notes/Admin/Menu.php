<?php
/**
 * The Add Note menu item.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes\Admin;

use HealthPress\Notes\Post_Type;
use HealthPress\Storage\Post_Type as Reading_Post_Type;

/**
 * Adds "Add Note" beneath "All Notes".
 *
 * Core will not do this itself. The loop in `wp-admin/menu.php` that contributes
 * an "Add New" item skips any post type whose `show_in_menu` is not literally
 * `true` — and `hp_note` sets it to a string, to nest under the HealthPress menu
 * rather than claim a second top-level item. So nesting costs the Add item, and
 * this puts it back.
 *
 * Without it the menu reads "All Readings / Add Reading / All Notes", which looks
 * like an oversight rather than a decision. Notes remain reachable either way
 * through the Add Note button on the list screen; this is about the two halves of
 * the feature matching.
 */
final class Menu {

	/**
	 * Registers the menu item.
	 *
	 * Priority 11, because core builds the HealthPress parent menu at the
	 * default 10 and a submenu cannot attach to a parent that does not exist yet.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add' ), 11 );
	}

	/**
	 * Adds the submenu item.
	 */
	public function add(): void {
		$type = get_post_type_object( Post_Type::SLUG );

		if ( null === $type ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . Reading_Post_Type::SLUG,
			$type->labels->add_new_item,
			$type->labels->add_new_item,
			$type->cap->create_posts,
			'post-new.php?post_type=' . Post_Type::SLUG
		);
	}
}
