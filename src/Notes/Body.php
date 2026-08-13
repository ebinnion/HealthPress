<?php
/**
 * The note body's one sanitiser.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes;

/**
 * Sanitises a note body, in one place, for every path that writes one.
 *
 * Two paths write a body — the metabox on the editor screen and `note add` on
 * the command line — and they must not drift, because which sanitiser runs here
 * is a deliberate decision with a visible cost rather than an implementation
 * detail.
 *
 * The decision: defend rather than preserve. `sanitize_textarea_field()` keeps
 * newlines, tabs, em dashes and apostrophes exactly, but it is *not* byte-exact.
 * It HTML-encodes a lone `<` and drops anything that parses as a tag:
 *
 *     BP <120 systolic  =>  BP &lt;120 systolic
 *     HbA1c <5.7%       =>  HbA1c &lt;5.7%
 *     a<b>c             =>  ac              (content removed)
 *
 * `HbA1c <5.7%` is ordinary clinical shorthand, so this happens to real notes.
 * It is accepted because the guarantee is worth more here than fidelity: no
 * stored note can carry markup, so nothing can render one as HTML by accident,
 * however carelessly a future screen escapes its output.
 */
final class Body {

	/**
	 * Returns the body as it should be stored.
	 *
	 * Takes and returns unslashed text. Callers writing through
	 * `wp_insert_post_data` must re-slash the result, because that filter hands
	 * over slashed data and writes it as given.
	 *
	 * @param string $body The submitted body.
	 */
	public static function sanitize( string $body ): string {
		return sanitize_textarea_field( $body );
	}
}
