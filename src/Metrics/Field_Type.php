<?php
/**
 * The scalar types a metric field can hold.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Metrics;

/**
 * The type of a single measured value.
 *
 * Health data is numeric, so the catalog is deliberately just two cases: a
 * count of discrete things, and a continuous measurement.
 */
enum Field_Type: string {
	case Integer = 'integer';
	case Number  = 'number';

	/**
	 * The JSON Schema type name for this field type.
	 */
	public function json_type(): string {
		return $this->value;
	}

	/**
	 * Coerces an arbitrary input into this type, or returns null if it is not
	 * a value of this type.
	 *
	 * This is *only* a type check. Range, required-ness, and cross-field rules
	 * all live in the validator, so a value has exactly one place it can be
	 * rejected for being out of bounds.
	 *
	 * @param mixed $value Raw input.
	 */
	public function coerce( mixed $value ): int|float|null {
		/*
		 * is_numeric() would accept neither booleans nor arrays, but being
		 * explicit documents the intent: `true` must not silently become 1.
		 */
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) || null === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		if ( is_nan( $number ) || is_infinite( $number ) ) {
			return null;
		}

		return match ( $this ) {
			// Reject fractions rather than truncating a value the user never entered.
			self::Integer => floor( $number ) === $number ? (int) $number : null,
			self::Number  => $number,
		};
	}
}
