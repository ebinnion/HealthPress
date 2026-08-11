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

hp_done();
