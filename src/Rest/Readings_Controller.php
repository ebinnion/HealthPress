<?php
/**
 * Reading endpoints.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Rest;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use HealthPress\Metrics\Metric_Registry;
use HealthPress\Storage\Post_Reading_Repository;
use HealthPress\Storage\Reading;
use HealthPress\Storage\Reading_Query;
use HealthPress\Storage\Reading_Repository;
use HealthPress\Validation\Reading_Validator;
use InvalidArgumentException;
use stdClass;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The only write path into reading storage.
 *
 * Every mutation funnels through `Reading_Validator`, which is why the post
 * type is not exposed on `/wp/v2`: that would create a second route straight
 * to `wp_insert_post()` with no validation behind it.
 */
final class Readings_Controller {

	/**
	 * The API namespace shared by every HealthPress route.
	 */
	public const NAMESPACE = 'healthpress/v1';

	/**
	 * Wires the collaborators a request needs.
	 *
	 * @param Metric_Registry    $metrics    The metric catalog.
	 * @param Reading_Repository $readings   Persistence.
	 * @param Reading_Validator  $validator  The single enforcement point.
	 * @param Unit_Negotiator    $negotiator Unit conversion at the boundary.
	 */
	public function __construct(
		private readonly Metric_Registry $metrics,
		private readonly Reading_Repository $readings,
		private readonly Reading_Validator $validator,
		private readonly Unit_Negotiator $negotiator,
	) {}

