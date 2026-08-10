<?php
/**
 * Tests for the single validation enforcement point.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Validation;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Tests\Support\Frozen_Clock;
use HealthPress\Validation\Reading_Validator;
use HealthPress\Validation\Validation_Result;
use PHPUnit\Framework\TestCase;

/**
 * Every rule in the data layer is enforced here and nowhere else, so this is
 * the suite that has to be exhaustive.
 *
 * @covers \HealthPress\Validation\Reading_Validator
 * @covers \HealthPress\Validation\Validation_Result
 * @covers \HealthPress\Validation\Violation
 * @covers \HealthPress\Validation\Validated_Reading
 */
final class ReadingValidatorTest extends TestCase {

	private const NOW = '2026-08-09T12:00:00+00:00';

	/**
	 * Builds a validator over a small hand-rolled catalog, so these tests stay
	 * independent of whatever the shipped metric list happens to contain.
	 *
	 * @param int $grace Seconds of clock skew tolerated on future timestamps.
	 */
	private function validator( int $grace = 300 ): Reading_Validator {
		$metrics = array(
			new Metric_Type(
				'blood_pressure',
				'Blood Pressure',
				array(
					new Field( 'systolic', 'Systolic', Field_Type::Integer, 'mmhg', 40.0, 300.0, true, 0 ),
					new Field( 'diastolic', 'Diastolic', Field_Type::Integer, 'mmhg', 20.0, 200.0, true, 0 ),
				)
			),
			new Metric_Type(
				'weight',
				'Weight',
				array( new Field( 'value', 'Weight', Field_Type::Number, 'kg', 1.0, 500.0 ) )
			),
			new Metric_Type(
				'sleep',
				'Sleep',
				array(
					new Field( 'duration', 'Duration', Field_Type::Number, 'minutes', 0.0, 1440.0, true, 0 ),
					new Field( 'quality', 'Quality', Field_Type::Integer, null, 1.0, 5.0, false, 0 ),
				)
			),
		);

		return new Reading_Validator( $metrics, new Frozen_Clock( self::NOW ), $grace );
	}

	/**
	 * Asserts the result failed, and returns the codes it failed with.
	 *
	 * @return list<string>
	 */
	private function codes( Validation_Result $result ): array {
		return array_map(
			static fn ( $violation ): string => $violation->code,
			$result->violations
		);
	}

	// -----------------------------------------------------------------
	// The happy path.
	// -----------------------------------------------------------------

	public function test_it_accepts_a_well_formed_reading(): void {
		$result = $this->validator()->validate(
			array(
				'metric'      => 'blood_pressure',
				'recorded_at' => '2026-08-08T07:14:00+00:00',
				'values'      => array(
					'systolic'  => 118,
					'diastolic' => 76,
				),
			)
		);

		$this->assertTrue( $result->is_valid(), implode( ', ', $this->codes( $result ) ) );
		$this->assertSame( 'blood_pressure', $result->reading->metric->slug );
		$this->assertSame(
			array(
				'systolic'  => 118,
				'diastolic' => 76,
			),
			$result->reading->values
		);
		$this->assertSame( '2026-08-08T07:14:00+00:00', $result->reading->recorded_at->format( DATE_ATOM ) );
	}

