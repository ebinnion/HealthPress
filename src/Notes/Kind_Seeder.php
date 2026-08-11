<?php
/**
 * Seeds the kind taxonomy.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes;

/**
 * Inserts the shipped note kinds, once.
 *
 * Idempotent by checking `term_exists()` first, so re-running activation adds
 * nothing. Deliberately one-directional: a kind removed from `Default_Kinds`
 * is *not* deleted, because deleting the term would silently unfile every note
 * carrying it. This is the same reasoning `Registry_Sync` applies to metrics,
 * minus the syncing — there is nothing to reconcile, only to seed.
 */
final class Kind_Seeder {

	/**
	 * Inserts any shipped kind that is not already a term.
	 *
	 * Requires the taxonomy to be registered, so callers outside `init` must
	 * register it first — which is why `Activator` calls
	 * `register_object_types()` before this.
	 */
	public static function seed(): void {
		foreach ( Default_Kinds::all() as $slug => $label ) {
			if ( null !== term_exists( $slug, Taxonomies::KIND ) ) {
				continue;
			}

			wp_insert_term( $label, Taxonomies::KIND, array( 'slug' => $slug ) );
		}
	}
}
