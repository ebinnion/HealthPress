<?php
/**
 * Shared helpers for the integration scripts.
 *
 * These run inside a real WordPress via `studio wp eval-file`, against the
 * actual SQLite driver. That is the point: the unit suite proves the logic,
 * these prove the queries actually execute on the driver this site runs.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

$GLOBALS['hp_failures'] = 0;
$GLOBALS['hp_checks']   = 0;

/**
 * Asserts a condition and reports it.
 *
 * @param bool   $condition Result of the check.
 * @param string $label     What was being checked.
 */
function hp_ok( bool $condition, string $label ): void {
	++$GLOBALS['hp_checks'];

	if ( ! $condition ) {
		++$GLOBALS['hp_failures'];
	}

	printf( "%s  %s\n", $condition ? ' ok ' : 'FAIL', $label );
}

/**
 * Asserts two values match, showing both when they do not.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    What was being checked.
 */
function hp_is( $expected, $actual, string $label ): void {
	$matches = $expected === $actual;

	hp_ok( $matches, $matches ? $label : sprintf( '%s (expected %s, got %s)', $label, wp_json_encode( $expected ), wp_json_encode( $actual ) ) );
}

/**
 * Asserts the last database call did not error.
 *
 * The SQLite driver is a release candidate, so no query shape is assumed to
 * work until it has actually run without complaint.
 *
 * @param string $label What was being checked.
 */
function hp_no_db_error( string $label ): void {
	global $wpdb;

	hp_ok( '' === $wpdb->last_error, '' === $wpdb->last_error ? $label : sprintf( '%s — %s', $label, $wpdb->last_error ) );
}

/**
 * Prints a section heading.
 *
 * @param string $title Section name.
 */
function hp_section( string $title ): void {
	printf( "\n== %s\n", $title );
}

/**
 * Reports the tally and exits non-zero on any failure.
 */
function hp_done(): void {
	printf(
		"\n%d checks, %d failures\n",
		$GLOBALS['hp_checks'],
		$GLOBALS['hp_failures']
	);

	exit( $GLOBALS['hp_failures'] > 0 ? 1 : 0 );
}

/**
 * Deletes every reading, so a script starts from a known state.
 */
function hp_reset_readings(): void {
	$ids = get_posts(
		array(
			'post_type'      => 'hp_reading',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}
