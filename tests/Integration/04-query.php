<?php
/**
 * Exercises the query path, including its query budget, on the real driver.
 *
 * Run with: studio wp eval-file <path>
 *
 * No `declare( strict_types = 1 )` here — `wp eval-file` runs the script
 * through eval(), where a declare cannot be the first statement.
 *
 * @package HealthPress
 */

require_once __DIR__ . '/_harness.php';

use HealthPress\Plugin;
use HealthPress\Storage\Reading_Query;

wp_set_current_user( 1 );
hp_reset_readings();

$plugin    = Plugin::instance();
$repo      = $plugin->readings();
$validator = $plugin->validator();

hp_section( 'Seeding' );

$seeded = 0;

for ( $day = 1; $day <= 30; $day++ ) {
	$date = sprintf( '2026-07-%02dT07:00:00+00:00', $day );

	$weight = $validator->validate(
		array(
			'metric'      => 'weight',
			'recorded_at' => $date,
			'values'      => array( 'value' => 78 + ( $day / 10 ) ),
		)
	);

	if ( $weight->is_valid() && ! is_wp_error( $repo->create( $weight->reading ) ) ) {
		++$seeded;
	}

	$steps = $validator->validate(
		array(
			'metric'      => 'steps',
			'recorded_at' => $date,
			'values'      => array( 'value' => 5000 + ( $day * 100 ) ),
		)
	);

	if ( $steps->is_valid() && ! is_wp_error( $repo->create( $steps->reading ) ) ) {
		++$seeded;
	}
}

hp_is( 60, $seeded, 'seeded 60 readings across two metrics' );
hp_no_db_error( 'seeding ran cleanly' );

hp_section( 'Filtering by metric' );

$weights = $repo->query( new Reading_Query( metrics: array( 'weight' ), limit: 100 ) );

hp_is( 30, $weights->count(), 'filtering by metric returns only that metric' );

$slugs = array_unique( array_map( static fn ( $r ) => $r->metric->slug, $weights->items() ) );

hp_is( array( 'weight' ), array_values( $slugs ), 'no other metric leaked in' );
hp_no_db_error( 'the taxonomy filter ran cleanly' );

$both = $repo->query( new Reading_Query( metrics: array( 'weight', 'steps' ), limit: 100 ) );

hp_is( 60, $both->count(), 'several metrics can be requested at once' );

hp_section( 'Filtering by window' );

$window = $repo->query(
	new Reading_Query(
		metrics: array( 'weight' ),
		after: new DateTimeImmutable( '2026-07-10T00:00:00+00:00' ),
		before: new DateTimeImmutable( '2026-07-19T23:59:59+00:00' ),
		limit: 100,
	)
);

hp_is( 10, $window->count(), 'the window is inclusive at both ends' );
hp_no_db_error( 'the date_query ran cleanly on post_date_gmt' );

hp_section( 'Ordering' );

$newest = $repo->query( new Reading_Query( metrics: array( 'weight' ), limit: 1 ) );
$oldest = $repo->query( new Reading_Query( metrics: array( 'weight' ), limit: 1, order: 'ASC' ) );

hp_is( '2026-07-30', $newest->items()[0]->recorded_at->format( 'Y-m-d' ), 'DESC returns the newest first' );
hp_is( '2026-07-01', $oldest->items()[0]->recorded_at->format( 'Y-m-d' ), 'ASC returns the oldest first' );

hp_section( 'Paging' );

$page_one = $repo->query( new Reading_Query( metrics: array( 'weight' ), limit: 10, count_total: true ) );
$page_two = $repo->query( new Reading_Query( metrics: array( 'weight' ), limit: 10, offset: 10 ) );

hp_is( 10, $page_one->count(), 'a page holds the requested number' );
hp_is( 30, $page_one->total(), 'the total counts every match, not just the page' );
hp_ok( null === $page_two->total(), 'the total is null when it was not asked for' );
hp_ok(
	$page_one->items()[0]->id !== $page_two->items()[0]->id,
	'the offset moved the window'
);
hp_no_db_error( 'counting ran cleanly' );

hp_section( 'Latest' );

$latest = $repo->latest( 'weight' );

hp_ok( null !== $latest, 'latest() finds a reading' );
hp_is( '2026-07-30', $latest->recorded_at->format( 'Y-m-d' ), 'latest() returns the most recent' );
hp_ok( null === $repo->latest( 'spo2' ), 'latest() is null when a metric has no readings' );

hp_section( 'Query budget' );

/*
 * The property that matters is that hydration cost does not grow with page
 * size. A fixed budget would just encode today's WordPress internals; measuring
 * a small page against a large one catches the thing that would actually hurt.
 *
 * Each measurement flushes first: seeding primed the object cache in this same
 * process, and an N+1 reading from a warm cache would look free.
 */
$measure = static function ( int $limit ) use ( $repo ): int {
	wp_cache_flush();

	// Warm the registry, options, and post-type caches only.
	$repo->query( new Reading_Query( limit: 1 ) );

	$before = get_num_queries();

	$page = $repo->query( new Reading_Query( limit: $limit ) );

	foreach ( $page->items() as $reading ) {
		$reading->primary_value();
		$reading->units();
	}

	return get_num_queries() - $before;
};

$small = $measure( 5 );
$large = $measure( 50 );

printf( "      5 readings: %d queries; 50 readings: %d queries\n", $small, $large );

hp_is( $small, $large, 'hydration cost is flat in page size — nothing is queried per reading' );
hp_ok( $large <= 6, sprintf( 'a page costs a small constant number of queries (used %d)', $large ) );

hp_section( 'Cleanup' );

hp_reset_readings();

hp_is( 0, $repo->query( new Reading_Query( limit: 100 ) )->count(), 'the fixtures were removed' );

hp_done();
