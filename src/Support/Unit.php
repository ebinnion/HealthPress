<?php
/**
 * A unit of measurement.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Support;

/**
 * An affine unit conversion: `canonical = raw * factor + offset`.
 *
 * The affine form is what lets Fahrenheit be expressed declaratively alongside
 * purely multiplicative units like pounds, with no special-casing:
 *
 *     new Unit( 'f', 'Fahrenheit', Dimension::Temperature, 5 / 9, -160 / 9, 1 );
 *
 * A unit with the default factor and offset is the canonical unit for its
 * dimension — the form every reading is stored in.
 */
final readonly class Unit {

	/**
	 * Declares a unit and its conversion back to the canonical one.
	 *
	 * @param string    $slug      Machine name, unique across all units.
	 * @param string    $label     Human-readable name. Translated at the edge, never hashed.
	 * @param Dimension $dimension The dimension this unit measures.
	 * @param float     $factor    Multiplier applied when converting to canonical.
	 * @param float     $offset    Constant added when converting to canonical.
	 * @param int       $precision Decimal places to present values in.
	 */
	public function __construct(
		public string $slug,
		public string $label,
		public Dimension $dimension,
		public float $factor = 1.0,
		public float $offset = 0.0,
		public int $precision = 2,
	) {}

	/**
	 * Whether this is the canonical unit for its dimension.
	 */
	public function is_canonical(): bool {
		return 1.0 === $this->factor && 0.0 === $this->offset;
	}

	/**
	 * Converts a value expressed in this unit into the canonical unit.
	 *
	 * @param float $value Value in this unit.
	 */
	public function to_canonical( float $value ): float {
		return $value * $this->factor + $this->offset;
	}

	/**
	 * Converts a canonical value into this unit.
	 *
	 * @param float $value Value in the canonical unit.
	 */
	public function from_canonical( float $value ): float {
		return ( $value - $this->offset ) / $this->factor;
	}
}
