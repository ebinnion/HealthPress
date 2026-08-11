<?php
/**
 * Exercises the note post type, taxonomies, and seeding.
 *
 * Run with: studio wp eval-file wp-content/plugins/healthpress/tests/Integration/07-notes.php
 *
 * No `declare( strict_types = 1 )` here — `wp eval-file` runs the script
 * through eval(), where a declare cannot be the first statement.
 *
 * @package HealthPress
 */

require_once __DIR__ . '/_harness.php';

use HealthPress\Notes\Admin\Query_Filters;
use HealthPress\Notes\Default_Kinds;
use HealthPress\Notes\Kind_Seeder;
use HealthPress\Notes\Post_Type;
use HealthPress\Notes\Taxonomies;
use HealthPress\Storage\Post_Type as Reading_Post_Type;
use HealthPress\Storage\Taxonomy as Metric_Taxonomy;

wp_set_current_user( 1 );

hp_section( 'Registration' );

hp_ok( post_type_exists( Post_Type::SLUG ), 'the hp_note post type is registered' );

$type = get_post_type_object( Post_Type::SLUG );

hp_is( false, $type->public, 'notes are not public' );
hp_is( false, $type->publicly_queryable, 'notes are not publicly queryable' );
hp_is( true, $type->exclude_from_search, 'notes are out of front-end search' );
hp_is( false, $type->show_in_rest, 'notes are not on the core REST API' );
hp_ok( post_type_supports( Post_Type::SLUG, 'title' ), 'notes support a title' );
hp_ok( post_type_supports( Post_Type::SLUG, 'revisions' ), 'notes support revisions' );
hp_ok( ! post_type_supports( Post_Type::SLUG, 'editor' ), 'notes do NOT support the editor' );

foreach ( array( Taxonomies::KIND, Taxonomies::PROVIDER, Taxonomies::TAG ) as $taxonomy ) {
	hp_ok( taxonomy_exists( $taxonomy ), sprintf( 'the %s taxonomy is registered', $taxonomy ) );
	hp_ok(
		in_array( $taxonomy, get_object_taxonomies( Post_Type::SLUG ), true ),
		sprintf( '%s is attached to hp_note', $taxonomy )
	);
}

hp_is( true, get_taxonomy( Taxonomies::KIND )->hierarchical, 'kind is hierarchical, so tax_input carries IDs' );
hp_is( false, get_taxonomy( Taxonomies::PROVIDER )->hierarchical, 'provider is flat, so core renders a tag box' );
hp_is( false, get_taxonomy( Taxonomies::TAG )->hierarchical, 'tag is flat, for the same reason' );

/*
 * Guards the single-select Kind metabox. With `hierarchical => true` above, a
 * regression to core's default callback renders a checkbox list, which would let
 * a note carry several kinds — contradicting "one per note" and the whole reason
 * Admin\Kind_Metabox exists.
 */
hp_is( false, get_taxonomy( Taxonomies::KIND )->meta_box_cb, 'kind suppresses core metabox, so the single select stands' );

hp_section( 'The privacy posture actually holds' );

/*
 * The assertions above read back the registration arrays. These assert the
 * mechanisms core itself selects on, which is the difference between "the flag
 * is set" and "the door is shut". Same shape as 01-registration.php, which
 * asserts absence from the `show_ui` set rather than the flag alone.
 */
hp_ok(
	! in_array( Post_Type::SLUG, get_post_types( array( 'exclude_from_search' => false ) ), true ),
	'a front-end search cannot reach a note'
);

// Core registers a /wp/v2 route per show_in_rest type; '' means there is none.
hp_is( '', rest_get_route_for_post_type_items( Post_Type::SLUG ), 'there is no core REST route for notes' );

foreach ( array( Taxonomies::KIND, Taxonomies::PROVIDER, Taxonomies::TAG ) as $taxonomy ) {
	hp_is( '', rest_get_route_for_taxonomy_items( $taxonomy ), sprintf( 'nor for %s', $taxonomy ) );
}

/*
 * The inversion the whole notes design rests on. `hp_metric` denies
 * `assign_terms` to remove core's second write path into readings; the note
 * taxonomies grant it, because `wp_insert_post()` gates its entire `tax_input`
 * path on that capability and the Kind metabox depends on core doing the saving.
 * The metric check is the positive control — without it this would still pass if
 * the current user simply had every capability.
 */
foreach ( array( Taxonomies::KIND, Taxonomies::PROVIDER, Taxonomies::TAG ) as $taxonomy ) {
	hp_ok(
		current_user_can( get_taxonomy( $taxonomy )->cap->assign_terms ),
		sprintf( 'an admin may assign %s', $taxonomy )
	);
}

hp_ok(
	! current_user_can( get_taxonomy( Metric_Taxonomy::SLUG )->cap->assign_terms ),
	'while metric terms stay unassignable, so the contrast is real'
);

