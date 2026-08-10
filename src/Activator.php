<?php
/**
 * Activation routine.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress;

/**
 * Runs once on plugin activation.
 */
final class Activator {

	/**
	 * Registers the object types and seeds the metric taxonomy.
	 *
	 * Activation happens after `init` has already fired, so the post type and
	 * taxonomy have to be registered here explicitly before terms can be
	 * inserted against them.
	 */
	public static function activate(): void {
		$plugin = Plugin::instance();

		$plugin->register_object_types();
		$plugin->sync()->sync();

		flush_rewrite_rules();
	}
}
