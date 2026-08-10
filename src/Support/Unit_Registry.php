<?php
/**
 * The catalog of known units.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Support;

use InvalidArgumentException;

/**
 * An immutable catalog of units, indexed by slug.
 *
 * Invariants are enforced at construction rather than at lookup, so an
 * ambiguous catalog fails loudly at boot instead of quietly producing a wrong
 * conversion at request time.
 */
final class Unit_Registry {

	/**
	 * Units indexed by slug.
	 *
	 * @var array<string, Unit>
	 */
	private array $units = array();

	/**
	 * Canonical unit slug for each dimension, indexed by dimension value.
	 *
	 * @var array<string, string>
	 */
	private array $canonical = array();

	/**
	 * Builds a catalog, checking its invariants up front.
	 *
	 * @param list<Unit> $units Units to register.
	 *
	 * @throws InvalidArgumentException When a slug is duplicated, or a dimension has more than one canonical unit.
	 */
	public function __construct( array $units ) {
		foreach ( $units as $unit ) {
			if ( isset( $this->units[ $unit->slug ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Duplicate unit slug "%s".', $unit->slug )
				);
			}

			$this->units[ $unit->slug ] = $unit;

			if ( ! $unit->is_canonical() ) {
				continue;
			}

			$dimension = $unit->dimension->value;

			if ( isset( $this->canonical[ $dimension ] ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'Dimension "%s" already has a canonical unit ("%s"); "%s" cannot also be canonical.',
						$dimension,
						$this->canonical[ $dimension ],
						$unit->slug
					)
				);
			}

			$this->canonical[ $dimension ] = $unit->slug;
		}
	}

	/**
	 * Whether a unit slug is known.
	 *
	 * @param string $slug Unit slug.
	 */
	public function has( string $slug ): bool {
		return isset( $this->units[ $slug ] );
	}

	/**
	 * Returns a unit by slug.
	 *
	 * @param string $slug Unit slug.
	 *
	 * @throws InvalidArgumentException When the slug is not registered.
	 */
	public function get( string $slug ): Unit {
		if ( ! isset( $this->units[ $slug ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown unit "%s".', $slug ) );
		}

		return $this->units[ $slug ];
	}

	/**
	 * Returns the canonical unit for a dimension — the form readings are stored in.
	 *
	 * @param Dimension $dimension Dimension to resolve.
	 *
	 * @throws InvalidArgumentException When the dimension has no canonical unit.
	 */
	public function canonical_for( Dimension $dimension ): Unit {
		if ( ! isset( $this->canonical[ $dimension->value ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Dimension "%s" has no canonical unit.', $dimension->value )
			);
		}

		return $this->units[ $this->canonical[ $dimension->value ] ];
	}

	/**
	 * Returns every dimension represented in this catalog.
	 *
	 * @return list<Dimension>
	 */
	public function dimensions(): array {
		$seen = array();

		foreach ( $this->units as $unit ) {
			$seen[ $unit->dimension->value ] = $unit->dimension;
		}

		return array_values( $seen );
	}

	/**
	 * Returns every registered unit.
	 *
	 * @return array<string, Unit>
	 */
	public function all(): array {
		return $this->units;
	}

	/**
	 * Returns every unit sharing a dimension.
	 *
	 * @param Dimension $dimension Dimension to filter by.
	 *
	 * @return list<Unit>
	 */
	public function in_dimension( Dimension $dimension ): array {
		return array_values(
			array_filter(
				$this->units,
				static fn ( Unit $unit ): bool => $unit->dimension === $dimension
			)
		);
	}

	/**
	 * Builds the catalog shipped with the plugin.
	 *
	 * Exactly one unit per dimension is canonical (factor 1, offset 0); every
	 * other unit declares its conversion back to that one.
	 */
	public static function create_default(): self {
		/*
		 * Labels are the conventional written abbreviation, not the spelled-out
		 * name: they appear inline beside a number, where "78.2 Kilograms" reads
		 * worse than "78.2 kg". The slug stays the machine name — note that it
		 * is not always a usable label, since `mmhg` and `mg_dl` have to lose
		 * their casing and punctuation to survive as identifiers.
		 *
		 * A bare count has no abbreviation, and "Steps (count)" is noise, so its
		 * label is empty and the form omits the chip entirely.
		 */
		return new self(
			array(
				// Mass — canonical kilograms.
				new Unit( 'kg', 'kg', Dimension::Mass ),
				new Unit( 'lb', 'lb', Dimension::Mass, 0.45359237 ),
				new Unit( 'st', 'st', Dimension::Mass, 6.35029318, 0.0, 1 ),

				// Length — canonical centimetres.
				new Unit( 'cm', 'cm', Dimension::Length, 1.0, 0.0, 1 ),
				new Unit( 'in', 'in', Dimension::Length, 2.54, 0.0, 1 ),

				// Temperature — canonical Celsius. Fahrenheit is the affine case.
				new Unit( 'c', '°C', Dimension::Temperature, 1.0, 0.0, 1 ),
				new Unit( 'f', '°F', Dimension::Temperature, 5 / 9, -160 / 9, 1 ),

				// Time — canonical minutes.
				new Unit( 'minutes', 'min', Dimension::Time, 1.0, 0.0, 0 ),
				new Unit( 'hours', 'hr', Dimension::Time, 60.0, 0.0, 2 ),

				// Pressure — canonical millimetres of mercury.
				new Unit( 'mmhg', 'mmHg', Dimension::Pressure, 1.0, 0.0, 0 ),

				// Frequency — canonical beats per minute.
				new Unit( 'bpm', 'bpm', Dimension::Frequency, 1.0, 0.0, 0 ),

				// Count — canonical bare count.
				new Unit( 'count', '', Dimension::Count, 1.0, 0.0, 0 ),

				// Ratio — canonical percent.
				new Unit( 'percent', '%', Dimension::Ratio, 1.0, 0.0, 0 ),

				// Concentration — canonical mg/dL.
				new Unit( 'mg_dl', 'mg/dL', Dimension::Concentration, 1.0, 0.0, 1 ),
				new Unit( 'mmol_l', 'mmol/L', Dimension::Concentration, 18.0182, 0.0, 1 ),
			)
		);
	}
}