hp_ok(
	'edit.php?post_type=' . Reading_Post_Type::SLUG === get_post_type_object( Post_Type::SLUG )->show_in_menu,
	'notes nest under the HealthPress menu rather than adding a second one'
);

hp_section( 'Kind seeding' );

Kind_Seeder::seed();
hp_no_db_error( 'seeding runs without a database error' );

foreach ( array_keys( Default_Kinds::all() ) as $slug ) {
	hp_ok( null !== term_exists( $slug, Taxonomies::KIND ), sprintf( 'the %s kind exists', $slug ) );
}

/*
 * The seed() call above only proves the read path once the four terms exist,
 * which on any site that has been activated is always — so on its own it stops
 * covering the insert. A scratch term exercises the write against the real
 * SQLite driver.
 *
 * Deliberately not done by deleting the four and re-seeding: that would churn
 * term IDs and unfile any note carrying one, which is exactly what
 * Kind_Seeder's docblock refuses to do.
 */
$scratch = wp_insert_term( 'HP scratch kind', Taxonomies::KIND, array( 'slug' => 'hp_scratch_kind' ) );

hp_ok( ! is_wp_error( $scratch ), 'a kind term inserts without error' );
hp_no_db_error( 'inserting a kind term runs without a database error' );

if ( ! is_wp_error( $scratch ) ) {
	wp_delete_term( (int) $scratch['term_id'], Taxonomies::KIND );
	hp_no_db_error( 'deleting a kind term runs without a database error' );
}

$before = wp_count_terms( array( 'taxonomy' => Taxonomies::KIND, 'hide_empty' => false ) );

Kind_Seeder::seed();

$after = wp_count_terms( array( 'taxonomy' => Taxonomies::KIND, 'hide_empty' => false ) );

hp_is( $before, $after, 'seeding twice inserts nothing the second time' );

hp_section( 'Publish_Guard leaves notes alone' );

/*
 * Publish_Guard demotes a titleless hp_reading out of the published set. It
 * early-returns on any other post type, and a note may legitimately have no
 * title, so this asserts the guard stays scoped.
 */
$titleless = wp_insert_post(
	array(
		'post_type'    => Post_Type::SLUG,
		'post_status'  => 'publish',
		'post_title'   => '',
		'post_content' => 'A note with no title.',
	)
);

hp_is( 'publish', get_post_status( $titleless ), 'a titleless note still publishes' );

wp_delete_post( (int) $titleless, true );

hp_section( 'The save path, through the real edit form' );

require_once ABSPATH . 'wp-admin/includes/admin.php';

/**
 * Deletes every note, so this section starts from a known state.
 */
