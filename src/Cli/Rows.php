<?php
/**
 * Row shaping for the CLI.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Cli;

use HealthPress\Storage\Reading;
use WP_Post;

/**
 * Turns domain objects into the flat associative rows WP-CLI formats.
 *
 * Separate from the commands so the shape is testable without WP_CLI loaded, and
 * so `list` and `get` cannot disagree about what a reading looks like.
 *
 * Values are canonical, always. The REST API used to convert them at its
 * boundary through a `Unit_Negotiator`; there is no such boundary now, so every
 * number is in the unit the field declares. That is why each row carries a
 * `unit` column rather than leaving the reader to guess — a bare `72.5` is
 * ambiguous in a way `72.5 kg` is not.
 */
final class Rows {

	/**
	 * Shapes one reading.
	 *
	 * Multi-field metrics — blood pressure has systolic and diastolic — are
	 * flattened to `118/76` in a single `value` column rather than given a column
	 * each. A column per field would mean a column per *metric's* fields, which
	 * across the shipped catalog is a table mostly full of blanks.
	 *
	 * @param Reading $reading The reading to shape.
	 *
	 * @return array<string, string|int>
	 */
	public static function for_reading( Reading $reading ): array {
		$units = $reading->units();
		$parts = array();
		$unit  = '';

		foreach ( $reading->values as $key => $value ) {
			$parts[] = (string) $value;

			// Every field of a metric shares one unit in the shipped catalog.
			if ( '' === $unit && isset( $units[ $key ] ) && null !== $units[ $key ] ) {
				$unit = (string) $units[ $key ];
			}
		}

		return array(
			'id'          => $reading->id,
			'metric'      => $reading->metric->slug,
			'value'       => implode( '/', $parts ),
			'unit'        => $unit,
			'recorded_at' => $reading->recorded_at->format( 'Y-m-d H:i:s' ),
			'source'      => $reading->source,
			'note'        => $reading->note,
		);
	}

	/**
	 * The columns `reading list` shows by default.
	 *
	 * `note` is omitted: it is free text of any length and would wrap a table
	 * into unreadability. `reading get` shows it, and `--fields=` can ask for it.
	 *
	 * @return list<string>
	 */
	public static function reading_fields(): array {
		return array( 'id', 'metric', 'value', 'unit', 'recorded_at' );
	}

	/**
	 * Shapes one note.
	 *
	 * The body is reduced to a word-limited snippet for the same reason the admin
	 * list has a Snippet column: a transcript is thousands of characters and
	 * would make a table useless. `note get` returns the whole body.
	 *
	 * @param WP_Post $note  The note to shape.
	 * @param int     $words Words of body to include, or 0 for all of it.
	 *
	 * @return array<string, string|int>
	 */
	public static function for_note( WP_Post $note, int $words = 12 ): array {
		$body = (string) $note->post_content;

		return array(
			'id'       => $note->ID,
			'title'    => (string) $note->post_title,
			'kind'     => self::terms( $note->ID, 'hp_note_kind' ),
			'provider' => self::terms( $note->ID, 'hp_note_provider' ),
			'tags'     => self::terms( $note->ID, 'hp_note_tag' ),
			'date'     => (string) $note->post_date,
			'status'   => (string) $note->post_status,
			'body'     => $words > 0 ? wp_trim_words( $body, $words ) : $body,
		);
	}

	/**
	 * The columns `note list` shows by default.
	 *
	 * @return list<string>
	 */
	public static function note_fields(): array {
		return array( 'id', 'title', 'kind', 'provider', 'tags', 'date' );
	}

	/**
	 * Returns a post's term slugs in one taxonomy, comma separated.
	 *
	 * Slugs rather than names, because a slug is what the filter flags take —
	 * so a value you read out of one column can be pasted straight back into
	 * `--kind=` or `--provider=`.
	 *
	 * @param int    $post_id  The post to read.
	 * @param string $taxonomy The taxonomy to read.
	 */
	private static function terms( int $post_id, string $taxonomy ): string {
		$slugs = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $slugs ) ) {
			return '';
		}

		return implode( ',', $slugs );
	}
}
