<?php
/**
 * Exercises the repository's write path against the real SQLite driver.
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
use HealthPress\Storage\Meta;
use HealthPress\Storage\Reading_Query;
use HealthPress\Storage\Taxonomy;

wp_set_current_user( 1 );
hp_reset_readings();

$plugin    = Plugin::instance();
$repo      = $plugin->readings();
$validator = $plugin->validator();
$registry  = $plugin->metrics();
$sync      = $plugin->sync();

hp_section( 'Create' );

$result = $validator->validate(
	array(
		'metric'      => 'blood_pressure',
		'recorded_at' => '2026-08-08T07:14:00+00:00',
		'values'      => array(
			'systolic'  => 118,
			'diastolic' => 76,
		),
		'note'        => 'Before coffee.',
	)
);

hp_ok( $result->is_valid(), 'a well-formed blood pressure reading validates' );

$reading = $repo->create( $result->reading );

hp_ok( ! is_wp_error( $reading ), 'the reading was created' );

if ( is_wp_error( $reading ) ) {
	hp_done();
}

hp_is( 'blood_pressure', $reading->metric->slug, 'it came back with its metric' );
hp_is( array( 'systolic' => 118, 'diastolic' => 76 ), $reading->values, 'both values round-tripped' );
hp_is( '2026-08-08T07:14:00+00:00', $reading->recorded_at->format( DATE_ATOM ), 'the timestamp round-tripped' );
hp_is( 'Before coffee.', $reading->note, 'the note round-tripped' );
hp_is( 'manual', $reading->source, 'the source defaulted to manual' );

hp_section( 'Storage shape' );

$post = get_post( $reading->id );

hp_is( 'hp_reading', $post->post_type, 'it is stored as an hp_reading' );
hp_is( '2026-08-08 07:14:00', $post->post_date_gmt, 'post_date_gmt holds the measurement time' );
hp_ok( '' !== $post->post_title, 'a summary title was generated' );

$terms = wp_get_object_terms( $reading->id, Taxonomy::SLUG, array( 'fields' => 'slugs' ) );

hp_is( array( 'blood_pressure' ), $terms, 'the metric term was assigned' );

$metric = $registry->get( 'blood_pressure' );

hp_is( '118', get_post_meta( $reading->id, Meta::key( $metric, 'systolic' ), true ), 'systolic is stored under its namespaced key' );
hp_is( '76', get_post_meta( $reading->id, Meta::key( $metric, 'diastolic' ), true ), 'diastolic is stored under its namespaced key' );

hp_no_db_error( 'the write ran cleanly' );

hp_section( 'Precision formatting' );

$weight = $validator->validate(
	array(
		'metric' => 'weight',
		'values' => array( 'value' => 78.199324588 ),
	)
);
$stored = $repo->create( $weight->reading );

hp_is( '78.20', get_post_meta( $stored->id, '_hp_weight_value', true ), 'weight is stored at the field precision' );

hp_section( 'Read' );

$fetched = $repo->get( $reading->id );

hp_ok( ! is_wp_error( $fetched ), 'the reading can be read back by ID' );
hp_is( $reading->values, $fetched->values, 'the values match on re-read' );
hp_is( 118, $fetched->primary_value(), 'the primary value is the first declared field' );

$missing = $repo->get( 999999 );

hp_ok( is_wp_error( $missing ), 'an unknown ID is an error' );
hp_is( 404, $missing->get_error_data()['status'] ?? null, 'that error is a 404' );

$not_a_reading = $repo->get( 1 );

hp_ok( is_wp_error( $not_a_reading ), 'a post of the wrong type is not a reading' );

hp_section( 'Update' );

$updated = $repo->update( $reading->id, array( 'values' => array( 'systolic' => 122 ) ) );

hp_ok( ! is_wp_error( $updated ), 'a partial update succeeds' );
hp_is( 122, $updated->values['systolic'], 'the patched field changed' );
hp_is( 76, $updated->values['diastolic'], 'the untouched field survived the merge' );

$retimed = $repo->update( $reading->id, array( 'recorded_at' => '2026-08-07T06:00:00+00:00' ) );

hp_is( '2026-08-07T06:00:00+00:00', $retimed->recorded_at->format( DATE_ATOM ), 'the timestamp can be corrected' );
hp_is( '2026-08-07 06:00:00', get_post( $reading->id )->post_date_gmt, 'wp_update_post honoured the new date' );

hp_section( 'Update is fully revalidated' );

$rejected = $repo->update( $reading->id, array( 'values' => array( 'systolic' => 900 ) ) );

hp_ok( is_wp_error( $rejected ), 'an out-of-range patch is rejected' );
hp_is( 'hp_out_of_range', $rejected->get_error_code(), 'and reports why' );
hp_is( 122, $repo->get( $reading->id )->values['systolic'], 'the stored value was left untouched' );

$typo = $repo->update( $reading->id, array( 'values' => array( 'sistolic' => 120 ) ) );

hp_ok( is_wp_error( $typo ), 'a misspelt field is rejected rather than ignored' );

hp_section( 'Optional fields' );

$sleep = $validator->validate(
	array(
		'metric' => 'sleep',
		'values' => array(
			'duration' => 445,
			'quality'  => 4,
		),
	)
);
$slept = $repo->create( $sleep->reading );

hp_is( 4, $slept->values['quality'], 'an optional field is stored when supplied' );

hp_section( 'save() as the single write path' );

/*
 * save() is the upsert create(), update(), and the admin screen all delegate
 * to, so it has to work against a post the caller already holds.
 */
