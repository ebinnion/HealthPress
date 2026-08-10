<?php
/**
 * Unit conversion at the REST boundary.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Rest;

use HealthPress\Metrics\Metric_Type;
use HealthPress\Support\Unit;
use HealthPress\Support\Unit_Registry;
use InvalidArgumentException;

/**
 * Translates between the units a client speaks and the canonical units
 * everything is stored in.
 *
 * Requests name units, not fields: `?unit=lb,f` means "give me mass in pounds
 * and temperature in Fahrenheit". Resolution is by dimension, so a metric that
 * mixes a dimensioned field with a unitless one — sleep's duration beside its
 * quality score — converts the former and never touches the latter.
 *
 * Conversion happens only here. Storage is always canonical, which is what
 * keeps stored values directly comparable.
 */
final class Unit_Negotiator {

	/**
	 * Wires the catalog used to resolve unit slugs.
	 *
	 * @param Unit_Registry $units The unit catalog.
	 */
	public function __construct( private readonly Unit_Registry $units ) {}

	/**
	 * Parses a comma-separated list of unit slugs into a dimension map.
	 *
	 * @param string $csv Requested units, e.g. `lb,f`.
	 *
	 * @return array<string, Unit> Dimension value => requested unit.
	 *
	 * @throws InvalidArgumentException When a slug is unknown or two units share a dimension.
	 */
	public function parse( string $csv ): array {
		$requested = array();

		foreach ( array_filter( array_map( 'trim', explode( ',', $csv ) ) ) as $slug ) {
			if ( ! $this->units->has( $slug ) ) {
				throw new InvalidArgumentException( sprintf( 'Unknown unit "%s".', $slug ) );
			}

			$unit      = $this->units->get( $slug );
			$dimension = $unit->dimension->value;

			// "Mass in both pounds and stone" has no sensible answer.
			if ( isset( $requested[ $dimension ] ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'Cannot request both "%s" and "%s"; they measure the same dimension.',
						$requested[ $dimension ]->slug,
						$slug
					)
				);
			}

			$requested[ $dimension ] = $unit;
		}

		return $requested;
	}

	/**
	 * Converts canonical values into the requested units, for a response.
	 *
	 * @param Metric_Type              $metric    The metric being returned.
	 * @param array<string, int|float> $values    Canonical values.
	 * @param array<string, Unit>      $requested Dimension => unit, from parse().
	 *
	 * @return array<string, int|float>
	 */
	public function out( Metric_Type $metric, array $values, array $requested ): array {
		return $this->convert(
			$metric,
			$values,
			$requested,
			static fn ( Unit $unit, float $value ): float => $unit->from_canonical( $value )
		);
	}

	/**
	 * Converts incoming values into canonical units, for a write.
	 *
	 * @param Metric_Type              $metric    The metric being written.
	 * @param array<string, int|float> $values    Values as the client sent them.
	 * @param array<string, Unit>      $requested Dimension => unit, from parse().
	 *
	 * @return array<string, int|float>
	 */
	public function in( Metric_Type $metric, array $values, array $requested ): array {
		return $this->convert(
			$metric,
			$values,
			$requested,
			static fn ( Unit $unit, float $value ): float => $unit->to_canonical( $value )
		);
	}

	/**
	 * Reports the unit each of a metric's fields is expressed in.
	 *
	 * A response always says what its numbers mean, rather than leaving the
	 * client to infer it from the request.
	 *
	 * @param Metric_Type         $metric    The metric being described.
	 * @param array<string, Unit> $requested Dimension => unit, from parse().
	 *
	 * @return array<string, string|null>
	 */
	public function units_for( Metric_Type $metric, array $requested ): array {
		$units = array();

		foreach ( $metric->fields as $field ) {
			$units[ $field->key ] = $this->unit_for( $field->unit, $requested )?->slug ?? $field->unit;
		}

		return $units;
	}

	/**
	 * Applies a conversion to every field whose dimension was requested.
	 *
	 * @param Metric_Type              $metric    The metric in play.
	 * @param array<string, int|float> $values    Values to convert.
	 * @param array<string, Unit>      $requested Dimension => unit, from parse().
	 * @param callable                 $apply     Conversion to apply.
	 *
	 * @return array<string, int|float>
	 */
	private function convert( Metric_Type $metric, array $values, array $requested, callable $apply ): array {
		if ( array() === $requested ) {
			return $values;
		}

		$converted = array();

		foreach ( $values as $key => $value ) {
			$field = $metric->field( (string) $key );
			$unit  = null === $field ? null : $this->unit_for( $field->unit, $requested );

			if ( null === $unit ) {
				$converted[ $key ] = $value;

				continue;
			}

			$converted[ $key ] = round( $apply( $unit, (float) $value ), $unit->precision );
		}

		return $converted;
	}

	/**
	 * Resolves the requested unit for a field's canonical unit, if any.
	 *
	 * @param string|null         $canonical_slug The field's canonical unit slug.
	 * @param array<string, Unit> $requested      Dimension => unit, from parse().
	 */
	private function unit_for( ?string $canonical_slug, array $requested ): ?Unit {
		if ( null === $canonical_slug || ! $this->units->has( $canonical_slug ) ) {
			return null;
		}

		return $requested[ $this->units->get( $canonical_slug )->dimension->value ] ?? null;
	}
}
