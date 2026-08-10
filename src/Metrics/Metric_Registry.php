<?php
/**
 * The catalog of known metrics.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Metrics;

/**
 * The set of metric types this site tracks.
 *
 * Built from the shipped catalog plus whatever the `healthpress_metrics`
 * filter adds. A malformed contribution is dropped rather than allowed to
 * fatal every request; the developer hears about it through
 * `_doing_it_wrong()`.
 */
final class Metric_Registry {

	/**
	 * The filter third parties use to extend the catalog.
	 */
	public const FILTER = 'healthpress_metrics';

	/**
	 * Metrics indexed by slug, in registration order.
	 *
	 * @var array<string, Metric_Type>
	 */
	private array $metrics = array();

	/**
	 * Builds a registry, discarding anything malformed.
	 *
	 * @param iterable<mixed> $metrics Candidate metric types.
	 */
	public function __construct( iterable $metrics ) {
		foreach ( $metrics as $metric ) {
			if ( ! $metric instanceof Metric_Type ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: the filter name used to register metrics. */
						esc_html__( 'Every entry passed through %s must be a Metric_Type.', 'healthpress' ),
						esc_html( self::FILTER )
					),
					'0.1.0'
				);

				continue;
			}

			if ( isset( $this->metrics[ $metric->slug ] ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: the duplicated metric slug. */
						esc_html__( 'A metric with the slug "%s" is already registered.', 'healthpress' ),
						esc_html( $metric->slug )
					),
					'0.1.0'
				);

				continue;
			}

			$this->metrics[ $metric->slug ] = $metric;
		}
	}

	/**
	 * Builds the registry for this site: the shipped catalog, plus extensions.
	 *
	 * Fires early on `init`, so anything hooking `healthpress_metrics` must
	 * register before then.
	 */
	public static function create(): self {
		/**
		 * Filters the metric types HealthPress tracks.
		 *
		 * @since 0.1.0
		 *
		 * @param list<Metric_Type> $metrics The metric catalog.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER is the literal 'healthpress_metrics'.
		$metrics = apply_filters( self::FILTER, Default_Metrics::all() );

		return new self( is_array( $metrics ) ? $metrics : Default_Metrics::all() );
	}

	/**
	 * Returns a metric by slug, or null when it is not registered.
	 *
	 * @param string $slug Metric slug.
	 */
	public function get( string $slug ): ?Metric_Type {
		return $this->metrics[ $slug ] ?? null;
	}

	/**
	 * Whether a metric slug is registered.
	 *
	 * @param string $slug Metric slug.
	 */
	public function has( string $slug ): bool {
		return isset( $this->metrics[ $slug ] );
	}

	/**
	 * Returns every registered metric, in registration order.
	 *
	 * @return list<Metric_Type>
	 */
	public function all(): array {
		return array_values( $this->metrics );
	}

	/**
	 * Returns every registered slug, in registration order.
	 *
	 * @return list<string>
	 */
	public function slugs(): array {
		return array_keys( $this->metrics );
	}
}
