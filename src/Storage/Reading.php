<?php
/**
 * A stored reading.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Storage;

use DateTimeImmutable;
use HealthPress\Metrics\Metric_Type;

/**
 * One measurement, as read back from storage.
 *
 * Values are always in the field's canonical unit, and nothing converts them
 * any more. Conversion used to happen at the REST boundary, resolved by
 * dimension; that boundary went with the REST API, so a reading is read back in
 * the unit it was stored in and the CLI prints the unit alongside it.
 */
final readonly class Reading {

	/**
	 * Holds one measurement read back from storage.
	 *
	 * @param int                      $id          Post ID.
	 * @param Metric_Type              $metric      The metric measured.
	 * @param DateTimeImmutable        $recorded_at When the measurement was taken, in UTC.
	 * @param array<string, int|float> $values      Field key => canonical value.
	 * @param string                   $note        Free-text note.
	 * @param string                   $source      How the reading was recorded.
	 */
	public function __construct(
		public int $id,
		public Metric_Type $metric,
		public DateTimeImmutable $recorded_at,
		public array $values,
		public string $note = '',
		public string $source = 'manual',
	) {}

	/**
	 * Returns the headline value — the one shown when a reading is summarised
	 * as a single number.
	 */
	public function primary_value(): int|float|null {
		return $this->values[ $this->metric->primary_field_key() ] ?? null;
	}

	/**
	 * Returns the canonical unit slug each value is expressed in.
	 *
	 * @return array<string, string|null>
	 */
	public function units(): array {
		$units = array();

		foreach ( array_keys( $this->values ) as $key ) {
			$units[ $key ] = $this->metric->field( $key )?->unit;
		}

		return $units;
	}
}
