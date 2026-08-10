<?php
/**
 * Tests for the metric type value object.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Metrics;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Support\Dimension;
use HealthPress\Support\Unit_Registry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \HealthPress\Metrics\Metric_Type
 */
final class MetricTypeTest extends TestCase {

	/**
	 * Blood pressure is the canonical multi-field metric.
	 */
	private function blood_pressure(): Metric_Type {
		return new Metric_Type(
			'blood_pressure',
			'Blood Pressure',
			array(
				new Field( 'systolic', 'Systolic', Field_Type::Integer, 'mmhg', 40.0, 300.0, true, 0 ),
				new Field( 'diastolic', 'Diastolic', Field_Type::Integer, 'mmhg', 20.0, 200.0, true, 0 ),
			)
		);
	}

	public function test_it_looks_up_a_field_by_key(): void {
		$this->assertSame( 'Systolic', $this->blood_pressure()->field( 'systolic' )->label );
	}

	public function test_it_returns_null_for_an_unknown_field(): void {
		$this->assertNull( $this->blood_pressure()->field( 'pulse' ) );
	}

	public function test_it_lists_its_field_keys_in_declaration_order(): void {
		$this->assertSame( array( 'systolic', 'diastolic' ), $this->blood_pressure()->field_keys() );
	}

	public function test_the_primary_field_defaults_to_the_first_declared_field(): void {
		$this->assertSame( 'systolic', $this->blood_pressure()->primary_field_key() );
	}

	public function test_the_primary_field_can_be_declared_explicitly(): void {
		$metric = new Metric_Type(
			'sleep',
			'Sleep',
			array(
				new Field( 'quality', 'Quality', Field_Type::Integer ),
				new Field( 'duration', 'Duration', Field_Type::Number, 'minutes' ),
			),
			'duration'
		);

		$this->assertSame( 'duration', $metric->primary_field_key() );
	}

	public function test_it_rejects_a_primary_field_that_does_not_exist(): void {
		$this->expectException( InvalidArgumentException::class );

		new Metric_Type( 'weight', 'Weight', array( new Field( 'value', 'Value' ) ), 'nope' );
	}

	public function test_it_rejects_a_malformed_slug(): void {
		$this->expectException( InvalidArgumentException::class );

		new Metric_Type( 'Blood-Pressure', 'Blood Pressure', array( new Field( 'value', 'Value' ) ) );
	}

	public function test_it_rejects_a_metric_with_no_fields(): void {
		$this->expectException( InvalidArgumentException::class );

		new Metric_Type( 'weight', 'Weight', array() );
	}

	/**
	 * Duplicate keys would collapse to a single meta key, silently losing one
	 * of the two values on every write.
	 */
	public function test_it_rejects_duplicate_field_keys(): void {
		$this->expectException( InvalidArgumentException::class );

		new Metric_Type(
			'weight',
			'Weight',
			array(
				new Field( 'value', 'Value' ),
				new Field( 'value', 'Value Again' ),
			)
		);
	}

	public function test_it_reports_the_distinct_dimensions_of_its_fields(): void {
		$units = Unit_Registry::create_default();

		$this->assertSame( array( Dimension::Pressure ), $this->blood_pressure()->dimensions( $units ) );
	}

	/**
	 * Sleep mixes a dimensioned field with a unitless one; only the former can
	 * take part in unit negotiation.
	 */
	public function test_unitless_fields_contribute_no_dimension(): void {
		$metric = new Metric_Type(
			'sleep',
			'Sleep',
			array(
				new Field( 'duration', 'Duration', Field_Type::Number, 'minutes' ),
				new Field( 'quality', 'Quality', Field_Type::Integer, null, 1.0, 5.0, false, 0 ),
			)
		);

		$this->assertSame( array( Dimension::Time ), $metric->dimensions( Unit_Registry::create_default() ) );
	}

	public function test_it_lists_only_its_required_field_keys(): void {
		$metric = new Metric_Type(
			'sleep',
			'Sleep',
			array(
				new Field( 'duration', 'Duration', Field_Type::Number, 'minutes' ),
				new Field( 'quality', 'Quality', Field_Type::Integer, null, 1.0, 5.0, false, 0 ),
			)
		);

		$this->assertSame( array( 'duration' ), $metric->required_field_keys() );
	}
}
