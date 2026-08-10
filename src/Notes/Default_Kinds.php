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
 * Unlike `Default_Metrics`, this list is a *seed*, not a registry. A metric
 * term without a code-defined metric behind it would name something with no
 * fields, units, or bounds, which is why metrics are synced and cannot be
 * authored. A kind carries no schema at all — it is a label used for
 * filtering — so a kind added by hand in the term screen is perfectly valid,
 * and nothing needs to reconcile it back against this list.
 *
 * That is the whole reason notes need no `Registry_Sync` equivalent.
 */
final class Default_Kinds {

	/**
	 * Returns the seeded kinds as slug => label, in display order.
	 *
	 * @return array<string, string>
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
