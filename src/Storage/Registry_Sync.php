<?php
/**
 * Keeps the metric taxonomy in step with the registry.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Storage;

use HealthPress\Metrics\Metric_Registry;
use HealthPress\Metrics\Metric_Type;
use WP_Error;
use WP_Term;

/**
 * Ensures every registered metric has a term to attach readings to.
 *
 * Nothing is ever deleted. Removing a metric from the registry leaves its term
 * and every reading attached to it intact — deleting the term would silently
 * detach years of history. Such a term is simply no longer resolved by
 * `Metric_Registry`, which is what `Post_Reading_Repository::hydrate()` already
 * detects live when it reports `hp_orphaned_reading`.
 *
 * A term carries only a name and a description, so syncing is gated on the
 * plugin version alone: those two strings are translated, and nothing else
 * about a metric reaches the taxonomy. A new metric added by a filter without a
 * version bump still works, because writes go through `ensure_term()`, which
 * creates on demand.
 */
final class Registry_Sync {

	/**
	 * Option holding the plugin version at last sync.
	 */
	public const VERSION_OPTION = 'healthpress_version';

	/**
	 * Wires the registry this instance mirrors.
	 *
	 * @param Metric_Registry $registry The metric catalog.
	 * @param string          $version  Current plugin version.
	 */
	public function __construct(
		private readonly Metric_Registry $registry,
		private readonly string $version,
	) {}

	/**
	 * Syncs once per plugin version.
	 *
	 * Hooked to `admin_init`, so the front end never pays for this.
	 */
	public function maybe_sync(): void {
		if ( get_option( self::VERSION_OPTION ) === $this->version ) {
			return;
		}

		$this->sync();
	}

	/**
	 * Creates or corrects a term for every registered metric.
	 */
	public function sync(): void {
		foreach ( $this->registry->all() as $metric ) {
			$this->upsert( $metric );
		}

		/*
		 * Bookkeeping from 0.1.x: a structural hash that gated this method, and a
		 * slug-to-term map that duplicated an index the taxonomy already has.
		 * Both were autoloaded on every request, so an upgraded site should not
		 * go on carrying them.
		 */
		delete_option( 'healthpress_registry_hash' );
		delete_option( 'healthpress_metric_terms' );

		update_option( self::VERSION_OPTION, $this->version, true );
	}

	/**
	 * Returns the term ID for a metric slug, creating the term if it is missing.
	 *
	 * Every write goes through here, so a write never depends on a sync having
	 * run — nor on any cached mapping being current, since it reads the taxonomy
	 * directly.
	 *
	 * @param string $slug Metric slug.
	 */
	public function ensure_term( string $slug ): int|WP_Error {
		$metric = $this->registry->get( $slug );

		if ( null === $metric ) {
			return new WP_Error(
				'hp_unknown_metric',
				sprintf(
					/* translators: %s: the metric slug. */
					__( 'Unknown metric "%s".', 'healthpress' ),
					$slug
				),
				array( 'status' => 400 )
			);
		}

		return $this->upsert( $metric );
	}

	/**
	 * Creates the metric's term, or corrects it if its name has drifted.
	 *
	 * @param Metric_Type $metric The metric to reflect.
	 */
	private function upsert( Metric_Type $metric ): int|WP_Error {
		$term = get_term_by( 'slug', $metric->slug, Taxonomy::SLUG );

		if ( $term instanceof WP_Term ) {
			if ( $term->name !== $metric->label || $term->description !== (string) $metric->description ) {
				wp_update_term(
					(int) $term->term_id,
					Taxonomy::SLUG,
					array(
						'name'        => $metric->label,
						'description' => (string) $metric->description,
					)
				);
			}

			return (int) $term->term_id;
		}

		$created = wp_insert_term(
			$metric->label,
			Taxonomy::SLUG,
			array(
				'slug'        => $metric->slug,
				'description' => (string) $metric->description,
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return (int) $created['term_id'];
	}
}
