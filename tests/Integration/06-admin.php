<?php
/**
 * Drives the admin reading form through the real save path.
 *
 * Calls edit_post() with a populated $_POST, which is the highest fidelity
 * reachable from the CLI: it exercises _wp_translate_postdata(),
 * _wp_get_allowed_postdata(), add_meta(), and the real wp_update_post() and
 * save_post chain. Only redirect_post() is skipped, because it exits.
 *
 * Run with: studio wp eval-file <path>
 *
 * No `declare( strict_types = 1 )` here — `wp eval-file` runs the script
 * through eval(), where a declare cannot be the first statement.
 *
 * @package HealthPress
 */

require_once __DIR__ . '/_harness.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use HealthPress\Admin\Submission_Store;
use HealthPress\Plugin;
use HealthPress\Storage\Meta;
use HealthPress\Storage\Post_Type;
use HealthPress\Storage\Reading_Query;
use HealthPress\Storage\Taxonomy;

wp_set_current_user( 1 );
hp_reset_readings();

$plugin   = Plugin::instance();
$repo     = $plugin->readings();
$registry = $plugin->metrics();
$sync     = $plugin->sync();
$store    = new Submission_Store();

/**
 * Creates the auto-draft the editor would have created.
 */
function hp_new_editor_draft(): int {
	return (int) wp_insert_post(
		array(
			'post_type'   => Post_Type::SLUG,
			'post_status' => 'auto-draft',
			'post_title'  => '',
		)
	);
}

/**
 * Submits the edit form for a post, exactly as wp-admin/post.php would.
 *
 * @param int                  $post_id Post being saved.
 * @param array<string, mixed> $fields  POST fields, merged over the base.
 * @param string               $button  Which submit button was pressed.
 */
function hp_submit( int $post_id, array $fields, string $button = 'publish' ): void {
	$base = array(
		'post_ID'     => $post_id,
		'post_type'   => Post_Type::SLUG,
		'post_author' => 1,
	);

	$base[ $button ] = 'publish' === $button ? 'Publish' : 'Save Draft';

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- constructing the request, not reading one.
	$_POST = wp_slash( array_merge( $base, $fields ) );

	edit_post();

	$_POST = array();

	clean_post_cache( $post_id );
}

/**
 * Builds a valid form payload for a metric.
 *
 * @param int                   $post_id The post being saved.
 * @param string                $slug    Metric slug.
 * @param array<string, string> $values  Field key => submitted string.
 * @param string                $note    Optional note.
 *
 * @return array<string, mixed>
 */
function hp_form( int $post_id, string $slug, array $values, string $note = '' ): array {
	return array(
		'hp_reading_nonce' => wp_create_nonce( 'hp_save_reading_' . $post_id ),
		'hp'               => array(
			'metric' => $slug,
			'note'   => $note,
			'values' => array( $slug => $values ),
		),
	);
}

// -----------------------------------------------------------------

hp_section( 'A new reading through the form' );

$post_id = hp_new_editor_draft();

hp_submit(
	$post_id,
	hp_form(
		$post_id,
		'blood_pressure',
		array(
			'systolic'  => '118',
			'diastolic' => '76',
		),
		'Before coffee.'
	)
	// A group for a metric that was not selected must be ignored.
	+ array()
);

hp_is( 'publish', get_post_status( $post_id ), 'a valid reading publishes' );
hp_is( array( 'blood_pressure' ), wp_get_object_terms( $post_id, Taxonomy::SLUG, array( 'fields' => 'slugs' ) ), 'the metric term was assigned' );
hp_is( '118', get_post_meta( $post_id, Meta::key( $registry->get( 'blood_pressure' ), 'systolic' ), true ), 'systolic was stored' );
hp_is( '76', get_post_meta( $post_id, Meta::key( $registry->get( 'blood_pressure' ), 'diastolic' ), true ), 'diastolic was stored' );
hp_is( 'Before coffee.', get_post( $post_id )->post_content, 'the note was stored' );
hp_ok( str_starts_with( get_post( $post_id )->post_title, 'Blood Pressure' ), 'a title was generated' );
hp_ok( ! is_wp_error( $repo->get( $post_id ) ), 'it reads back as a reading' );
hp_no_db_error( 'the form save ran cleanly' );

hp_section( 'Values for an unselected metric are ignored' );

$noise = hp_new_editor_draft();

$payload                            = hp_form( $noise, 'weight', array( 'value' => '80' ) );
$payload['hp']['values']['steps']   = array( 'value' => '99999' );
$payload['hp']['values']['spo2']    = array( 'value' => '99' );

hp_submit( $noise, $payload );

hp_is( '80.00', get_post_meta( $noise, '_hp_weight_value', true ), 'the selected metric was stored' );
hp_is( '', get_post_meta( $noise, '_hp_steps_value', true ), 'a group for another metric wrote nothing' );
hp_is( '', get_post_meta( $noise, '_hp_spo2_value', true ), 'nor did a second one' );

