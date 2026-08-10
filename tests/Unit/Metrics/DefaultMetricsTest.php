<?php
/**
 * Tests for the shipped metric catalog.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Metrics;

use HealthPress\Metrics\Default_Metrics;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Support\Unit_Registry;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * The catalog is plain data, so these tests exist to catch typos in it — a
 * misspelt unit slug or an inverted range would otherwise only surface at
 * runtime, on a write.
 *
 * Labels are the one place the catalog touches WordPress, so the translation
 * functions are stubbed to pass their input straight through.
 *
 * @covers \HealthPress\Metrics\Default_Metrics
 */
final class DefaultMetricsTest extends TestCase {

	/**
	 * Stubs the translation functions the catalog's labels rely on.
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->stubTranslationFunctions();
	}

	public function test_the_catalog_builds_without_throwing(): void {
		$this->assertContainsOnlyInstancesOf( Metric_Type::class, Default_Metrics::all() );
	}

	public function test_it_ships_the_expected_metrics(): void {
		$slugs = array_map( static fn ( Metric_Type $m ): string => $m->slug, Default_Metrics::all() );

		$this->assertSame(
			array(
				'blood_pressure',
				'weight',
				'resting_heart_rate',
				'body_temperature',
				'steps',
				'sleep',
				'blood_glucose',
				'spo2',
				'height',
			),
			$slugs
		);
	}

	public function test_every_slug_is_unique(): void {
		$slugs = array_map( static fn ( Metric_Type $m ): string => $m->slug, Default_Metrics::all() );

		$this->assertSame( $slugs, array_unique( $slugs ) );
	}

	/**
	 * Every unit named by a field must resolve, or conversion blows up on the
	 * first request that touches that metric.
	 */
	public function test_every_declared_unit_exists_in_the_catalog(): void {
		$units = Unit_Registry::create_default();

		foreach ( Default_Metrics::all() as $metric ) {
			foreach ( $metric->fields as $field ) {
				if ( ! $field->has_unit() ) {
					continue;
				}

				$this->assertTrue(
					$units->has( $field->unit ),
					"Metric '{$metric->slug}' field '{$field->key}' names unknown unit '{$field->unit}'."
				);
			}
		}
	}

	/**
	 * Readings are stored in canonical units, so a field declaring a
	 * non-canonical unit would silently store unconverted numbers.
	 */
	public function test_every_field_stores_in_its_canonical_unit(): void {
		$units = Unit_Registry::create_default();

		foreach ( Default_Metrics::all() as $metric ) {
			foreach ( $metric->fields as $field ) {
				if ( ! $field->has_unit() ) {
					continue;
				}

				$this->assertTrue(
					$units->get( $field->unit )->is_canonical(),
					"Metric '{$metric->slug}' field '{$field->key}' stores in non-canonical unit '{$field->unit}'."
				);
			}
		}
	}

	public function test_blood_pressure_records_both_numbers(): void {
		$metric = $this->metric( 'blood_pressure' );

		$this->assertSame( array( 'systolic', 'diastolic' ), $metric->field_keys() );
		$this->assertSame( 'mmhg', $metric->field( 'systolic' )->unit );
		$this->assertSame( 40.0, $metric->field( 'systolic' )->min );
		$this->assertSame( 300.0, $metric->field( 'systolic' )->max );
	}

	public function test_sleep_quality_is_optional_and_unitless(): void {
		$quality = $this->metric( 'sleep' )->field( 'quality' );

		$this->assertFalse( $quality->required );
		$this->assertNull( $quality->unit );
		$this->assertSame( 1.0, $quality->min );
		$this->assertSame( 5.0, $quality->max );
	}

	public function test_sleep_reports_duration_as_its_primary_field(): void {
		$this->assertSame( 'duration', $this->metric( 'sleep' )->primary_field_key() );
	}

	/**
	 * Returns a shipped metric by slug.
	 */
	private function metric( string $slug ): Metric_Type {
		foreach ( Default_Metrics::all() as $metric ) {
			if ( $metric->slug === $slug ) {
				return $metric;
			}
		}

		$this->fail( "Default catalog has no metric '{$slug}'." );
	}
}
