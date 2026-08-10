<?php
/**
 * The note kinds shipped with the plugin.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes;

/**
 * The starting vocabulary for the `hp_note_kind` taxonomy.
 *
 * A *seed*, not a registry: this list is written to the taxonomy once, on
 * activation, and the site owns the terms from then on.
 *
 * What makes that safe is the direction of authority. A reading is written by
 * code keyed on `$metric->slug`, so something has to be able to resolve that
 * slug to a term on demand — which is what `Registry_Sync::ensure_term()` is
 * for. Nothing ever resolves a kind slug to a term: a human picks a kind from
 * the terms that already exist. So there is no code path to keep supplied, and
 * adding or renaming a kind by hand is an ordinary thing to do rather than a
 * way to produce a term nothing stands behind.
 */
final class Default_Kinds {

	/**
	 * Returns the seeded kinds as slug => label, in display order.
	 *
	 * The keys are the term slugs, and a caller must pass them to
	 * `wp_insert_term()` explicitly rather than let WordPress derive one from
	 * the label: `sanitize_title( 'Doctor’s note' )` is `doctors-note`, not
	 * `doctor_note`, so a derived slug would seed a term that no later lookup
	 * by key ever finds.
	 *
	 * @return array<string, string> Term slug => label.
	 */
	public static function all(): array {
		return array(
			'transcript'   => __( 'Transcript', 'healthpress' ),
			'doctor_note'  => __( 'Doctor’s note', 'healthpress' ),
			'lab_summary'  => __( 'Lab summary', 'healthpress' ),
			'personal_log' => __( 'Personal log', 'healthpress' ),
		);
	}
}
