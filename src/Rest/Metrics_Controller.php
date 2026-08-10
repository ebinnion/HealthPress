<?php
/**
 * Metric discovery endpoints.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Rest;

use HealthPress\Metrics\Metric_Registry;
use HealthPress\Support\Unit_Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves the metric catalog, so a client can discover what it may record and
 * in which units, rather than hard-coding the schema.
 */
final class Metrics_Controller {

	/**
	 * The API namespace shared by every HealthPress route.
	 */
	public const NAMESPACE = 'healthpress/v1';

	/**
	 * Schema generation.
	 *
	 * @var Schema_Factory
	 */
	private Schema_Factory $schema;

	/**
	 * Wires the catalogs this controller exposes.
	 *
	 * @param Metric_Registry $metrics The metric catalog.
	 * @param Unit_Registry   $units   The unit catalog.
	 */
	public function __construct(
		private readonly Metric_Registry $metrics,
		Unit_Registry $units,
	) {
		$this->schema = new Schema_Factory( $units );
	}

	/**
	 * Registers the discovery routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/metrics',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( Permissions::class, 'check' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/metrics/(?P<slug>[a-z][a-z0-9_]*)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( Permissions::class, 'check' ),
					'args'                => array(
						'slug' => array(
							'type'        => 'string',
							'required'    => true,
							'description' => __( 'Metric slug.', 'healthpress' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Returns the whole catalog.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return rest_ensure_response(
			array_map(
				fn ( $metric ): array => $this->schema->describe_metric( $metric ),
				$this->metrics->all()
			)
		);
	}

	/**
	 * Returns one metric.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$metric = $this->metrics->get( (string) $request['slug'] );

		if ( null === $metric ) {
			return new WP_Error(
				'hp_unknown_metric',
				__( 'No metric with that slug.', 'healthpress' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->schema->describe_metric( $metric ) );
	}
}