$bare_id = (int) wp_insert_post(
	array(
		'post_type'   => 'hp_reading',
		'post_status' => 'draft',
	)
);

$upserted = $repo->save(
	$bare_id,
	$validator->validate(
		array(
			'metric' => 'spo2',
			'values' => array( 'value' => 98 ),
		)
	)->reading,
	'publish'
);

hp_ok( ! is_wp_error( $upserted ), 'save() writes a validated reading over an existing post' );
hp_is( $bare_id, $upserted->id, 'and reuses that post rather than creating another' );
hp_is( 'publish', get_post_status( $bare_id ), 'the requested status was applied' );
hp_is( array( 'spo2' ), wp_get_object_terms( $bare_id, Taxonomy::SLUG, array( 'fields' => 'slugs' ) ), 'the metric term was assigned' );

hp_ok( is_wp_error( $repo->save( 999999, $sleep->reading ) ), 'save() rejects an unknown post ID' );
hp_ok( is_wp_error( $repo->save( 1, $sleep->reading ) ), 'save() rejects a post of the wrong type' );

hp_section( 'save() preserves status when none is requested' );

$held = (int) wp_insert_post(
	array(
		'post_type'   => 'hp_reading',
		'post_status' => 'draft',
	)
);

$repo->save(
	$held,
	$validator->validate(
		array(
			'metric' => 'steps',
			'values' => array( 'value' => 8000 ),
		)
	)->reading
);

hp_is( 'draft', get_post_status( $held ), 'a null status leaves the post where it was' );

/*
 * A draft reading is invisible to the repository because build_query_args()
 * filters on post_status = publish. That is what makes both "held back" and
 * "rejected" work without any new filtering.
 */
$draft_visible = array_filter(
	$repo->query( new Reading_Query( metrics: array( 'steps' ), limit: 100 ) )->items(),
	static fn ( $r ): bool => $r->id === $held
);

hp_is( 0, count( $draft_visible ), 'and keeps it out of query() results' );

hp_section( 'Changing which metric a reading measures' );

$switch = $repo->create(
	$validator->validate(
		array(
			'metric' => 'blood_pressure',
			'values' => array(
				'systolic'  => 120,
				'diastolic' => 80,
			),
		)
	)->reading
);

hp_is( '120', get_post_meta( $switch->id, '_hp_blood_pressure_systolic', true ), 'it starts as a blood pressure reading' );

$switched = $repo->update(
	$switch->id,
	array(
		'metric' => 'weight',
		'values' => array( 'value' => 80.5 ),
	)
);

hp_ok( ! is_wp_error( $switched ), 'the metric can be changed' );
hp_is( 'weight', $switched->metric->slug, 'and the reading reports the new metric' );
hp_is( array( 'weight' ), wp_get_object_terms( $switch->id, Taxonomy::SLUG, array( 'fields' => 'slugs' ) ), 'the term was swapped, not added to' );
hp_is( '80.50', get_post_meta( $switch->id, '_hp_weight_value', true ), 'the new value was stored' );

/*
 * The old metric's rows must go. Leaving them behind would strand meta under
 * keys nothing reads back, and they would reappear if the metric were switched
 * again.
 */
hp_is( '', get_post_meta( $switch->id, '_hp_blood_pressure_systolic', true ), 'the old metric systolic row was swept' );
hp_is( '', get_post_meta( $switch->id, '_hp_blood_pressure_diastolic', true ), 'the old metric diastolic row was swept' );

hp_section( 'A reading with no values is not a reading' );

/*
 * Hand-built to bypass the repository entirely — this is the shape a database
 * already contains if anything ever wrote a reading around the validator. The
 * guard has to be retroactive, not merely preventative.
 */
$hollow = (int) wp_insert_post(
	array(
		'post_type'   => 'hp_reading',
		'post_status' => 'publish',
		'post_title'  => 'Hand-built',
	)
);

wp_set_object_terms( $hollow, array( (int) get_term_by( 'slug', 'weight', Taxonomy::SLUG )->term_id ), Taxonomy::SLUG, false );
clean_post_cache( $hollow );

$hollow_read = $repo->get( $hollow );

hp_ok( is_wp_error( $hollow_read ), 'a post with a metric but no values is not readable as a reading' );
hp_is( 'hp_incomplete_reading', $hollow_read->get_error_code(), 'and says why' );
hp_is( 409, $hollow_read->get_error_data()['status'] ?? null, 'reported as a conflict, not a 404 — the row is real' );

$hollow_listed = array_filter(
	$repo->query( new Reading_Query( limit: 100 ) )->items(),
	static fn ( $r ): bool => $r->id === $hollow
);

hp_is( 0, count( $hollow_listed ), 'and it never appears in a listing' );

/*
 * The orphaned case must stay distinguishable from the incomplete one: they
 * need different fixes, so they cannot share a code.
 */
$orphan = (int) wp_insert_post(
	array(
		'post_type'   => 'hp_reading',
		'post_status' => 'publish',
		'post_title'  => 'Orphan',
	)
);

hp_is( 'hp_orphaned_reading', $repo->get( $orphan )->get_error_code(), 'a reading with no metric at all reports separately' );

wp_delete_post( $hollow, true );
wp_delete_post( $orphan, true );

hp_section( 'Delete' );

hp_ok( true === $repo->delete( $reading->id ), 'a reading can be deleted' );
hp_ok( is_wp_error( $repo->get( $reading->id ) ), 'it is gone afterwards' );
hp_ok( is_wp_error( $repo->delete( $reading->id ) ), 'deleting it again is an error' );

hp_no_db_error( 'every operation ran cleanly' );

hp_reset_readings();

hp_done();