hp_section( 'The Publish box owns the timestamp' );

$timed = hp_new_editor_draft();

hp_submit(
	$timed,
	hp_form( $timed, 'steps', array( 'value' => '8000' ) ) + array(
		/*
		 * edit_date is what core's own Publish-box JS submits when the date
		 * editor is confirmed; _wp_translate_postdata() ignores aa/mm/jj
		 * entirely without it.
		 */
		'edit_date' => '1',
		'aa'        => '2026',
		'mm'        => '07',
		'jj'        => '04',
		'hh'        => '09',
		'mn'        => '30',
		'ss'        => '00',
	)
);

hp_is( '2026-07-04 09:30:00', get_post( $timed )->post_date, 'the date set in the Publish box survived the save' );
hp_is(
	get_gmt_from_date( '2026-07-04 09:30:00' ),
	get_post( $timed )->post_date_gmt,
	'and the GMT column agrees'
);
hp_is( '2026-07-04', $repo->get( $timed )->recorded_at->setTimezone( wp_timezone() )->format( 'Y-m-d' ), 'the reading reports that measurement time' );

hp_section( 'Correcting an existing reading' );

hp_submit(
	$post_id,
	hp_form(
		$post_id,
		'blood_pressure',
		array(
			'systolic'  => '122',
			'diastolic' => '78',
		)
	)
);

hp_is( 122, $repo->get( $post_id )->values['systolic'], 'the corrected value was stored' );
hp_is( 'publish', get_post_status( $post_id ), 'and it stayed published' );
hp_ok( str_contains( get_post( $post_id )->post_title, '122' ), 'the title was regenerated' );

hp_section( 'Changing the metric from the form' );

hp_submit( $post_id, hp_form( $post_id, 'weight', array( 'value' => '81.4' ) ) );

hp_is( 'weight', $repo->get( $post_id )->metric->slug, 'the metric can be changed' );
hp_is( array( 'weight' ), wp_get_object_terms( $post_id, Taxonomy::SLUG, array( 'fields' => 'slugs' ) ), 'the term was swapped' );
hp_is( '81.40', get_post_meta( $post_id, '_hp_weight_value', true ), 'the new value was stored' );
hp_is( '', get_post_meta( $post_id, '_hp_blood_pressure_systolic', true ), 'the old metric rows were swept' );

hp_section( 'Save Draft holds a reading back' );

$held = hp_new_editor_draft();

hp_submit( $held, hp_form( $held, 'spo2', array( 'value' => '97' ) ), 'save' );

hp_is( 'draft', get_post_status( $held ), 'Save Draft leaves the reading as a draft' );
hp_is( '97', get_post_meta( $held, '_hp_spo2_value', true ), 'but the values were still stored' );

$held_listed = array_filter(
	$repo->query( new Reading_Query( limit: 100 ) )->items(),
	static fn ( $r ): bool => $r->id === $held
);

hp_is( 0, count( $held_listed ), 'and it stays out of the repository' );

hp_section( 'A rejected reading is quarantined' );

$bad = hp_new_editor_draft();

hp_submit(
	$bad,
	hp_form(
		$bad,
		'blood_pressure',
		array(
			'systolic'  => '900',
			'diastolic' => '76',
		)
	)
);

hp_is( 'draft', get_post_status( $bad ), 'a rejected reading is quarantined as a draft' );
hp_ok( is_wp_error( $repo->get( $bad ) ), 'and is not readable as a reading' );

$bad_listed = array_filter(
	$repo->query( new Reading_Query( limit: 100 ) )->items(),
	static fn ( $r ): bool => $r->id === $bad
);

hp_is( 0, count( $bad_listed ), 'and never appears in a listing' );

$rejected = $store->take( $bad );

hp_ok( null !== $rejected, 'the refusal was recorded for the notice' );
hp_is( 'hp_out_of_range', $rejected->violations[0]->code ?? '', 'with the reason' );
hp_is( '900', $rejected->value_for( 'blood_pressure', 'systolic' ), 'and the submitted value, so it need not be retyped' );
hp_ok( null === $store->take( $bad ), 'a refusal is shown only once' );

hp_section( 'A bad edit does not demote a good reading' );

$before_values = $repo->get( $post_id )->values;

hp_submit( $post_id, hp_form( $post_id, 'weight', array( 'value' => '9000' ) ) );

hp_is( 'publish', get_post_status( $post_id ), 'a reading that already passed stays published' );
hp_is( $before_values, $repo->get( $post_id )->values, 'and keeps its stored values' );

$store->take( $post_id );

hp_section( 'Non-form writes fall through the nonce guard' );

wp_update_post(
	array(
		'ID'           => $post_id,
		'post_excerpt' => 'touched by something else',
	)
);

clean_post_cache( $post_id );

hp_is( 'publish', get_post_status( $post_id ), 'a write with no submission behind it is left alone' );
hp_ok( ! is_wp_error( $repo->get( $post_id ) ), 'and the reading survives it' );

