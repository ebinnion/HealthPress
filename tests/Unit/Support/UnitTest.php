<?php
/**
 * Tests for the affine unit value object.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Support;

use HealthPress\Support\Dimension;
use HealthPress\Support\Unit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \HealthPress\Support\Unit
 */
final class UnitTest extends TestCase {

	/**
	 * Builds the pounds unit used across several cases.
	 */
	private function pounds(): Unit {
		return new Unit( 'lb', 'Pounds', Dimension::Mass, 0.45359237 );
	}

	/**
	 * Builds the Fahrenheit unit, the only affine case in the default catalog.
	 */
	private function fahrenheit(): Unit {
		return new Unit( 'f', 'Fahrenheit', Dimension::Temperature, 5 / 9, -160 / 9, 1 );
	}

	public function test_a_unit_with_no_factor_or_offset_is_canonical(): void {
		$kg = new Unit( 'kg', 'Kilograms', Dimension::Mass );

		$this->assertTrue( $kg->is_canonical() );
		$this->assertSame( 82.0, $kg->to_canonical( 82.0 ) );
		$this->assertSame( 82.0, $kg->from_canonical( 82.0 ) );
	}

	public function test_a_unit_with_a_factor_is_not_canonical(): void {
		$this->assertFalse( $this->pounds()->is_canonical() );
	}

	public function test_it_converts_pounds_to_kilograms(): void {
		$this->assertEqualsWithDelta( 78.1993245880, $this->pounds()->to_canonical( 172.4 ), 1e-8 );
	}

	public function test_mass_survives_a_round_trip(): void {
		$lb = $this->pounds();

		$this->assertEqualsWithDelta( 172.4, $lb->from_canonical( $lb->to_canonical( 172.4 ) ), 1e-9 );
	}

	public function test_it_converts_boiling_point_from_fahrenheit(): void {
		$this->assertEqualsWithDelta( 100.0, $this->fahrenheit()->to_canonical( 212.0 ), 1e-9 );
	}

	public function test_it_converts_freezing_point_to_fahrenheit(): void {
		$this->assertEqualsWithDelta( 32.0, $this->fahrenheit()->from_canonical( 0.0 ), 1e-9 );
	}

	/**
	 * -40 is the fixed point of the C/F scales. If the offset is being applied
	 * in the wrong order, or dropped, this is the case that catches it.
	 */
	public function test_minus_forty_is_the_fahrenheit_fixed_point(): void {
		$f = $this->fahrenheit();

		$this->assertEqualsWithDelta( -40.0, $f->to_canonical( -40.0 ), 1e-9 );
		$this->assertEqualsWithDelta( -40.0, $f->from_canonical( -40.0 ), 1e-9 );
	}

	public function test_temperature_survives_a_round_trip(): void {
		$f = $this->fahrenheit();

		$this->assertEqualsWithDelta( 98.6, $f->from_canonical( $f->to_canonical( 98.6 ) ), 1e-9 );
	}
}
