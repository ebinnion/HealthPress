<?php
/**
 * Tests for the metric field value object.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Metrics;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \HealthPress\Metrics\Field
 */
final class FieldTest extends TestCase {

	public function test_it_defaults_to_a_required_unitless_number(): void {
		$field = new Field( 'quality', 'Quality' );

		$this->assertSame( Field_Type::Number, $field->type );
		$this->assertNull( $field->unit );
		$this->assertTrue( $field->required );
		$this->assertNull( $field->min );
		$this->assertNull( $field->max );
	}

	/**
	 * Field keys become part of a meta key, so they are constrained to the
	 * same shape as the metric slug they are concatenated with.
	 *
	 * @dataProvider provide_invalid_keys
	 *
	 * @param string $key Key that must be rejected.
	 */
	public function test_it_rejects_a_malformed_key( string $key ): void {
		$this->expectException( InvalidArgumentException::class );

		new Field( $key, 'Label' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_invalid_keys(): array {
		return array(
			'empty'          => array( '' ),
			'leading digit'  => array( '1systolic' ),
			'uppercase'      => array( 'Systolic' ),
			'hyphen'         => array( 'blood-pressure' ),
			'space'          => array( 'blood pressure' ),
			'leading score'  => array( '_systolic' ),
			'trailing space' => array( 'systolic ' ),
		);
	}

	/**
	 * @dataProvider provide_valid_keys
	 *
	 * @param string $key Key that must be accepted.
	 */
	public function test_it_accepts_a_well_formed_key( string $key ): void {
		$this->assertSame( $key, ( new Field( $key, 'Label' ) )->key );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_valid_keys(): array {
		return array(
			'single word'  => array( 'value' ),
			'underscored'  => array( 'resting_rate' ),
			'with a digit' => array( 'spo2' ),
		);
	}

	public function test_it_rejects_a_minimum_above_its_maximum(): void {
		$this->expectException( InvalidArgumentException::class );

		new Field( 'value', 'Value', Field_Type::Number, 'kg', 500.0, 1.0 );
	}

	public function test_it_accepts_a_minimum_equal_to_its_maximum(): void {
		$field = new Field( 'value', 'Value', Field_Type::Number, 'kg', 1.0, 1.0 );

		$this->assertSame( 1.0, $field->min );
		$this->assertSame( 1.0, $field->max );
	}

	public function test_it_rejects_a_negative_precision(): void {
		$this->expectException( InvalidArgumentException::class );

		new Field( 'value', 'Value', Field_Type::Number, 'kg', null, null, true, -1 );
	}

	/**
	 * Formatting is what fixes a value's stored representation, so it is the
	 * last thing standing between a float and what lands in the database.
	 *
	 * @dataProvider provide_formatting
	 *
	 * @param int       $precision Decimal places the field declares.
	 * @param int|float $value     Canonical value being stored.
	 * @param string    $expected  Exactly what should be written.
	 */
	public function test_it_formats_a_value_at_its_declared_precision( int $precision, int|float $value, string $expected ): void {
		$field = new Field( 'value', 'Value', Field_Type::Number, 'kg', null, null, true, $precision );

		$this->assertSame( $expected, $field->format( $value ) );
	}

	/**
	 * @return array<string, array{int, int|float, string}>
	 */
	public static function provide_formatting(): array {
		return array(
			'rounds down to two places' => array( 2, 78.199324588, '78.20' ),
			'pads to two places'        => array( 2, 78, '78.00' ),
			'pads a whole float'        => array( 2, 78.2, '78.20' ),
			'one place'                 => array( 1, 37.049, '37.0' ),
			'no places'                 => array( 0, 118, '118' ),
			'no places rounds'          => array( 0, 117.6, '118' ),
			'keeps a zero'              => array( 0, 0, '0' ),
			'keeps a negative'          => array( 1, -40.0, '-40.0' ),
		);
	}

	/**
	 * `%F` rather than `%f`: a locale with comma decimal separators would
	 * otherwise write "78,20", which is not a number to anything reading it back.
	 */
	public function test_formatting_is_locale_independent(): void {
		$previous = setlocale( LC_NUMERIC, '0' );

		if ( false === setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German_Germany' ) ) {
			$this->markTestSkipped( 'No comma-decimal locale available on this machine.' );
		}

		$field = new Field( 'value', 'Value', Field_Type::Number, 'kg', null, null, true, 2 );

		try {
			$this->assertSame( '78.20', $field->format( 78.2 ) );
		} finally {
			setlocale( LC_NUMERIC, (string) $previous );
		}
	}
}
