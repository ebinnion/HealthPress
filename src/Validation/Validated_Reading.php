<?php
/**
 * A reading that has passed validation.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Validation;

use DateTimeImmutable;
use HealthPress\Metrics\Metric_Type;

/**
 * A reading known to be well-formed, with values coerced and expressed in
 * canonical units.
 *
 * The repository accepts only this type, which makes it structurally
 * impossible to persist a reading that has not been through the validator.
 */
final readonly class Validated_Reading {

	/**
	 * Holds a reading that has cleared every rule.
	 *
	 * @param Metric_Type              $metric      The metric this reading belongs to.
	 * @param DateTimeImmutable        $recorded_at When the measurement was taken, in UTC.
	 * @param array<string, int|float> $values      Field key => canonical value.
	 * @param string                   $note        Free-text note; sanitised at the storage boundary.
	 * @param string                   $source      How the reading arrived.
	 */
	public function __construct(
		public Metric_Type $metric,
		public DateTimeImmutable $recorded_at,
		public array $values,
		public string $note = '',
		public string $source = 'manual',
	) {}
}
