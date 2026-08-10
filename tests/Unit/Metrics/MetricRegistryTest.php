<?php
/**
 * Tests for the metric registry.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Metrics;

use Brain\Monkey\Functions;
use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;
use HealthPress\Metrics\Metric_Registry;
use HealthPress\Metrics\Metric_Type;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @covers \HealthPress\Metrics\Metric_Registry
 */
final class MetricRegistryTest extends TestCase {

	/**
	 * Stubs the WordPress functions the registry reaches for.
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->stubTranslationFunctions();
		$this->stubEscapeFunctions();
	}

	private function weight(): Metric_Type {
		return new Metric_Type( 'weight', 'Weight', array( new Field( 'value', 'Weight', Field_Type::Number, 'kg', 1.0, 500.0 ) ) );
	}

	private function steps(): Metric_Type {
		return new Metric_Type( 'steps', 'Steps', array( new Field( 'value', 'Steps', Field_Type::Integer, 'count', 0.0, 200000.0, true, 0 ) ) );
	}

	// -----------------------------------------------------------------
	// Lookup.
	// -----------------------------------------------------------------

	public function test_it_looks_up_a_metric_by_slug(): void {
		$registry = new Metric_Registry( array( $this->weight() ) );

		$this->assertSame( 'weight', $registry->get( 'weight' )->slug );
	}

	public function test_it_returns_null_for_an_unknown_slug(): void {
		$this->assertNull( ( new Metric_Registry( array() ) )->get( 'nope' ) );
	}

	public function test_it_reports_whether_a_slug_is_known(): void {
		$registry = new Metric_Registry( array( $this->weight() ) );

		$this->assertTrue( $registry->has( 'weight' ) );
		$this->assertFalse( $registry->has( 'nope' ) );
	}

	public function test_it_lists_its_slugs_in_registration_order(): void {
		$registry = new Metric_Registry( array( $this->weight(), $this->steps() ) );

		$this->assertSame( array( 'weight', 'steps' ), $registry->slugs() );
	}

	// -----------------------------------------------------------------
	// Building from the filter.
	// -----------------------------------------------------------------

	public function test_the_filter_can_add_a_metric(): void {
		$extra = new Metric_Type( 'mood', 'Mood', array( new Field( 'value', 'Mood', Field_Type::Integer, null, 1.0, 5.0, true, 0 ) ) );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'healthpress_metrics', \Mockery::type( 'array' ) )
			->andReturnUsing(
				static fn ( string $hook, array $metrics ): array => array_merge( $metrics, array( $extra ) )
			);

		$this->assertTrue( Metric_Registry::create()->has( 'mood' ) );
	}

	/**
	 * A filter returning junk must not take down every request; the offending
	 * entry is dropped and flagged for the developer.
	 */
	public function test_it_drops_filter_entries_that_are_not_metric_types(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, array $metrics ): array => array_merge( $metrics, array( 'not a metric' ) )
		);

		$registry = Metric_Registry::create();

		$this->assertNotEmpty( $registry->slugs() );
	}

	public function test_it_drops_a_metric_whose_slug_is_already_taken(): void {
		Functions\expect( '_doing_it_wrong' )->once();

		$registry = new Metric_Registry( array( $this->weight(), $this->weight() ) );

		$this->assertSame( array( 'weight' ), $registry->slugs() );
	}
}
