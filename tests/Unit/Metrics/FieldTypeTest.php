<?php
/**
 * Tests for field type coercion.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Metrics;

use HealthPress\Metrics\Field_Type;
use PHPUnit\Framework\TestCase;

/**
 * Coercion is deliberately narrow: it answers "is this a value of my type?"
 * and nothing else. Range and required-ness live in the validator, so that
 * there is exactly one place a value can be rejected for being out of bounds.
 *
 * @covers \HealthPress\Metrics\Field_Type
 */
final class FieldTypeTest extends TestCase {

	public function test_it_exposes_its_json_schema_type(): void {
		$this->assertSame( 'integer', Field_Type::Integer->json_type() );
		$this->assertSame( 'number', Field_Type::Number->json_type() );
	}

	public function test_it_coerces_numeric_strings_to_integers(): void {
		$this->assertSame( 118, Field_Type::Integer->coerce( '118' ) );
	}

	public function test_it_passes_through_integers(): void {
		$this->assertSame( 118, Field_Type::Integer->coerce( 118 ) );
	}

	public function test_it_coerces_numeric_strings_to_floats(): void {
		$this->assertSame( 78.2, Field_Type::Number->coerce( '78.2' ) );
	}

	public function test_it_widens_integers_to_floats_for_number_fields(): void {
		$this->assertSame( 78.0, Field_Type::Number->coerce( 78 ) );
	}

	/**
	 * A step count of 1234.5 is not a step count. Silently truncating it to 1234
	 * would record a number the user never entered.
	 */
	public function test_it_rejects_a_fractional_value_for_an_integer_field(): void {
		$this->assertNull( Field_Type::Integer->coerce( 11.5 ) );
		$this->assertNull( Field_Type::Integer->coerce( '11.5' ) );
	}

	public function test_it_accepts_a_float_with_no_fractional_part_for_an_integer_field(): void {
		$this->assertSame( 11, Field_Type::Integer->coerce( 11.0 ) );
	}

	/**
	 * @dataProvider provide_non_numeric_values
	 *
	 * @param mixed $value Value that is not a number.
	 */
	public function test_it_rejects_non_numeric_values( $value ): void {
		$this->assertNull( Field_Type::Integer->coerce( $value ) );
		$this->assertNull( Field_Type::Number->coerce( $value ) );
	}

	/**
	 * PHP would happily turn `true` into 1 and `[]` into a warning; both would
	 * write a value the caller never meant.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function provide_non_numeric_values(): array {
		return array(
			'true'         => array( true ),
			'false'        => array( false ),
			'null'         => array( null ),
			'array'        => array( array() ),
			'alpha string' => array( 'abc' ),
			'empty string' => array( '' ),
			'NAN'          => array( NAN ),
			'INF'          => array( INF ),
			'-INF'         => array( -INF ),
			'object'       => array( new \stdClass() ),
		);
	}

	public function test_it_accepts_negative_and_zero_values(): void {
		$this->assertSame( 0, Field_Type::Integer->coerce( 0 ) );
		$this->assertSame( -40.0, Field_Type::Number->coerce( '-40' ) );
	}
}
