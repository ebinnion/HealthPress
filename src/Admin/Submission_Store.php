<?php
/**
 * Short-lived storage for refused submissions.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Admin;

/**
 * Carries a refused submission across the post-save redirect.
 *
 * A transient rather than post meta: meta would live on the row, show up in
 * exports, and need explicit clearing on every successful save. This expires on
 * its own if the user simply walks away.
 */
final class Submission_Store {

	/**
	 * Transient name prefix.
	 */
	private const PREFIX = 'healthpress_rejected_';

	/**
	 * How long a refused submission survives.
	 *
	 * Long enough to outlive a redirect, short enough that abandoning the screen
	 * cleans up after itself.
	 */
	private const TTL = MINUTE_IN_SECONDS;

	/**
	 * Stores a refused submission.
	 *
	 * @param int                 $post_id    The reading being edited.
	 * @param Rejected_Submission $submission What was refused, and why.
	 */
	public function put( int $post_id, Rejected_Submission $submission ): void {
		set_transient( $this->key( $post_id ), $submission->to_array(), self::TTL );
	}

	/**
	 * Returns the stored submission and removes it.
	 *
	 * A refusal is shown once. Read early and hold the result: `admin_notices`
	 * fires before `do_meta_boxes()`, so the notice and the form must share one
	 * in-memory copy or whichever reads second gets nothing.
	 *
	 * @param int $post_id The reading being edited.
	 */
	public function take( int $post_id ): ?Rejected_Submission {
		$key    = $this->key( $post_id );
		$stored = get_transient( $key );

		delete_transient( $key );

		return is_array( $stored ) ? Rejected_Submission::from_array( $stored ) : null;
	}

	/**
	 * Builds the transient name.
	 *
	 * Keyed by user as well as post: two administrators correcting the same
	 * reading must not be shown each other's input.
	 *
	 * @param int $post_id The reading being edited.
	 */
	private function key( int $post_id ): string {
		return self::PREFIX . get_current_user_id() . '_' . $post_id;
	}
}
