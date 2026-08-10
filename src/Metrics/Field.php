<?php
/**
 * A single measured value within a metric.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Metrics;

use InvalidArgumentException;

/**
 * One measured value belonging to a metric — `systolic`, `weight`, `duration`.
 *
 * Units live here rather than on the metric because a metric can mix them:
 * sleep records a duration in minutes alongside a unitless quality score, and
 * converting the response to hours must touch the former and not the latter.
 */
final readonly class Field {

	/**
	 * Field keys are concatenated into a post meta key, so they are restricted
	 * to the same lowercase snake_case shape as metric slugs.
	 */
	private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

	/**
	 * Declares one measured value.
	 *
	 * @param string      $key         Machine name, unique within its metric.
	 * @param string      $label       Human-readable name.
	 * @param Field_Type  $type        Scalar type of the value.
	 * @param string|null $unit       Canonical unit slug, or null when unitless.
	 * @param float|null  $min         Inclusive lower bound, in canonical units.
	 * @param float|null  $max         Inclusive upper bound, in canonical units.
	 * @param bool        $required    Whether a reading must supply this field.
	 * @param int         $precision   Decimal places used when storing the value.
	 * @param string|null $description Optional longer explanation.
	 *
	 * @throws InvalidArgumentException When the key is malformed, the bounds are inverted, or precision is negative.
	 */
	public function __construct(
		public string $key,
		public string $label,
		public Field_Type $type = Field_Type::Number,
		public ?string $unit = null,
		public ?float $min = null,
		public ?float $max = null,
		public bool $required = true,
		public int $precision = 2,
		public ?string $description = null,
	) {
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Field key "%s" must be lowercase snake_case starting with a letter.', $key )
			);
		}

		if ( null !== $min && null !== $max && $min > $max ) {
			throw new InvalidArgumentException(
				sprintf( 'Field "%s" has a minimum (%s) above its maximum (%s).', $key, $min, $max )
			);
		}

		if ( $precision < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'Field "%s" has a negative precision.', $key )
			);
		}
	}

	/**
	 * Whether this field carries a unit and can therefore be converted.
	 */
	public function has_unit(): bool {
		return null !== $this->unit;
	}

	/**
	 * Formats a canonical value for storage.
	 *
	 * Uppercase `%F` is locale-independent, so a site running a locale with
	 * comma decimal separators still writes `78.20` rather than `78,20`.
	 *
	 * @param int|float $value Canonical value.
	 */
	public function format( int|float $value ): string {
		return sprintf( '%.' . $this->precision . 'F', $value );
	}
}
