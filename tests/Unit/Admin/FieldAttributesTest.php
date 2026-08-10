<?php
/**
 * Tests for HTML constraint derivation.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Admin;

use HealthPress\Admin\Field_Attributes;
use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;
use PHPUnit\Framework\TestCase;

/**
 * The browser gets the same bounds the validator will apply, so a mistake is
 * caught before the round trip. These attributes are a courtesy only — every
 * one of them is re-checked server-side in Reading_Validator.
 *
 * @covers \HealthPress\Admin\Field_Attributes
 */
final class FieldAttributesTest extends TestCase {

	public function test_it_renders_a_number_input(): void {
		$this->assertSame( 'number', Field_Attributes::for( new Field( 'value', 'Value' ) )['type'] );
	}

	// -----------------------------------------------------------------
	// step — the trap.
	// -----------------------------------------------------------------

	public function test_an_integer_field_steps_by_one(): void {
		$field = new Field( 'systolic', 'Systolic', Field_Type::Integer, 'mmhg', 40.0, 300.0, true, 0 );

		$this->assertSame( '1', Field_Attributes::for( $field )['step'] );
	}

	/**
	 * The type wins over the precision. An Integer field declaring precision 2
	 * still steps by one, because a fractional integer is not a thing — and
	 * `Field_Type::coerce()` would reject it anyway.
	 */
	public function test_an_integer_field_steps_by_one_whatever_precision_it_declares(): void {
		$field = new Field( 'value', 'Value', Field_Type::Integer, null, null, null, true, 2 );

		$this->assertSame( '1', Field_Attributes::for( $field )['step'] );
	}

	public function test_a_whole_number_field_steps_by_one(): void {
		$field = new Field( 'duration', 'Duration', Field_Type::Number, 'minutes', 0.0, 1440.0, true, 0 );

		$this->assertSame( '1', Field_Attributes::for( $field )['step'] );
	}

	public function test_a_one_decimal_field_steps_by_a_tenth(): void {
		$field = new Field( 'value', 'Temperature', Field_Type::Number, 'c', 25.0, 45.0, true, 1 );

		$this->assertSame( '0.1', Field_Attributes::for( $field )['step'] );
	}

	public function test_a_two_decimal_field_steps_by_a_hundredth(): void {
		$field = new Field( 'value', 'Weight', Field_Type::Number, 'kg', 1.0, 500.0, true, 2 );

		$this->assertSame( '0.01', Field_Attributes::for( $field )['step'] );
	}

	// -----------------------------------------------------------------
	// Bounds.
	// -----------------------------------------------------------------

	public function test_it_renders_bounds_at_the_field_precision(): void {
		$attributes = Field_Attributes::for( new Field( 'value', 'Weight', Field_Type::Number, 'kg', 1.0, 500.0, true, 2 ) );

		$this->assertSame( '1.00', $attributes['min'] );
		$this->assertSame( '500.00', $attributes['max'] );
	}

	public function test_it_omits_a_bound_that_is_not_declared(): void {
		$attributes = Field_Attributes::for( new Field( 'value', 'Value' ) );

		$this->assertArrayNotHasKey( 'min', $attributes );
		$this->assertArrayNotHasKey( 'max', $attributes );
	}

	public function test_it_renders_only_the_bound_that_exists(): void {
		$attributes = Field_Attributes::for( new Field( 'value', 'Value', Field_Type::Integer, null, 0.0, null, true, 0 ) );

		$this->assertSame( '0', $attributes['min'] );
		$this->assertArrayNotHasKey( 'max', $attributes );
	}

	// -----------------------------------------------------------------
	// required.
	// -----------------------------------------------------------------

	public function test_a_required_field_is_marked_required(): void {
		$this->assertArrayHasKey( 'required', Field_Attributes::for( new Field( 'value', 'Value' ) ) );
	}

	/**
	 * Sleep's quality score is the optional one in the shipped catalog.
	 */
	public function test_an_optional_field_is_not_marked_required(): void {
		$field = new Field( 'quality', 'Quality', Field_Type::Integer, null, 1.0, 5.0, false, 0 );

		$this->assertArrayNotHasKey( 'required', Field_Attributes::for( $field ) );
	}
}
