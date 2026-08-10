<?php
/**
 * Tests for unit negotiation at the REST boundary.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Rest;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Rest\Unit_Negotiator;
use HealthPress\Support\Unit_Registry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Conversion is resolved by dimension, not by field name, so `?unit=lb`
 * converts every mass-dimensioned field and leaves everything else alone.
 *
 * @covers \HealthPress\Rest\Unit_Negotiator
 */
final class UnitNegotiatorTest extends TestCase {

	private function negotiator(): Unit_Negotiator {
		return new Unit_Negotiator( Unit_Registry::create_default() );
	}

	private function weight(): Metric_Type {
		return new Metric_Type( 'weight', 'Weight', array( new Field( 'value', 'Weight', Field_Type::Number, 'kg', 1.0, 500.0 ) ) );
	}

	/**
	 * Sleep is the case that matters: a dimensioned field beside a unitless one.
	 */
	private function sleep(): Metric_Type {
		return new Metric_Type(
			'sleep',
			'Sleep',
			array(
				new Field( 'duration', 'Duration', Field_Type::Number, 'minutes', 0.0, 1440.0, true, 0 ),
				new Field( 'quality', 'Quality', Field_Type::Integer, null, 1.0, 5.0, false, 0 ),
			),
			'duration'
		);
	}

	// -----------------------------------------------------------------
	// Parsing the request parameter.
	// -----------------------------------------------------------------

	public function test_an_empty_parameter_requests_nothing(): void {
		$this->assertSame( array(), $this->negotiator()->parse( '' ) );
	}

	public function test_it_resolves_a_unit_slug_to_its_dimension(): void {
		$requested = $this->negotiator()->parse( 'lb' );

		$this->assertArrayHasKey( 'mass', $requested );
		$this->assertSame( 'lb', $requested['mass']->slug );
	}

	public function test_it_accepts_several_units_at_once(): void {
		$requested = $this->negotiator()->parse( 'lb,f' );

		$this->assertSame( 'lb', $requested['mass']->slug );
		$this->assertSame( 'f', $requested['temperature']->slug );
	}

	public function test_it_ignores_surrounding_whitespace(): void {
		$this->assertSame( 'lb', $this->negotiator()->parse( ' lb , f ' )['mass']->slug );
	}

	public function test_it_rejects_an_unknown_unit(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'furlong' );

		$this->negotiator()->parse( 'furlong' );
	}

	/**
	 * Two units of the same dimension is ambiguous — there is no sensible
	 * answer to "give me mass in both pounds and stone".
	 */
	public function test_it_rejects_two_units_of_the_same_dimension(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->negotiator()->parse( 'lb,st' );
	}

	// -----------------------------------------------------------------
	// Converting out, for responses.
	// -----------------------------------------------------------------

	public function test_it_leaves_values_canonical_when_nothing_is_requested(): void {
		$this->assertSame(
			array( 'value' => 78.2 ),
			$this->negotiator()->out( $this->weight(), array( 'value' => 78.2 ), array() )
		);
	}

	public function test_it_converts_a_value_to_the_requested_unit(): void {
		$out = $this->negotiator()->out(
			$this->weight(),
			array( 'value' => 78.19932458 ),
			$this->negotiator()->parse( 'lb' )
		);

		$this->assertEqualsWithDelta( 172.4, $out['value'], 0.01 );
	}

	public function test_it_ignores_a_dimension_the_metric_does_not_measure(): void {
		$this->assertSame(
			array( 'value' => 78.2 ),
			$this->negotiator()->out( $this->weight(), array( 'value' => 78.2 ), $this->negotiator()->parse( 'f' ) )
		);
	}

	/**
	 * The whole reason units live on the field rather than the metric.
	 */
	public function test_it_leaves_a_unitless_field_alone(): void {
		$out = $this->negotiator()->out(
			$this->sleep(),
			array(
				'duration' => 445.0,
				'quality'  => 4,
			),
			$this->negotiator()->parse( 'hours' )
		);

		// 445 minutes is 7.41666… hours, rounded to the `hours` unit's precision.
		$this->assertSame( 7.42, $out['duration'] );
		$this->assertSame( 4, $out['quality'], 'A unitless field must never be converted.' );
	}

	// -----------------------------------------------------------------
	// Converting in, for writes.
	// -----------------------------------------------------------------

	public function test_it_converts_an_incoming_value_to_canonical(): void {
		$in = $this->negotiator()->in(
			$this->weight(),
			array( 'value' => 172.4 ),
			$this->negotiator()->parse( 'lb' )
		);

		$this->assertEqualsWithDelta( 78.1993, $in['value'], 0.001 );
	}

	public function test_an_incoming_value_is_unchanged_when_no_unit_is_given(): void {
		$this->assertSame(
			array( 'value' => 78.2 ),
			$this->negotiator()->in( $this->weight(), array( 'value' => 78.2 ), array() )
		);
	}

	/**
	 * The `unit` parameter does double duty — "what I am sending" on write and
	 * "what I want back" on read — so the two directions must compose to a
	 * no-op or a write-then-read would drift.
	 */
	public function test_converting_in_then_out_is_lossless(): void {
		$negotiator = $this->negotiator();
		$requested  = $negotiator->parse( 'lb' );

		$canonical = $negotiator->in( $this->weight(), array( 'value' => 172.4 ), $requested );
		$back      = $negotiator->out( $this->weight(), $canonical, $requested );

		$this->assertEqualsWithDelta( 172.4, $back['value'], 1e-9 );
	}

	public function test_the_round_trip_holds_for_an_affine_unit(): void {
		$negotiator  = $this->negotiator();
		$requested   = $negotiator->parse( 'f' );
		$temperature = new Metric_Type(
			'body_temperature',
			'Body Temperature',
			array( new Field( 'value', 'Body Temperature', Field_Type::Number, 'c', 25.0, 45.0, true, 1 ) )
		);

		$canonical = $negotiator->in( $temperature, array( 'value' => 98.6 ), $requested );
		$back      = $negotiator->out( $temperature, $canonical, $requested );

		$this->assertEqualsWithDelta( 37.0, $canonical['value'], 0.01 );
		$this->assertEqualsWithDelta( 98.6, $back['value'], 1e-9 );
	}

	// -----------------------------------------------------------------
	// Reporting which units a payload is actually in.
	// -----------------------------------------------------------------

	public function test_it_reports_canonical_units_by_default(): void {
		$this->assertSame(
			array( 'value' => 'kg' ),
			$this->negotiator()->units_for( $this->weight(), array() )
		);
	}

	public function test_it_reports_the_requested_unit_when_one_applies(): void {
		$this->assertSame(
			array( 'value' => 'lb' ),
			$this->negotiator()->units_for( $this->weight(), $this->negotiator()->parse( 'lb' ) )
		);
	}

	public function test_it_reports_null_for_a_unitless_field(): void {
		$units = $this->negotiator()->units_for( $this->sleep(), $this->negotiator()->parse( 'hours' ) );

		$this->assertSame( 'hours', $units['duration'] );
		$this->assertNull( $units['quality'] );
	}
}
