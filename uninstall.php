<?php
/**
 * Uninstall routine.
 *
 * Removes this plugin's options. Readings and metric terms are deliberately
 * left in place — health data should not evaporate because someone fumbled a
 * deactivate. Opt into a full wipe by setting the
 * `healthpress_delete_data_on_uninstall` option to a truthy value first.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'healthpress_version' );

/*
 * Written by 0.1.x and no longer used. Kept here rather than dropped with the
 * code that wrote them: a site that installed an earlier version still has both
 * rows, autoloaded on every request, and nothing else would ever remove them.
 */
delete_option( 'healthpress_registry_hash' );
delete_option( 'healthpress_metric_terms' );

/*
 * Refused admin submissions are short-lived transients, but a set of them can
 * outlive an uninstall if the plugin goes away mid-edit. Cleared directly
 * rather than through delete_transient(), which needs a name we do not have.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API deletes transients by prefix, and a cache is meaningless during uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_healthpress_rejected_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_healthpress_rejected_' ) . '%'
	)
);
