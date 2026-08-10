<?php
/**
 * Tests for WP_Query argument construction.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Storage;

use DateTimeImmutable;
use DateTimeZone;
use HealthPress\Storage\Post_Reading_Repository;
use HealthPress\Storage\Reading_Query;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Building the argument array is deliberately a pure function, split away from
 * running the query, so the whole matrix can be asserted without a database.
 *
 * @covers \HealthPress\Storage\Post_Reading_Repository::build_query_args
 * @covers \HealthPress\Storage\Reading_Query
 */
final class QueryArgsTest extends TestCase {

	/**
	 * Builds args for a query, without touching WordPress.
	 *
	 * @return array<string, mixed>
	 */
	private function args( Reading_Query $query ): array {
		return Post_Reading_Repository::build_query_args( $query );
	}

	private function utc( string $iso ): DateTimeImmutable {
		return new DateTimeImmutable( $iso, new DateTimeZone( 'UTC' ) );
	}

	// -----------------------------------------------------------------
	// Defaults.
	// -----------------------------------------------------------------

	public function test_it_queries_published_readings(): void {
		$args = $this->args( new Reading_Query() );

		$this->assertSame( 'hp_reading', $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );
	}

	/**
	 * post_date is covered by the type_status_date index, so ordering by it is
	 * the cheap path.
	 */
	public function test_it_orders_by_date_newest_first(): void {
		$args = $this->args( new Reading_Query() );

		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
	}

	public function test_it_applies_the_limit_and_offset(): void {
		$args = $this->args( new Reading_Query( limit: 50, offset: 100 ) );

		$this->assertSame( 50, $args['posts_per_page'] );
		$this->assertSame( 100, $args['offset'] );
	}

	// -----------------------------------------------------------------
	// Caching, and the N+1 defence.
	// -----------------------------------------------------------------

	/**
	 * Priming the meta cache once for every result ID is the entire defence
	 * against an N+1 during hydration: each later get_post_meta() is a hit.
	 */
	public function test_it_primes_the_meta_cache_for_the_whole_result_set(): void {
		$args = $this->args( new Reading_Query() );

		$this->assertTrue( $args['update_post_meta_cache'] );
		$this->assertTrue( $args['cache_results'] );
	}

	/**
	 * One query primes every result's metric term, so hydration never queries
	 * terms per reading.
	 */
	public function test_it_primes_the_term_cache(): void {
		$this->assertTrue( $this->args( new Reading_Query() )['update_post_term_cache'] );
	}

	/**
	 * The SQLite driver emulates SQL_CALC_FOUND_ROWS by running a second
	 * counting query, so totals are a real 2x cost and must be opted into.
	 */
	public function test_it_skips_counting_rows_unless_a_total_is_wanted(): void {
		$this->assertTrue( $this->args( new Reading_Query() )['no_found_rows'] );
		$this->assertFalse( $this->args( new Reading_Query( count_total: true ) )['no_found_rows'] );
	}

	// -----------------------------------------------------------------
	// Filtering by metric.
	// -----------------------------------------------------------------

	public function test_it_adds_no_taxonomy_clause_when_no_metric_is_named(): void {
		$this->assertArrayNotHasKey( 'tax_query', $this->args( new Reading_Query() ) );
	}

	public function test_it_filters_by_metric_slug(): void {
		$args = $this->args( new Reading_Query( metrics: array( 'weight' ) ) );

		$this->assertSame(
			array(
				array(
					'taxonomy'         => 'hp_metric',
					'field'            => 'slug',
					'terms'            => array( 'weight' ),
					'include_children' => false,
					'operator'         => 'IN',
				),
			),
			$args['tax_query']
		);
	}

	public function test_it_filters_by_several_metrics_at_once(): void {
		$args = $this->args( new Reading_Query( metrics: array( 'weight', 'steps' ) ) );

		$this->assertSame( array( 'weight', 'steps' ), $args['tax_query'][0]['terms'] );
	}

	// -----------------------------------------------------------------
	// Filtering by date.
	// -----------------------------------------------------------------

	public function test_it_adds_no_date_clause_when_no_window_is_given(): void {
		$this->assertArrayNotHasKey( 'date_query', $this->args( new Reading_Query() ) );
	}

	/**
	 * Always post_date_gmt, never post_date. The two are identical on a site
	 * with no timezone set, which is exactly why filtering on the local column
	 * would be an invisible bug until someone sets one.
	 */
	public function test_it_filters_on_the_gmt_column(): void {
		$args = $this->args(
			new Reading_Query(
				after: $this->utc( '2026-08-01T00:00:00+00:00' ),
				before: $this->utc( '2026-08-31T23:59:59+00:00' ),
			)
		);

		$this->assertSame( 'post_date_gmt', $args['date_query'][0]['column'] );
		$this->assertTrue( $args['date_query'][0]['inclusive'] );
		$this->assertSame( '2026-08-01 00:00:00', $args['date_query'][0]['after'] );
		$this->assertSame( '2026-08-31 23:59:59', $args['date_query'][0]['before'] );
	}

	public function test_it_accepts_an_open_ended_window(): void {
		$args = $this->args( new Reading_Query( after: $this->utc( '2026-08-01T00:00:00+00:00' ) ) );

		$this->assertSame( '2026-08-01 00:00:00', $args['date_query'][0]['after'] );
		$this->assertArrayNotHasKey( 'before', $args['date_query'][0] );
	}

	/**
	 * A caller in another timezone must not shift the window.
	 */
	public function test_it_normalises_the_window_to_utc(): void {
		$args = $this->args(
			new Reading_Query( after: new DateTimeImmutable( '2026-08-01T02:00:00+02:00' ) )
		);

		$this->assertSame( '2026-08-01 00:00:00', $args['date_query'][0]['after'] );
	}

	// -----------------------------------------------------------------
	// Reading_Query's own invariants.
	// -----------------------------------------------------------------

	public function test_it_accepts_ascending_order(): void {
		$this->assertSame( 'ASC', $this->args( new Reading_Query( order: 'asc' ) )['order'] );
	}

	public function test_it_rejects_an_unrecognised_order(): void {
		$this->expectException( InvalidArgumentException::class );

		new Reading_Query( order: 'sideways' );
	}

	public function test_it_rejects_a_negative_offset(): void {
		$this->expectException( InvalidArgumentException::class );

		new Reading_Query( offset: -1 );
	}

	public function test_it_rejects_a_limit_below_one(): void {
		$this->expectException( InvalidArgumentException::class );

		new Reading_Query( limit: 0 );
	}
}
