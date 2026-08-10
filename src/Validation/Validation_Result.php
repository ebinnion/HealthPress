<?php
/**
 * The outcome of validating a reading.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Validation;

/**
 * Either a validated reading, or the complete list of reasons there isn't one.
 *
 * Violations are collected rather than short-circuited so a client correcting
 * a form sees every problem at once.
 */
final readonly class Validation_Result {

	/**
	 * Records the outcome of a validation run.
	 *
	 * @param list<Violation>        $violations Every reason validation failed; empty when valid.
	 * @param Validated_Reading|null $reading    The validated reading, present only when valid.
	 */
	public function __construct(
		public array $violations,
		public ?Validated_Reading $reading = null,
	) {}

	/**
	 * Builds a successful result.
	 *
	 * @param Validated_Reading $reading The validated reading.
	 */
	public static function valid( Validated_Reading $reading ): self {
		return new self( array(), $reading );
	}

	/**
	 * Builds a failed result.
	 *
	 * @param list<Violation> $violations Reasons for failure.
	 */
	public static function invalid( array $violations ): self {
		return new self( array_values( $violations ) );
	}

	/**
	 * Whether the reading passed every rule.
	 */
	public function is_valid(): bool {
		return array() === $this->violations;
	}

	/**
	 * Returns the first violation carrying a given code, or null.
	 *
	 * @param string $code Violation code to look for.
	 */
	public function violation_for_code( string $code ): ?Violation {
		foreach ( $this->violations as $violation ) {
			if ( $violation->code === $code ) {
				return $violation;
			}
		}

		return null;
	}
}
