<?php
/**
 * Request-to-query translation for the notes list.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes\Admin;

use DateTimeImmutable;
use HealthPress\Notes\Taxonomies;

/**
 * Turns the notes list's filter parameters into query arguments.
 *
 * This exists as its own class because the note taxonomies register
 * `query_var => false`, so `WP_Query` does not parse `?hp_note_kind=transcript`
 * on its own — verified, not assumed. Translating the parameters here means
 * one handler serves both the filter dropdowns and the taxonomy columns'
 * filter links, which build exactly those URLs.
 *
 * Static and free of WordPress state so it can be unit tested directly.
 */
final class Query_Filters {

	/**
	 * The `from` bound's parameter name.
	 */
	public const FROM = 'hp_note_from';

	/**
	 * The `to` bound's parameter name.
	 */
	public const TO = 'hp_note_to';

	/**
	 * Returns the note taxonomies that may be filtered on.
	 *
	 * @return list<string>
	 */
	public static function taxonomies(): array {
		return array( Taxonomies::KIND, Taxonomies::PROVIDER, Taxonomies::TAG );
	}

	/**
	 * Builds the `tax_query` for whichever taxonomy parameters are present.
	 *
	 * `include_children => false` throughout: the kind taxonomy is hierarchical
	 * only so that `tax_input` carries term IDs, and no kind has a parent, so
	 * descending would be work with nothing to find.
	 *
	 * @param array<string, mixed> $request Typically `$_GET`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function tax_query( array $request ): array {
		$clauses = array();

		foreach ( self::taxonomies() as $taxonomy ) {
			if ( ! isset( $request[ $taxonomy ] ) || ! is_scalar( $request[ $taxonomy ] ) ) {
				continue;
			}

			$slug = sanitize_title( (string) wp_unslash( $request[ $taxonomy ] ) );

			// '' is an unset dropdown; '0' is the Kind box's "None" option.
			if ( '' === $slug || '0' === $slug ) {
				continue;
			}

			$clauses[] = array(
				'taxonomy'         => $taxonomy,
				'field'            => 'slug',
				'terms'            => array( $slug ),
				'include_children' => false,
			);
		}

		return $clauses;
	}

	/**
	 * Builds the `date_query` for whichever bounds are present.
	 *
	 * Bounds are widened to whole days — `after` to the first second, `before`
	 * to the last — because a bare `2026-03-31` as `before` would otherwise
	 * exclude everything recorded that day.
	 *
	 * Filters `post_date`, not `post_date_gmt`: the list table's Date column
	 * shows local time, so a range typed against what is on screen has to be
	 * read in the same zone.
	 *
	 * @param array<string, mixed> $request Typically `$_GET`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function date_query( array $request ): array {
		$after  = self::bound( $request, self::FROM, ' 00:00:00' );
		$before = self::bound( $request, self::TO, ' 23:59:59' );

		if ( null === $after && null === $before ) {
			return array();
		}

		$clause = array();

		if ( null !== $after ) {
			$clause['after'] = $after;
		}

		if ( null !== $before ) {
			$clause['before'] = $before;
		}

		$clause['inclusive'] = true;
		$clause['column']    = 'post_date';

		return array( $clause );
	}

	/**
	 * Reads one date bound, or null if it is absent or malformed.
	 *
	 * Validated by round-tripping through `DateTimeImmutable` rather than by a
	 * regular expression, so `2026-13-45` is rejected as well as `not a date`.
	 * A bad bound is dropped rather than corrected: passing it through would
	 * hand `strtotime()` something whose interpretation is anyone's guess.
	 *
	 * @param array<string, mixed> $request Typically `$_GET`.
	 * @param string               $key     Parameter to read.
	 * @param string               $time    Time portion to append.
	 */
	private static function bound( array $request, string $key, string $time ): ?string {
		if ( ! isset( $request[ $key ] ) || ! is_scalar( $request[ $key ] ) ) {
			return null;
		}

		$value = trim( sanitize_text_field( (string) wp_unslash( $request[ $key ] ) ) );

		if ( '' === $value ) {
			return null;
		}

		/*
		 * The `!` resets the unparsed time fields to zero, so nothing leaks in
		 * from the current clock. Two different failures are caught here: text
		 * that does not fit the format at all returns false, while an
		 * out-of-range but well-shaped value is silently rolled over —
		 * `2026-13-45` becomes 2027-02-14 — which only the re-format comparison
		 * catches.
		 */
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		return $value . $time;
	}
}
