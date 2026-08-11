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

hp_section( 'Kind seeding' );

Kind_Seeder::seed();
hp_no_db_error( 'seeding runs without a database error' );

foreach ( array_keys( Default_Kinds::all() ) as $slug ) {
	hp_ok( null !== term_exists( $slug, Taxonomies::KIND ), sprintf( 'the %s kind exists', $slug ) );
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
