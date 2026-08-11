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
	 *
	 * `term_exists()` returns `null` for a term that is absent and a non-null
	 * array or string for one that is present, so `null !==` matches its
	 * documented contract exactly rather than relying on truthiness.
	 *
	 * The slug is cast because PHP coerces numeric string array keys to `int`,
	 * and `term_exists()` switches to looking up by *term ID* when handed one.
	 * No shipped kind is numeric, so this changes nothing today; it stops a
	 * future kind keyed `'2024'` from silently resolving against an unrelated
	 * term.
	 *
	 * `wp_insert_term()`'s return is deliberately dropped. It can only fail here
	 * if the taxonomy is unregistered — impossible, `Activator` registers it two
	 * lines earlier and `07-notes.php` asserts it — or if the slug already
	 * exists, which the guard above has just ruled out. Logging an unreachable
	 * branch would be noise that outlives whoever added it. The per-slug
	 * assertions in `07-notes.php` are what would catch a seeding failure.
	 */
	public static function seed(): void {
		foreach ( Default_Kinds::all() as $slug => $label ) {
			if ( null !== term_exists( (string) $slug, Taxonomies::KIND ) ) {
				continue;
			}

			wp_insert_term( $label, Taxonomies::KIND, array( 'slug' => (string) $slug ) );
		}
	}
}