	/**
	 * Registers the reading routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/readings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( Permissions::class, 'check' ),
					'args'                => $this->collection_args(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( Permissions::class, 'check' ),
					'args'                => $this->write_args( true ),
				),
			)
		);

		/*
		 * Registered before the numeric-ID route so that `/readings/latest` is
		 * not swallowed by it.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/readings/latest',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_latest' ),
					'permission_callback' => array( Permissions::class, 'check' ),
					'args'                => array(
						'metric' => array(
							'type'     => 'string',
							'required' => true,
						),
						'unit'   => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/readings/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( Permissions::class, 'check' ),
					'args'                => array( 'unit' => array( 'type' => 'string' ) ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( Permissions::class, 'check' ),
					'args'                => $this->write_args( false ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( Permissions::class, 'check' ),
					'args'                => array(
						'force' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	// -----------------------------------------------------------------
	// Argument definitions.
	// -----------------------------------------------------------------

	/**
	 * Arguments accepted when listing readings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function collection_args(): array {
		return array(
			'metric'   => array(
				'type'        => array( 'string', 'array' ),
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'One or more metric slugs.', 'healthpress' ),
			),
			'after'    => array(
				'type'   => 'string',
				'format' => 'date-time',
			),
			'before'   => array(
				'type'   => 'string',
				'format' => 'date-time',
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => Reading_Query::MAX_LIMIT,
			),
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'order'    => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
			'unit'     => array(
				'type'        => 'string',
				'description' => __( 'Comma-separated unit slugs to return values in.', 'healthpress' ),
			),
		);
	}

	/**
	 * Arguments accepted when writing a reading.
	 *
	 * `values` is declared only as an object. Its permitted shape depends on
	 * the value of `metric`, which JSON Schema cannot express, so the real
	 * check is delegated to the validator rather than duplicated here.
	 *
	 * @param bool $creating Whether this is a create rather than an update.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function write_args( bool $creating ): array {
		return array(
			'metric'      => array(
				'type'     => 'string',
				'required' => $creating,
			),
			'recorded_at' => array(
				'type'   => 'string',
				'format' => 'date-time',
			),
			'values'      => array(
				'type'     => 'object',
				'required' => $creating,
			),
			'note'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'source'      => array(
				'type' => 'string',
				'enum' => Reading_Validator::SOURCES,
			),
			'unit'        => array(
				'type'        => 'string',
				'description' => __( 'Comma-separated unit slugs the submitted values are expressed in.', 'healthpress' ),
			),
		);
	}

	// -----------------------------------------------------------------
	// Handlers.
	// -----------------------------------------------------------------

	/**
	 * Lists readings.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$requested = $this->requested_units( $request );

		if ( is_wp_error( $requested ) ) {
			return $requested;
		}

		$per_page = (int) $request['per_page'];
		$page     = (int) $request['page'];

		$after  = $this->parse_boundary( $request['after'] );
		$before = $this->parse_boundary( $request['before'] );

		if ( is_wp_error( $after ) ) {
			return $after;
		}

		if ( is_wp_error( $before ) ) {
			return $before;
		}

		try {
			$query = new Reading_Query(
				metrics: $this->requested_metrics( $request ),
				after: $after,
				before: $before,
				limit: $per_page,
				offset: ( $page - 1 ) * $per_page,
				order: (string) $request['order'],
				count_total: true,
			);
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'hp_invalid_query', $e->getMessage(), array( 'status' => 400 ) );
		}

		$collection = $this->readings->query( $query );

		$response = rest_ensure_response(
			array_map(
				fn ( Reading $reading ): array => $this->prepare_item( $reading, $requested ),
				$collection->items()
			)
		);

		$total = (int) $collection->total();

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 ) );

		return $response;
	}

	/**
	 * Returns a single reading.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$requested = $this->requested_units( $request );

		if ( is_wp_error( $requested ) ) {
			return $requested;
		}

		$reading = $this->readings->get( (int) $request['id'] );

		if ( is_wp_error( $reading ) ) {
			return $reading;
		}

		return rest_ensure_response( $this->prepare_item( $reading, $requested ) );
	}

	/**
	 * Returns the most recent reading for a metric.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_latest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$requested = $this->requested_units( $request );

		if ( is_wp_error( $requested ) ) {
			return $requested;
		}

		$slug = (string) $request['metric'];

		if ( ! $this->metrics->has( $slug ) ) {
			return new WP_Error(
				'hp_unknown_metric',
				__( 'No metric with that slug.', 'healthpress' ),
				array( 'status' => 400 )
			);
		}

		$reading = $this->readings->latest( $slug );

		if ( null === $reading ) {
			return new WP_Error(
				'hp_no_readings',
				__( 'No readings recorded for that metric yet.', 'healthpress' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_item( $reading, $requested ) );
	}

	/**
	 * Creates a reading.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$requested = $this->requested_units( $request );

		if ( is_wp_error( $requested ) ) {
			return $requested;
		}

		$input = $this->input_from( $request, $requested );

		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$result = $this->validator->validate( $input );

		if ( ! $result->is_valid() ) {
			return Post_Reading_Repository::to_wp_error( $result->violations );
		}

		$reading = $this->readings->create( $result->reading );

		if ( is_wp_error( $reading ) ) {
			return $reading;
		}

		$response = rest_ensure_response( $this->prepare_item( $reading, $requested ) );

		$response->set_status( 201 );
		$response->header(
			'Location',
			rest_url( sprintf( '%s/readings/%d', self::NAMESPACE, $reading->id ) )
		);

		return $response;
	}

	/**
	 * Updates a reading.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$requested = $this->requested_units( $request );

		if ( is_wp_error( $requested ) ) {
			return $requested;
		}

		$existing = $this->readings->get( (int) $request['id'] );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$patch = array();

		foreach ( array( 'recorded_at', 'note', 'source' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$patch[ $key ] = $request[ $key ];
			}
		}

		/*
		 * A patch may change which metric a reading measures, matching what the
		 * admin screen allows. Incoming values must therefore be converted
		 * against the metric they are being written as, not the one being
		 * replaced — otherwise `?unit=lb` on a switch to weight would resolve
		 * against the previous metric's dimensions and silently do nothing.
		 */
		$target = $existing->metric;

		if ( null !== $request['metric'] && (string) $request['metric'] !== $existing->metric->slug ) {
			$requested_metric = $this->metrics->get( (string) $request['metric'] );

			if ( null === $requested_metric ) {
				return new WP_Error(
					'hp_unknown_metric',
					__( 'No metric with that slug.', 'healthpress' ),
					array( 'status' => 400 )
				);
			}

			$target          = $requested_metric;
			$patch['metric'] = $requested_metric->slug;
		}

		if ( is_array( $request['values'] ) ) {
			$patch['values'] = $this->negotiator->in( $target, $request['values'], $requested );
		}

