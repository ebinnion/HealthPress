<?php
/**
 * The definition of a trackable metric.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Metrics;

use HealthPress\Support\Dimension;
use HealthPress\Support\Unit_Registry;
use InvalidArgumentException;

/**
 * The schema for one kind of measurement — what fields it has, in what units,
 * within what bounds.
 *
 * Metric types are declared in code rather than authored as content, mirroring
 * how a health platform ships a fixed catalog of measurement types. Everything
 * downstream derives from this object: meta keys, validation rules, the CLI's
 * value flags, and the canonical unit each field is stored in.
 */
final readonly class Metric_Type {

	/**
	 * Slugs become part of a post meta key and a taxonomy term slug.
	 */
	private const SLUG_PATTERN = '/^[a-z][a-z0-9_]*$/';

	/**
	 * Declares a metric, checking its invariants up front.
	 *
	 * @param string      $slug          Machine name, globally unique.
	 * @param string      $label         Human-readable name.
	 * @param list<Field> $fields        At least one field, in display order.
	 * @param string|null $primary_field Key of the headline field; defaults to the first.
	 * @param string|null $description   Optional longer explanation.
	 *
	 * @throws InvalidArgumentException When the slug is malformed, there are no fields, keys collide, or the primary field is unknown.
	 */
	public function __construct(
		public string $slug,
		public string $label,
		public array $fields,
		public ?string $primary_field = null,
		public ?string $description = null,
	) {
		if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Metric slug "%s" must be lowercase snake_case starting with a letter.', $slug )
			);
		}

		if ( array() === $fields ) {
			throw new InvalidArgumentException(
				sprintf( 'Metric "%s" must declare at least one field.', $slug )
			);
		}

		$seen = array();

		foreach ( $fields as $field ) {
			if ( ! $field instanceof Field ) {
				throw new InvalidArgumentException(
					sprintf( 'Metric "%s" was given something that is not a Field.', $slug )
				);
			}

			if ( isset( $seen[ $field->key ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Metric "%s" declares the field "%s" more than once.', $slug, $field->key )
				);
			}

			$seen[ $field->key ] = true;
		}

		if ( null !== $primary_field && ! isset( $seen[ $primary_field ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Metric "%s" names "%s" as its primary field, but has no such field.', $slug, $primary_field )
			);
		}
	}

	/**
	 * Returns a field by key, or null when the metric has no such field.
	 *
	 * @param string $key Field key.
	 */
	public function field( string $key ): ?Field {
		foreach ( $this->fields as $field ) {
			if ( $field->key === $key ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Returns every field key, in declaration order.
	 *
	 * @return list<string>
	 */
	public function field_keys(): array {
		return array_map( static fn ( Field $field ): string => $field->key, $this->fields );
	}

	/**
	 * Returns the keys of fields a reading must supply.
	 *
	 * @return list<string>
	 */
	public function required_field_keys(): array {
		return array_values(
			array_map(
				static fn ( Field $field ): string => $field->key,
				array_filter( $this->fields, static fn ( Field $field ): bool => $field->required )
			)
		);
	}

	/**
	 * Returns the key of the headline field — the one shown when a reading is
	 * summarised as a single number.
	 */
	public function primary_field_key(): string {
		return $this->primary_field ?? $this->fields[0]->key;
	}

	/**
	 * Returns the distinct dimensions this metric measures in.
	 *
	 * Unitless fields contribute nothing, which is what keeps them out of unit
	 * negotiation.
	 *
	 * @param Unit_Registry $units Catalog used to resolve unit slugs.
	 *
	 * @return list<Dimension>
	 */
	public function dimensions( Unit_Registry $units ): array {
		$seen = array();

		foreach ( $this->fields as $field ) {
			if ( ! $field->has_unit() ) {
				continue;
			}

			$dimension = $units->get( $field->unit )->dimension;

			$seen[ $dimension->value ] = $dimension;
		}

		return array_values( $seen );
	}
}
