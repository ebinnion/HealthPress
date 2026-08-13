<?php
/**
 * The single point at which reading data is accepted or rejected.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Validation;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Support\Clock;

/**
 * Validates raw reading input against a metric's schema.
 *
 * This class contains no WordPress at all. That is deliberate and load-bearing:
 * REST, the repository, and any future importer all funnel through here, and
 * keeping it framework-free means the entire rule set can be exercised in
 * milliseconds with no bootstrap.
 *
 * Sanitising the note for output is *not* done here — that is a storage
 * concern, applied where the note is written, so this class stays pure.
 */
final class Reading_Validator {

	/**
	 * Longest note accepted, in characters.
	 */
	public const MAX_NOTE_LENGTH = 2000;

	/**
	 * Recognised values for a reading's `source`.
	 *
	 * `cli` arrived with the WP-CLI commands that replaced the REST API. `api` is
	 * kept rather than renamed: nothing writes it any more, but readings recorded
	 * through the old API carry it, and removing the value would make those rows
	 * fail validation when they are read back.
	 */
	public const SOURCES = array( 'manual', 'import', 'api', 'cli' );

	/**
	 * Earliest year a reading may claim, guarding against epoch-zero mistakes.
	 */
	private const EARLIEST_YEAR = 1900;

	/**
	 * Metrics indexed by slug.
	 *
	 * @var array<string, Metric_Type>
	 */
	private array $metrics = array();

	/**
	 * Builds a validator over a metric catalog.
	 *
	 * @param iterable<Metric_Type> $metrics              The known metric catalog.
	 * @param Clock                 $clock                Source of "now".
	 * @param int                   $future_grace_seconds Clock skew tolerated on future timestamps.
	 * @param DateTimeZone|null     $local_timezone       Zone used to interpret timestamps with no offset.
	 */
	public function __construct(
		iterable $metrics,
		private readonly Clock $clock,
		private readonly int $future_grace_seconds = 300,
		private readonly ?DateTimeZone $local_timezone = null,
	) {
		foreach ( $metrics as $metric ) {
			$this->metrics[ $metric->slug ] = $metric;
		}
	}

	/**
	 * Validates raw reading input.
	 *
	 * @param array<string, mixed> $input Raw input, typically straight off a REST request.
	 */
	public function validate( array $input ): Validation_Result {
		$slug = is_string( $input['metric'] ?? null ) ? $input['metric'] : '';

		/*
		 * Without a metric there is no schema to check anything else against,
		 * so this is the one rule that short-circuits rather than collecting.
		 */
		if ( ! isset( $this->metrics[ $slug ] ) ) {
			return Validation_Result::invalid(
				array(
					new Violation(
						'hp_unknown_metric',
						sprintf( 'Unknown metric "%s".', $slug ),
						null,
						array(
							'received' => $slug,
							'allowed'  => array_keys( $this->metrics ),
						)
					),
				)
			);
		}

		$metric     = $this->metrics[ $slug ];
		$violations = array();

		$values      = $this->validate_values( $metric, $input['values'] ?? null, $violations );
		$recorded_at = $this->validate_timestamp( $input['recorded_at'] ?? null, $violations );
		$note        = $this->validate_note( $input['note'] ?? '', $violations );
		$source      = $this->validate_source( $input['source'] ?? 'manual', $violations );

		if ( array() !== $violations ) {
			return Validation_Result::invalid( $violations );
		}

		return Validation_Result::valid(
			new Validated_Reading( $metric, $recorded_at, $values, $note, $source )
		);
	}

	/**
	 * Checks the value map against the metric's fields.
	 *
	 * @param Metric_Type     $metric     Metric being written.
	 * @param mixed           $raw        Raw `values` input.
	 * @param list<Violation> $violations Collected violations, appended to by reference.
	 *
	 * @return array<string, int|float> Coerced canonical values.
	 */
	private function validate_values( Metric_Type $metric, mixed $raw, array &$violations ): array {
		if ( ! is_array( $raw ) ) {
			$violations[] = new Violation(
				'hp_invalid_values',
				'The "values" property must be an object mapping field keys to numbers.',
				null,
				array( 'allowed' => $metric->field_keys() )
			);

			return array();
		}

		$allowed = $metric->field_keys();
		$unknown = array_values( array_diff( array_keys( $raw ), $allowed ) );

		/*
		 * Rejecting rather than ignoring unknown keys is what catches a typo
		 * like `vlaue`, which would otherwise write nothing and report success.
		 */
		if ( array() !== $unknown ) {
			$violations[] = new Violation(
				'hp_unknown_field',
				sprintf( 'Unknown field(s) for metric "%s": %s.', $metric->slug, implode( ', ', $unknown ) ),
				null,
				array(
					'received' => $unknown,
					'allowed'  => $allowed,
				)
			);
		}

		$values = array();

		foreach ( $metric->fields as $field ) {
			if ( ! array_key_exists( $field->key, $raw ) ) {
				if ( $field->required ) {
					$violations[] = new Violation(
						'hp_missing_field',
						sprintf( 'The field "%s" is required for metric "%s".', $field->key, $metric->slug ),
						$field->key
					);
				}

				continue;
			}

			$coerced = $field->type->coerce( $raw[ $field->key ] );

			if ( null === $coerced ) {
				$violations[] = new Violation(
					'hp_invalid_type',
					sprintf( 'The field "%s" must be %s.', $field->key, $field->type->json_type() ),
					$field->key,
					array(
						'expected' => $field->type->json_type(),
						'received' => $raw[ $field->key ],
					)
				);

				continue;
			}

			if ( ( null !== $field->min && $coerced < $field->min ) || ( null !== $field->max && $coerced > $field->max ) ) {
				$violations[] = new Violation(
					'hp_out_of_range',
					sprintf( 'The field "%s" is outside its permitted range.', $field->key ),
					$field->key,
					array(
						'min'      => $field->min,
						'max'      => $field->max,
						'received' => $coerced,
					)
				);

				continue;
			}

			$values[ $field->key ] = $coerced;
		}

		return $values;
	}