function hp_reset_notes(): void {
	$ids = get_posts(
		array(
			'post_type'      => Post_Type::SLUG,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

/**
 * Submits the note edit form, exactly as wp-admin/post.php would.
 *
 * `edit_post()` is the highest fidelity reachable from the CLI: it runs
 * `_wp_translate_postdata()`, `_wp_get_allowed_postdata()`, the real
 * `wp_update_post()`, and the whole `save_post` chain. Only `redirect_post()`
 * is skipped, because it exits.
 *
 * @param int                  $post_id Note being saved.
 * @param array<string, mixed> $fields  POST fields merged over the base.
 */
function hp_submit_note( int $post_id, array $fields ): void {
	$_POST = array_merge(
		array(
			'post_ID'            => $post_id,
			'post_type'          => Post_Type::SLUG,
			'post_title'         => 'Cardiology call',
			'post_status'        => 'publish',
			'publish'            => 'Publish',
			'hp_note_body_nonce' => wp_create_nonce( 'hp_note_save_body' ),
			'_wpnonce'           => wp_create_nonce( 'update-post_' . $post_id ),
		),
		$fields
	);

	$_REQUEST = $_POST;

	edit_post( $_POST );

	$_POST    = array();
	$_REQUEST = array();
}

hp_reset_notes();

$note_id = (int) wp_insert_post(
	array(
		'post_type'   => Post_Type::SLUG,
		'post_status' => 'auto-draft',
		'post_title'  => '',
	)
);

$transcript = "Dr: How's the blood pressure?\nMe: It's been steady — around 118/76.";

hp_submit_note( $note_id, array( 'hp_note_body' => $transcript ) );
hp_no_db_error( 'saving a note runs without a database error' );

hp_is( $transcript, get_post_field( 'post_content', $note_id ), 'the body lands in post_content unchanged (no angle brackets in it)' );
hp_is( 'publish', get_post_status( $note_id ), 'the note publishes' );

/*
 * The apostrophes are the point. `wp_insert_post_data` hands over slashed data,
 * so a mapper that forgets to re-slash loses a backslash on every save — and it
 * takes a second save to expose it.
 */
hp_submit_note( $note_id, array( 'hp_note_body' => $transcript ) );
hp_is( $transcript, get_post_field( 'post_content', $note_id ), 'the body survives a second save with its apostrophes intact' );

/*
 * Pins the accepted cost of sanitize_textarea_field() against real WordPress
 * rather than the unit suite's port of it, so that if core's behaviour ever
 * changes this fails here instead of silently altering stored notes.
 */
hp_submit_note( $note_id, array( 'hp_note_body' => 'HbA1c <5.7% and BP <120' ) );
hp_is(
	'HbA1c &lt;5.7% and BP &lt;120',
	get_post_field( 'post_content', $note_id ),
	'a bare < is stored HTML-encoded, as documented'
);

// A save carrying no body field must leave the stored body alone.
hp_submit_note( $note_id, array() );
hp_is(
	'HbA1c &lt;5.7% and BP &lt;120',
	get_post_field( 'post_content', $note_id ),
	'a save with no body field leaves the body untouched'
);

hp_submit_note( $note_id, array( 'hp_note_body' => $transcript ) );

hp_section( 'Kind assignment, saved entirely by core' );

$kind = get_term_by( 'slug', 'transcript', Taxonomies::KIND );

hp_submit_note(
	$note_id,
	array(
		'hp_note_body' => $transcript,
		'tax_input'    => array( Taxonomies::KIND => array( (string) $kind->term_id ) ),
	)
);

hp_is(
	array( 'transcript' ),
	wp_get_object_terms( $note_id, Taxonomies::KIND, array( 'fields' => 'slugs' ) ),
	'the kind select assigns exactly one term'
);

// The metabox's "None" option submits '0', which array_filter() drops.
hp_submit_note(
	$note_id,
	array(
		'hp_note_body' => $transcript,
		'tax_input'    => array( Taxonomies::KIND => array( '0' ) ),
	)
);

hp_is(
	array(),
	wp_get_object_terms( $note_id, Taxonomies::KIND, array( 'fields' => 'slugs' ) ),
	'the None option clears the kind'
);

hp_section( 'Search and filtering' );

$found = get_posts(
	array(
		'post_type'      => Post_Type::SLUG,
		's'              => 'blood pressure',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
hp_no_db_error( 'searching notes runs without a database error' );
hp_ok( in_array( $note_id, array_map( 'intval', $found ), true ), 'a note is found by a phrase in its body' );

hp_is(
	array(),
	get_posts(
		array(
			'post_type'      => Post_Type::SLUG,
			's'              => 'zzzznotpresent',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	),
	'a phrase that is absent finds nothing'
);

/*
 * Slugification, which the unit suite structurally cannot cover: its
 * `sanitize_title` stub only lowercases, while the real function turns spaces
 * into hyphens. A provider named with a space is the realistic case — "Dr Smith"
 * is stored slugged `dr-smith`, and that is what the dropdown submits.
 */
$provider = wp_insert_term( 'Dr Smith', Taxonomies::PROVIDER );

if ( ! is_wp_error( $provider ) ) {
	wp_set_object_terms( $note_id, array( (int) $provider['term_id'] ), Taxonomies::PROVIDER );

	hp_is( 'dr-smith', get_term( (int) $provider['term_id'], Taxonomies::PROVIDER )->slug, 'a provider name with a space is stored hyphenated' );

	$by_provider = get_posts(
		array(
			'post_type'      => Post_Type::SLUG,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => Query_Filters::tax_query( array( Taxonomies::PROVIDER => 'Dr Smith' ) ),
		)
	);
	hp_no_db_error( 'filtering by provider runs without a database error' );
	hp_ok(
		in_array( $note_id, array_map( 'intval', $by_provider ), true ),
		'a note is found by filtering on the un-slugified provider name'
	);

	wp_delete_term( (int) $provider['term_id'], Taxonomies::PROVIDER );
}

// The "All" option in every filter dropdown submits 0, which must not filter.
hp_is( array(), Query_Filters::tax_query( array( Taxonomies::KIND => '0' ) ), 'the All option produces no clause at all' );

$occurred = substr( (string) get_post_field( 'post_date', $note_id ), 0, 10 );

$in_range = get_posts(
	array(
		'post_type'      => Post_Type::SLUG,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'date_query'     => Query_Filters::date_query(
			array(
				Query_Filters::FROM => $occurred,
				Query_Filters::TO   => $occurred,
			)
		),
	)
);
hp_no_db_error( 'a note date range runs without a database error' );
hp_ok( in_array( $note_id, array_map( 'intval', $in_range ), true ), 'a single-day range includes a note recorded that day' );

hp_is(
	array(),
	get_posts(
		array(
			'post_type'      => Post_Type::SLUG,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'date_query'     => Query_Filters::date_query(
				array(
					Query_Filters::FROM => '1999-01-01',
					Query_Filters::TO   => '1999-12-31',
				)
			),
		)
	),
	'a range before the note excludes it'
);

hp_reset_notes();

hp_done();
