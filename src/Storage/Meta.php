<?php
/**
 * Post meta keys for readings.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Storage;

use HealthPress\Metrics\Metric_Type;

/**
 * Derives the post meta keys a reading is stored under.
 *
 * Keys are namespaced per metric — `_hp_weight_value`, not `_hp_value`. Five of
 * the shipped metrics declare a field called `value`, so a shared key would be
 * ambiguous, and `wp_postmeta` is indexed on `meta_key`, so a namespaced key
 * matches only that metric's rows rather than five metrics' worth.
 *
 * Keys are never written by hand; everything goes through `key()`.
 *
 * These keys are deliberately NOT passed to `register_post_meta()`. Registration
 * buys a REST shape, a Custom Fields UI, and an `auth_callback` — and this post
 * type has `show_in_rest => false`, `supports => false`, and `_`-prefixed keys
 * that are protected meta, so all three are inert. The one thing registration
 * did contribute was a `sanitize_callback` that re-formatted values, which
 * duplicated (and once contradicted) the formatting `Post_Reading_Repository`
 * already applies through `Field::format()`.
 */
final class Meta {

	/**
	 * Prefix shared by every key this plugin writes.
	 *
	 * The leading underscore marks the meta protected, keeping it out of the
	 * custom fields UI.
	 */
	public const PREFIX = '_hp_';

	/**
	 * Key recording how a reading arrived.
	 */
	public const SOURCE = '_hp_source';

	/**
	 * Returns the meta key a metric's field is stored under.
	 *
	 * @param Metric_Type $metric    The metric being stored.
	 * @param string      $field_key Key of the field within that metric.
	 */
	public static function key( Metric_Type $metric, string $field_key ): string {
		return self::PREFIX . $metric->slug . '_' . $field_key;
	}
}