		$reading = $this->readings->update( (int) $request['id'], $patch );

		if ( is_wp_error( $reading ) ) {
			return $reading;
		}

		return rest_ensure_response( $this->prepare_item( $reading, $requested ) );
	}

	/**
	 * Deletes a reading.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$reading = $this->readings->get( (int) $request['id'] );

		if ( is_wp_error( $reading ) ) {
			return $reading;
		}

		$previous = $this->prepare_item( $reading, array() );
		$deleted  = $this->readings->delete( (int) $request['id'], (bool) $request['force'] );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	// -----------------------------------------------------------------
	// Shaping.
	// -----------------------------------------------------------------

	/**
	 * Builds the response body for a reading.
	 *
	 * The `units` map always reports what the numbers are actually in, rather
	 * than leaving the client to infer it from its own request.
	 *
	 * @param Reading                                  $reading   The reading.
	 * @param array<string, \HealthPress\Support\Unit> $requested Dimension => unit.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_item( Reading $reading, array $requested ): array {
		$values = $this->negotiator->out( $reading->metric, $reading->values, $requested );
		$units  = array_intersect_key(
			$this->negotiator->units_for( $reading->metric, $requested ),
			$reading->values
		);

		return array(
			'id'          => $reading->id,
			'metric'      => $reading->metric->slug,
			'recorded_at' => $reading->recorded_at->format( DATE_ATOM ),

			/*
			 * Cast when empty, or wp_json_encode() emits `[]` and contradicts the
			 * `object` type this endpoint declares. The empty-values guard in
			 * hydrate() means a Reading in that state can no longer exist, so
			 * this keeps the wire format honest if a future path produces one.
			 */
			'values'      => array() === $values ? new stdClass() : $values,
			'units'       => array() === $units ? new stdClass() : $units,
			'note'        => $reading->note,
			'source'      => $reading->source,
		);
	}

	/**
	 * Builds validator input from a create request.
	 *
	 * @param WP_REST_Request                          $request   The request.
	 * @param array<string, \HealthPress\Support\Unit> $requested Dimension => unit.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function input_from( WP_REST_Request $request, array $requested ): array|WP_Error {
		$input = array(
			'metric' => (string) $request['metric'],
			'values' => $request['values'],
		);

		foreach ( array( 'recorded_at', 'note', 'source' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$input[ $key ] = $request[ $key ];
			}
		}

		$metric = $this->metrics->get( $input['metric'] );

		/*
		 * Conversion needs a known metric. When it is unknown, hand the input
		 * to the validator unchanged and let it produce the proper error.
		 */
		if ( null !== $metric && is_array( $input['values'] ) ) {
			$input['values'] = $this->negotiator->in( $metric, $input['values'], $requested );
		}

		return $input;
	}

	/**
	 * Resolves the `unit` parameter into a dimension map.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, \HealthPress\Support\Unit>|WP_Error
	 */
	private function requested_units( WP_REST_Request $request ): array|WP_Error {
		try {
			return $this->negotiator->parse( (string) ( $request['unit'] ?? '' ) );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'hp_invalid_unit', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Normalises the `metric` parameter into a list of slugs.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return list<string>
	 */
	private function requested_metrics( WP_REST_Request $request ): array {
		$metric = $request['metric'];

		if ( null === $metric || '' === $metric ) {
			return array();
		}

		return array_values( array_map( 'strval', (array) $metric ) );
	}

	/**
	 * Parses a window boundary into a UTC instant.
	 *
	 * @param mixed $value Raw `after` or `before` parameter.
	 */
	private function parse_boundary( mixed $value ): DateTimeImmutable|WP_Error|null {
		if ( null === $value || '' === $value ) {
			return null;
		}

		try {
			return ( new DateTimeImmutable( (string) $value, wp_timezone() ) )
				->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( Exception ) {
			return new WP_Error(
				'hp_invalid_date',
				sprintf(
					/* translators: %s: the value that could not be parsed. */
					__( 'Could not interpret "%s" as a date and time.', 'healthpress' ),
					$value
				),
				array( 'status' => 400 )
			);
		}
	}
}