	public function test_it_coerces_numeric_strings_on_the_way_through(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => '78.2' ),
			)
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( array( 'value' => 78.2 ), $result->reading->values );
	}

	public function test_an_omitted_timestamp_defaults_to_now(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 78.2 ),
			)
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( self::NOW, $result->reading->recorded_at->format( DATE_ATOM ) );
	}

	public function test_an_optional_field_may_be_omitted(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'sleep',
				'values' => array( 'duration' => 445 ),
			)
		);

		$this->assertTrue( $result->is_valid(), implode( ', ', $this->codes( $result ) ) );
		$this->assertSame( array( 'duration' => 445.0 ), $result->reading->values );
	}

	public function test_it_defaults_the_source_to_manual(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 78.2 ),
			)
		);

		$this->assertSame( 'manual', $result->reading->source );
	}

	// -----------------------------------------------------------------
	// Metric resolution.
	// -----------------------------------------------------------------

	public function test_it_rejects_an_unknown_metric(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'nope',
				'values' => array( 'value' => 1 ),
			)
		);

		$this->assertSame( array( 'hp_unknown_metric' ), $this->codes( $result ) );
		$this->assertNull( $result->reading );
	}

	public function test_it_rejects_a_missing_metric(): void {
		$result = $this->validator()->validate( array( 'values' => array( 'value' => 1 ) ) );

		$this->assertSame( array( 'hp_unknown_metric' ), $this->codes( $result ) );
	}

	/**
	 * Without a metric there is no schema to check anything else against, so
	 * this is the one rule that short-circuits.
	 */
	public function test_an_unknown_metric_suppresses_every_other_check(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'nope',
				'values' => 'not even an array',
				'source' => 'bogus',
			)
		);

		$this->assertCount( 1, $result->violations );
	}

	// -----------------------------------------------------------------
	// Field shape.
	// -----------------------------------------------------------------

	public function test_it_rejects_values_that_are_not_an_array(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => '78.2',
			)
		);

		$this->assertContains( 'hp_invalid_values', $this->codes( $result ) );
	}

	/**
	 * A typo like `vlaue` would otherwise write nothing at all and report success.
	 */
	public function test_it_rejects_an_unknown_field_and_names_the_alternatives(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'vlaue' => 78.2 ),
			)
		);

		$codes = $this->codes( $result );

		$this->assertContains( 'hp_unknown_field', $codes );

		$violation = $result->violation_for_code( 'hp_unknown_field' );

		$this->assertSame( array( 'vlaue' ), $violation->data['received'] );
		$this->assertSame( array( 'value' ), $violation->data['allowed'] );
	}

	public function test_it_rejects_a_missing_required_field(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'blood_pressure',
				'values' => array( 'systolic' => 118 ),
			)
		);

		$this->assertContains( 'hp_missing_field', $this->codes( $result ) );
		$this->assertSame( 'diastolic', $result->violation_for_code( 'hp_missing_field' )->field );
	}

	public function test_it_rejects_a_value_of_the_wrong_type(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'blood_pressure',
				'values' => array(
					'systolic'  => 'high',
					'diastolic' => 76,
				),
			)
		);

		$this->assertContains( 'hp_invalid_type', $this->codes( $result ) );
	}

	public function test_it_rejects_a_fraction_for_an_integer_field(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'blood_pressure',
				'values' => array(
					'systolic'  => 118.5,
					'diastolic' => 76,
				),
			)
		);

		$this->assertContains( 'hp_invalid_type', $this->codes( $result ) );
	}

	// -----------------------------------------------------------------
	// Range.
	// -----------------------------------------------------------------

	public function test_it_rejects_a_value_above_the_maximum(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'blood_pressure',
				'values' => array(
					'systolic'  => 900,
					'diastolic' => 76,
				),
			)
		);

		$violation = $result->violation_for_code( 'hp_out_of_range' );

		$this->assertSame( 'systolic', $violation->field );
		$this->assertSame( 40.0, $violation->data['min'] );
		$this->assertSame( 300.0, $violation->data['max'] );
		$this->assertSame( 900, $violation->data['received'] );
	}

	public function test_it_rejects_a_value_below_the_minimum(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 0.5 ),
			)
		);

		$this->assertContains( 'hp_out_of_range', $this->codes( $result ) );
	}

	public function test_the_bounds_are_inclusive(): void {
		foreach ( array( 1.0, 500.0 ) as $boundary ) {
			$result = $this->validator()->validate(
				array(
					'metric' => 'weight',
					'values' => array( 'value' => $boundary ),
				)
			);

			$this->assertTrue( $result->is_valid(), "Boundary {$boundary} should be accepted." );
		}
	}

	// -----------------------------------------------------------------
	// Timestamps.
	// -----------------------------------------------------------------

	public function test_it_normalises_an_offset_timestamp_to_utc(): void {
		$result = $this->validator()->validate(
			array(
				'metric'      => 'weight',
				'recorded_at' => '2026-08-08T09:14:00+02:00',
				'values'      => array( 'value' => 78.2 ),
			)
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '2026-08-08T07:14:00+00:00', $result->reading->recorded_at->format( DATE_ATOM ) );
	}

	public function test_it_rejects_an_unparseable_timestamp(): void {
		$result = $this->validator()->validate(
			array(
				'metric'      => 'weight',
				'recorded_at' => 'last tuesday-ish',
				'values'      => array( 'value' => 78.2 ),
			)
		);

		$this->assertContains( 'hp_invalid_date', $this->codes( $result ) );
	}

	/**
	 * Catches a Unix epoch zero arriving where a real date was meant.
	 */
	public function test_it_rejects_a_timestamp_before_1900(): void {
		$result = $this->validator()->validate(
			array(
				'metric'      => 'weight',
				'recorded_at' => '1899-12-31T00:00:00+00:00',
				'values'      => array( 'value' => 78.2 ),
			)
		);

		$this->assertContains( 'hp_date_too_old', $this->codes( $result ) );
	}

	public function test_it_rejects_a_future_timestamp(): void {
		$result = $this->validator()->validate(
			array(
				'metric'      => 'weight',
				'recorded_at' => '2030-01-01T00:00:00+00:00',
				'values'      => array( 'value' => 78.2 ),
			)
		);

		$this->assertContains( 'hp_future_date', $this->codes( $result ) );
	}

	/**
	 * Browser and server clocks disagree by a few seconds routinely, so a
	 * small grace window keeps honest writes from failing.
	 */
	public function test_it_tolerates_a_timestamp_inside_the_clock_skew_grace(): void {
		$result = $this->validator()->validate(
			array(
				'metric'      => 'weight',
				'recorded_at' => '2026-08-09T12:05:00+00:00',
				'values'      => array( 'value' => 78.2 ),
			)
		);

		$this->assertTrue( $result->is_valid(), implode( ', ', $this->codes( $result ) ) );
	}

	public function test_it_rejects_a_timestamp_one_second_past_the_grace(): void {
		$result = $this->validator()->validate(
			array(
				'metric'      => 'weight',
				'recorded_at' => '2026-08-09T12:05:01+00:00',
				'values'      => array( 'value' => 78.2 ),
			)
		);

		$this->assertContains( 'hp_future_date', $this->codes( $result ) );
	}

	// -----------------------------------------------------------------
	// Note and source.
	// -----------------------------------------------------------------

	public function test_it_keeps_a_note(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 78.2 ),
				'note'   => '  After a run.  ',
			)
		);

		$this->assertSame( 'After a run.', $result->reading->note );
	}

	public function test_it_rejects_an_overlong_note(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 78.2 ),
				'note'   => str_repeat( 'a', 2001 ),
			)
		);

		$this->assertContains( 'hp_note_too_long', $this->codes( $result ) );
	}

	public function test_it_accepts_a_note_at_the_length_limit(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 78.2 ),
				'note'   => str_repeat( 'a', 2000 ),
			)
		);

		$this->assertTrue( $result->is_valid() );
	}

	public function test_it_rejects_an_unrecognised_source(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 78.2 ),
				'source' => 'telepathy',
			)
		);

		$this->assertContains( 'hp_invalid_source', $this->codes( $result ) );
	}

	/**
	 * @dataProvider provide_valid_sources
	 *
	 * @param string $source An allowed source.
	 */
	public function test_it_accepts_every_whitelisted_source( string $source ): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 78.2 ),
				'source' => $source,
			)
		);

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( $source, $result->reading->source );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_valid_sources(): array {
		return array(
			'manual' => array( 'manual' ),
			'import' => array( 'import' ),
			'api'    => array( 'api' ),
		);
	}

	// -----------------------------------------------------------------
	// Reporting.
	// -----------------------------------------------------------------

	/**
	 * Rules are collected rather than short-circuited, so a client fixing a
	 * form sees every problem at once instead of one per round trip.
	 */
	public function test_it_reports_every_violation_at_once(): void {
		$result = $this->validator()->validate(
			array(
				'metric'      => 'blood_pressure',
				'recorded_at' => '2030-01-01T00:00:00+00:00',
				'values'      => array( 'systolic' => 900 ),
				'source'      => 'telepathy',
			)
		);

		$codes = $this->codes( $result );

		$this->assertContains( 'hp_out_of_range', $codes );
		$this->assertContains( 'hp_missing_field', $codes );
		$this->assertContains( 'hp_future_date', $codes );
		$this->assertContains( 'hp_invalid_source', $codes );
	}

	public function test_an_invalid_result_carries_no_reading(): void {
		$result = $this->validator()->validate(
			array(
				'metric' => 'weight',
				'values' => array( 'value' => 9000 ),
			)
		);

		$this->assertFalse( $result->is_valid() );
		$this->assertNull( $result->reading );
	}
}
