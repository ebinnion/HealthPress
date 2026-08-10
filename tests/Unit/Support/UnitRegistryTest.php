<?php
/**
 * Tests for the unit catalog.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Support;

use HealthPress\Support\Dimension;
use HealthPress\Support\Unit;
use HealthPress\Support\Unit_Registry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \HealthPress\Support\Unit_Registry
 */
final class UnitRegistryTest extends TestCase {

	public function test_it_returns_a_registered_unit_by_slug(): void {
		$registry = new Unit_Registry(
			array(
				new Unit( 'kg', 'Kilograms', Dimension::Mass ),
			)
		);

		$this->assertSame( 'kg', $registry->get( 'kg' )->slug );
	}

	public function test_it_reports_whether_a_slug_is_known(): void {
		$registry = new Unit_Registry( array( new Unit( 'kg', 'Kilograms', Dimension::Mass ) ) );

		$this->assertTrue( $registry->has( 'kg' ) );
		$this->assertFalse( $registry->has( 'furlong' ) );
	}

	public function test_it_throws_on_an_unknown_slug(): void {
		$registry = new Unit_Registry( array() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'furlong' );

		$registry->get( 'furlong' );
	}

	public function test_it_rejects_a_duplicate_slug(): void {
		$this->expectException( InvalidArgumentException::class );

		new Unit_Registry(
			array(
				new Unit( 'kg', 'Kilograms', Dimension::Mass ),
				new Unit( 'kg', 'Kilogrammes', Dimension::Mass ),
			)
		);
	}

	public function test_it_finds_the_canonical_unit_for_a_dimension(): void {
		$registry = new Unit_Registry(
			array(
				new Unit( 'lb', 'Pounds', Dimension::Mass, 0.45359237 ),
				new Unit( 'kg', 'Kilograms', Dimension::Mass ),
			)
		);

		$this->assertSame( 'kg', $registry->canonical_for( Dimension::Mass )->slug );
	}

	/**
	 * Two canonical units for one dimension makes conversion ambiguous, so it is
	 * rejected at construction rather than producing a silently wrong answer.
	 */
	public function test_it_rejects_two_canonical_units_in_one_dimension(): void {
		$this->expectException( InvalidArgumentException::class );

		new Unit_Registry(
			array(
				new Unit( 'kg', 'Kilograms', Dimension::Mass ),
				new Unit( 'g', 'Grams', Dimension::Mass ),
			)
		);
	}

	public function test_it_throws_when_a_dimension_has_no_canonical_unit(): void {
		$registry = new Unit_Registry( array( new Unit( 'lb', 'Pounds', Dimension::Mass, 0.45359237 ) ) );

		$this->expectException( InvalidArgumentException::class );

		$registry->canonical_for( Dimension::Mass );
	}

	public function test_the_default_catalog_covers_every_unit_the_metrics_need(): void {
		$registry = Unit_Registry::create_default();

		foreach ( array( 'kg', 'lb', 'st', 'mmhg', 'bpm', 'c', 'f', 'count', 'minutes', 'hours', 'mg_dl', 'mmol_l', 'percent', 'cm', 'in' ) as $slug ) {
			$this->assertTrue( $registry->has( $slug ), "Default catalog is missing '{$slug}'." );
		}
	}

	/**
	 * Every dimension present in the default catalog must have exactly one
	 * canonical unit, or storage has nowhere to normalise to.
	 */
	public function test_every_dimension_in_the_default_catalog_has_a_canonical_unit(): void {
		$registry = Unit_Registry::create_default();

		foreach ( $registry->dimensions() as $dimension ) {
			$this->assertTrue( $registry->canonical_for( $dimension )->is_canonical() );
		}
	}
}