	/**
	 * Parses and bounds-checks the timestamp.
	 *
	 * @param mixed           $raw        Raw `recorded_at` input.
	 * @param list<Violation> $violations Collected violations, appended to by reference.
	 */
	private function validate_timestamp( mixed $raw, array &$violations ): DateTimeImmutable {
		$now = $this->clock->now();

		if ( null === $raw || '' === $raw ) {
			return $now;
		}

		if ( ! is_string( $raw ) ) {
			$violations[] = new Violation(
				'hp_invalid_date',
				'The "recorded_at" property must be an RFC 3339 date-time string.',
				'recorded_at'
			);

			return $now;
		}

		try {
			/*
			 * A string carrying its own offset keeps it; one without is read in
			 * the site's timezone, which is the natural reading of a value typed
			 * into a form.
			 */
			$parsed = new DateTimeImmutable( $raw, $this->local_timezone ?? new DateTimeZone( 'UTC' ) );
		} catch ( Exception ) {
			$violations[] = new Violation(
				'hp_invalid_date',
				sprintf( 'Could not interpret "%s" as a date and time.', $raw ),
				'recorded_at',
				array( 'received' => $raw )
			);

			return $now;
		}

		$parsed = $parsed->setTimezone( new DateTimeZone( 'UTC' ) );

		if ( (int) $parsed->format( 'Y' ) < self::EARLIEST_YEAR ) {
			$violations[] = new Violation(
				'hp_date_too_old',
				sprintf( 'Readings cannot be dated before %d.', self::EARLIEST_YEAR ),
				'recorded_at',
				array( 'received' => $parsed->format( DATE_ATOM ) )
			);

			return $now;
		}

		if ( $parsed->getTimestamp() > $now->getTimestamp() + $this->future_grace_seconds ) {
			$violations[] = new Violation(
				'hp_future_date',
				'Readings cannot be dated in the future.',
				'recorded_at',
				array(
					'received' => $parsed->format( DATE_ATOM ),
					'now'      => $now->format( DATE_ATOM ),
				)
			);

			return $now;
		}

		return $parsed;
	}

	/**
	 * Trims and length-checks the note.
	 *
	 * @param mixed           $raw        Raw `note` input.
	 * @param list<Violation> $violations Collected violations, appended to by reference.
	 */
	private function validate_note( mixed $raw, array &$violations ): string {
		if ( null === $raw ) {
			return '';
		}

		if ( ! is_string( $raw ) ) {
			$violations[] = new Violation( 'hp_invalid_note', 'The "note" property must be a string.', 'note' );

			return '';
		}

		$note = trim( $raw );

		if ( mb_strlen( $note ) > self::MAX_NOTE_LENGTH ) {
			$violations[] = new Violation(
				'hp_note_too_long',
				sprintf( 'Notes are limited to %d characters.', self::MAX_NOTE_LENGTH ),
				'note',
				array(
					'max'      => self::MAX_NOTE_LENGTH,
					'received' => mb_strlen( $note ),
				)
			);
		}

		return $note;
	}

	/**
	 * Checks the source against the whitelist.
	 *
	 * @param mixed           $raw        Raw `source` input.
	 * @param list<Violation> $violations Collected violations, appended to by reference.
	 */
	private function validate_source( mixed $raw, array &$violations ): string {
		if ( ! is_string( $raw ) || ! in_array( $raw, self::SOURCES, true ) ) {
			$violations[] = new Violation(
				'hp_invalid_source',
				'Unrecognised reading source.',
				'source',
				array(
					'received' => $raw,
					'allowed'  => self::SOURCES,
				)
			);

			return 'manual';
		}

		return $raw;
	}
}