hp_section( 'REGRESSION — the core publish path cannot produce a reading' );

/*
 * The reported bug, reproduced exactly: tick a metric in the old taxonomy box,
 * press Publish, submit no reading data and no nonce.
 */
$legacy  = hp_new_editor_draft();
$term_id = (int) get_term_by( 'slug', 'blood_pressure', Taxonomy::SLUG )->term_id;

// phpcs:ignore WordPress.Security.NonceVerification.Missing -- constructing the request, not reading one.
$_POST = wp_slash(
	array(
		'post_ID'     => $legacy,
		'post_type'   => Post_Type::SLUG,
		'post_author' => 1,
		'publish'     => 'Publish',
		'tax_input'   => array( Taxonomy::SLUG => array( (string) $term_id ) ),
	)
);

edit_post();

$_POST = array();

clean_post_cache( $legacy );

hp_is( array(), wp_get_object_terms( $legacy, Taxonomy::SLUG, array( 'fields' => 'slugs' ) ), 'tax_input can no longer assign a metric' );
hp_is( 'draft', get_post_status( $legacy ), 'and a titleless reading cannot publish' );
hp_ok( is_wp_error( $repo->get( $legacy ) ), 'it is not a reading' );

$legacy_listed = array_filter(
	$repo->query( new Reading_Query( limit: 100 ) )->items(),
	static fn ( $r ): bool => $r->id === $legacy
);

hp_is( 0, count( $legacy_listed ), 'and never appears in a listing' );

hp_section( 'REGRESSION — a future date cannot smuggle a reading past the guard' );

/*
 * wp_insert_post() turns `publish` into `future` when the date is at least a
 * minute ahead (wp-includes/post.php:4709) — 176 lines BEFORE it applies the
 * `wp_insert_post_data` filter. A guard that only inspects `publish` therefore
 * never sees this write at all, and cron later promotes the row through
 * wp_publish_post(), which is a bare $wpdb->update touching no filter.
 *
 * Same shape as the bug above, with one field changed: a date an hour out.
 */
$scheduled = hp_new_editor_draft();
$ahead     = new DateTimeImmutable( '+1 hour', wp_timezone() );

// phpcs:ignore WordPress.Security.NonceVerification.Missing -- constructing the request, not reading one.
$_POST = wp_slash(
	array(
		'post_ID'     => $scheduled,
		'post_type'   => Post_Type::SLUG,
		'post_author' => 1,
		'publish'     => 'Publish',
		'edit_date'   => '1',
		'aa'          => $ahead->format( 'Y' ),
		'mm'          => $ahead->format( 'm' ),
		'jj'          => $ahead->format( 'd' ),
		'hh'          => $ahead->format( 'H' ),
		'mn'          => $ahead->format( 'i' ),
		'ss'          => '00',
	)
);

edit_post();

$_POST = array();

clean_post_cache( $scheduled );

hp_ok( 'future' !== get_post_status( $scheduled ), 'a titleless reading is not left scheduled to publish itself' );
hp_is( 'draft', get_post_status( $scheduled ), 'it is demoted like any other titleless reading' );
hp_ok(
	false === wp_next_scheduled( 'publish_future_post', array( $scheduled ) ),
	'and no cron event is left waiting to publish it'
);
hp_ok( is_wp_error( $repo->get( $scheduled ) ), 'it is not a reading' );

hp_section( 'REGRESSION — the fix reaches rows that already exist' );

/*
 * Built by hand, bypassing every guard, because this is the shape a database
 * written before the fix already contains.
 */
$preexisting = (int) wp_insert_post(
	array(
		'post_type'   => Post_Type::SLUG,
		'post_status' => 'publish',
		'post_title'  => 'Legacy row',
	)
);

wp_set_object_terms( $preexisting, array( $term_id ), Taxonomy::SLUG, false );
clean_post_cache( $preexisting );

hp_is( 'hp_incomplete_reading', $repo->get( $preexisting )->get_error_code(), 'a pre-existing hollow row is refused' );

/*
 * Read through the repository rather than the REST collection. These assertions
 * were written against `/healthpress/v1/readings`, which no longer exists; what
 * they were really checking is that a refused row is skipped on read, and the
 * repository query is where that now happens — for the CLI and every listing
 * alike.
 */
$listed = $repo->query( new Reading_Query( limit: 100 ) )->items();
$ids    = array_map( static fn ( $r ): int => $r->id, $listed );

hp_ok( ! in_array( $preexisting, $ids, true ), 'and never reaches a repository listing' );
hp_ok( ! in_array( $legacy, $ids, true ), 'nor does the one core tried to publish' );

$empty_values = array_filter(
	$listed,
	static fn ( $r ): bool => array() === $r->values
);

hp_is( 0, count( $empty_values ), 'no reading in a listing has empty values' );

hp_no_db_error( 'every admin operation ran cleanly' );

hp_reset_readings();

hp_done();
