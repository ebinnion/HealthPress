<?php
/**
 * Verifies the object types and meta keys registered as intended.
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
use HealthPress\Storage\Post_Type;
use HealthPress\Storage\Taxonomy;

hp_section( 'Object types' );

hp_ok( post_type_exists( Post_Type::SLUG ), 'hp_reading post type is registered' );
hp_ok( taxonomy_exists( Taxonomy::SLUG ), 'hp_metric taxonomy is registered' );

$post_type = get_post_type_object( Post_Type::SLUG );

hp_is( false, $post_type->show_in_rest, 'readings are not exposed through /wp/v2' );
hp_is( true, $post_type->show_ui, 'the readings admin screen is available' );
hp_is( false, $post_type->public, 'readings are not public' );
hp_is( false, $post_type->publicly_queryable, 'and not queryable from the front end' );
hp_is( true, $post_type->exclude_from_search, 'nor surfaced by site search' );
hp_is( false, $post_type->has_archive, 'there is no public archive of health data' );
hp_is( true, $post_type->map_meta_cap, 'per-post capabilities are mapped' );

$taxonomy = get_taxonomy( Taxonomy::SLUG );

hp_is( false, $taxonomy->show_in_rest, 'metrics are not exposed through /wp/v2' );
hp_ok( in_array( Post_Type::SLUG, $taxonomy->object_type, true ), 'the taxonomy is attached to hp_reading' );
hp_is( false, $taxonomy->public, 'the taxonomy is not public' );
hp_is( false, $taxonomy->publicly_queryable, 'nor queryable' );
hp_is( false, $taxonomy->hierarchical, 'and flat — metrics have no parents' );

hp_section( 'The editor renders nothing of its own' );

hp_is( array(), get_all_post_type_supports( Post_Type::SLUG ), 'the post type supports no core editor feature' );
hp_ok( ! post_type_supports( Post_Type::SLUG, 'custom-fields' ), 'the Custom Fields box is gone' );
hp_ok( ! post_type_supports( Post_Type::SLUG, 'title' ), 'the title field is gone — titles are generated' );
hp_is( false, $taxonomy->meta_box_cb, 'the taxonomy renders no metabox of its own' );

hp_section( 'Core cannot write a metric term' );

wp_set_current_user( 1 );

/*
 * A positive control first. Every check below is a `! current_user_can()`, and
 * those all pass vacuously against a user with no capabilities at all — so this
 * asserts the current user really is an administrator and the denials mean
 * something.
 */
hp_ok( current_user_can( 'manage_options' ), 'the current user is an administrator' );

/*
 * The whole tax_input path in wp_insert_post() is gated on assign_terms, so
 * denying it neutralises core's second write path structurally.
 */
hp_ok( ! current_user_can( $taxonomy->cap->assign_terms ), 'not even an administrator may assign a metric through core' );
hp_ok( ! current_user_can( $taxonomy->cap->edit_terms ), 'nobody may create or rename a metric term' );
hp_ok( ! current_user_can( $taxonomy->cap->delete_terms ), 'nor delete one' );
hp_ok( current_user_can( $taxonomy->cap->manage_terms ), 'but the catalog itself stays readable' );

hp_section( 'The taxonomy has no screen of its own' );

hp_is( false, $taxonomy->show_ui, 'the term management screen is off' );
hp_is( false, $taxonomy->show_in_menu, 'so no Metrics submenu is registered' );

/*
 * edit-tags.php gates on membership of this list and dies otherwise, so being
 * absent from it is what makes the URL inaccessible rather than merely
 * unlinked. That distinction is the whole point of using show_ui here.
 */
hp_ok(
	! in_array( Taxonomy::SLUG, get_taxonomies( array( 'show_ui' => true ) ), true ),
	'and edit-tags.php will refuse the URL outright'
);

/*
 * The column is the one surface kept, and it is selected on show_admin_column
 * alone — this asserts that turning the screen off did not take it with them.
 */
hp_is( true, $taxonomy->show_admin_column, 'but the Metrics column survives' );

$columns = wp_filter_object_list(
	get_object_taxonomies( Post_Type::SLUG, 'objects' ),
	array( 'show_admin_column' => true ),
	'and',
	'name'
);

hp_ok( in_array( Taxonomy::SLUG, $columns, true ), 'and the readings list table still selects it' );

hp_section( 'Metric terms' );

$registry = Plugin::instance()->metrics();
$slugs    = $registry->slugs();

hp_is( 9, count( $slugs ), 'nine metrics are registered' );

foreach ( $slugs as $slug ) {
	$term = get_term_by( 'slug', $slug, Taxonomy::SLUG );

	hp_ok( $term instanceof WP_Term, sprintf( 'term exists for "%s"', $slug ) );
}

hp_no_db_error( 'term lookups ran cleanly' );

hp_section( 'Meta keys' );

/*
 * These keys are deliberately not passed to register_post_meta() — see the
 * Meta class docblock. What still has to hold is that the live catalog cannot
 * produce two fields sharing a key, which is the entire reason they are
 * namespaced by metric. MetaKeyTest proves the derivation; this proves the
 * shipped nine actually come out distinct.
 */
$keys = array( Meta::SOURCE );

foreach ( $registry->all() as $metric ) {
	foreach ( $metric->fields as $field ) {
		$keys[] = Meta::key( $metric, $field->key );
	}
}

hp_is( count( $keys ), count( array_unique( $keys ) ), 'no two fields in the shipped catalog share a meta key' );

$too_long = array_filter( $keys, static fn ( string $k ): bool => strlen( $k ) > 191 );

hp_is( 0, count( $too_long ), 'every key fits within the indexable meta_key prefix' );

$unprotected = array_filter( $keys, static fn ( string $k ): bool => ! is_protected_meta( $k, 'post' ) );

hp_is( 0, count( $unprotected ), 'every key is protected meta' );

hp_done();
