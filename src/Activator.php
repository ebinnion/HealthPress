<?php
/**
 * Activation routine.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress;

use HealthPress\Notes\Kind_Seeder;

/**
 * Runs once on plugin activation.
 */
final class Activator {

	/**
	 * Registers the object types, then seeds the metric and kind taxonomies.
	 *
	 * Activation happens after `init` has already fired, so the post type and
	 * taxonomy have to be registered here explicitly before terms can be
	 * inserted against them.
	 */
	public static function activate(): void {
		$plugin = Plugin::instance();

		$plugin->register_object_types();
		$plugin->sync()->sync();

		Kind_Seeder::seed();

		flush_rewrite_rules();
	}
}
