<?php
/**
 * Exercises registry-to-taxonomy syncing.
 *
 * Run with: studio wp eval-file <path>
 *
 * No `declare( strict_types = 1 )` here — `wp eval-file` runs the script
 * through eval(), where a declare cannot be the first statement.
 *
 * @package HealthPress
 */

require_once __DIR__ . '/_harness.php';

use HealthPress\Metrics\Metric_Registry;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Plugin;
use HealthPress\Storage\Registry_Sync;
use HealthPress\Storage\Taxonomy;

wp_set_current_user( 1 );

$registry = Plugin::instance()->metrics();
$sync     = Plugin::instance()->sync();

/**
 * Returns a metric's term, or null.
 *
 * @param string $slug Metric slug.
 */
function hp_term( string $slug ): ?WP_Term {
	$term = get_term_by( 'slug', $slug, Taxonomy::SLUG );

	return $term instanceof WP_Term ? $term : null;
}

hp_section( 'Every metric gets a term' );

$sync->sync();

foreach ( $registry->slugs() as $slug ) {
	hp_ok( null !== hp_term( $slug ), sprintf( 'term exists for "%s"', $slug ) );
}

hp_no_db_error( 'syncing ran cleanly' );

hp_section( 'Idempotency' );

$before = get_terms(
	array(
		'taxonomy'   => Taxonomy::SLUG,
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);

$sync->sync();

$after = get_terms(
	array(
		'taxonomy'   => Taxonomy::SLUG,
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);

hp_is( count( $before ), count( $after ), 'syncing twice creates no duplicate terms' );
hp_is( array_map( 'intval', $before ), array_map( 'intval', $after ), 'and the same term IDs survive' );

hp_section( 'A drifted term name is corrected' );

wp_update_term( hp_term( 'weight' )->term_id, Taxonomy::SLUG, array( 'name' => 'Something Else' ) );

$sync->sync();

hp_is( $registry->get( 'weight' )->label, hp_term( 'weight' )->name, 'the label is restored from the registry' );

hp_section( 'Version gating' );

update_option( Registry_Sync::VERSION_OPTION, 'not-the-current-version' );

$sync->maybe_sync();

hp_is( HEALTHPRESS_VERSION, get_option( Registry_Sync::VERSION_OPTION ), 'a version change triggers a sync' );

$term_id = hp_term( 'weight' )->term_id;

$sync->maybe_sync();

hp_is( $term_id, hp_term( 'weight' )->term_id, 'and running again at the same version changes nothing' );

hp_section( 'Writes never depend on a sync having run' );

wp_delete_term( hp_term( 'height' )->term_id, Taxonomy::SLUG );

hp_ok( null === hp_term( 'height' ), 'the term is gone' );

$recreated = $sync->ensure_term( 'height' );

hp_ok( ! is_wp_error( $recreated ), 'ensure_term() recreates it rather than failing the write' );
hp_is( (int) $recreated, (int) hp_term( 'height' )->term_id, 'and returns the id it created' );
hp_is( (int) $recreated, (int) $sync->ensure_term( 'height' ), 'calling it again returns the same term' );

$unknown = $sync->ensure_term( 'not_a_metric' );

hp_ok( is_wp_error( $unknown ), 'ensure_term() rejects an unregistered slug' );
hp_is( 'hp_unknown_metric', $unknown->get_error_code(), 'and says why' );

hp_section( 'A metric leaving the registry keeps its term and its readings' );

/*
 * Nothing is ever deleted: dropping the term would silently detach history.
 * Orphan-ness is not recorded anywhere — hydrate() recomputes it live, which is
 * what turns such a reading into hp_orphaned_reading.
 */
$reduced = array_values(
	array_filter(
		$registry->all(),
		static fn ( Metric_Type $metric ): bool => 'spo2' !== $metric->slug
	)
);

$spo2_term_id = hp_term( 'spo2' )->term_id;

( new Registry_Sync( new Metric_Registry( $reduced ), '0.0.0-test' ) )->sync();

hp_ok( null !== hp_term( 'spo2' ), 'a removed metric keeps its term' );
hp_is( $spo2_term_id, hp_term( 'spo2' )->term_id, 'and the same term, so readings stay attached' );

hp_section( 'Legacy bookkeeping is cleaned up' );

/*
 * 0.1.x autoloaded a structural hash and a slug-to-term map. Both are gone, so
 * an upgraded site should not go on carrying them.
 */
add_option( 'healthpress_registry_hash', 'stale', '', true );
add_option( 'healthpress_metric_terms', array( 'weight' => 1 ), '', true );

$sync->sync();

hp_ok( false === get_option( 'healthpress_registry_hash' ), 'the old hash option is removed on sync' );
hp_ok( false === get_option( 'healthpress_metric_terms' ), 'so is the old term map' );

// Leave the site consistent.
$sync->sync();

hp_no_db_error( 'every sync operation ran cleanly' );

hp_done();
