<?php
/**
 * Exercises the REST surface through the real dispatcher.
 *
 * Run with: studio wp eval-file <path>
 *
 * No `declare( strict_types = 1 )` here — `wp eval-file` runs the script
 * through eval(), where a declare cannot be the first statement.
 *
 * @package HealthPress
 */

require_once __DIR__ . '/_harness.php';

wp_set_current_user( 1 );
hp_reset_readings();

// Routes are registered on rest_api_init, which has not fired under eval-file.
do_action( 'rest_api_init' );

/**
 * Dispatches a request and returns the response.
 *
 * @param string               $method HTTP method.
 * @param string               $route  Route path.
 * @param array<string, mixed> $body   JSON body for writes.
 * @param array<string, mixed> $params Query parameters.
 */
function hp_request( string $method, string $route, array $body = array(), array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	if ( array() !== $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}

	return rest_do_request( $request );
}

hp_section( 'Routes' );

$routes = array_keys( rest_get_server()->get_routes() );

foreach ( array(
	'/healthpress/v1/metrics',
	'/healthpress/v1/readings',
	'/healthpress/v1/readings/latest',
) as $route ) {
	hp_ok( in_array( $route, $routes, true ), sprintf( '%s is registered', $route ) );
}

hp_ok( ! in_array( '/wp/v2/hp_reading', $routes, true ), 'readings are NOT exposed on /wp/v2' );

hp_section( 'Discovery' );

$metrics = hp_request( 'GET', '/healthpress/v1/metrics' );

hp_is( 200, $metrics->get_status(), 'the catalog is served' );
hp_is( 9, count( $metrics->get_data() ), 'it lists nine metrics' );

$one = hp_request( 'GET', '/healthpress/v1/metrics/blood_pressure' );

hp_is( 200, $one->get_status(), 'a single metric is served' );
hp_is( 2, count( $one->get_data()['fields'] ), 'blood pressure declares two fields' );
hp_is( 404, hp_request( 'GET', '/healthpress/v1/metrics/nope' )->get_status(), 'an unknown metric is a 404' );

hp_section( 'Creating' );

$created = hp_request(
	'POST',
	'/healthpress/v1/readings',
	array(
		'metric'      => 'blood_pressure',
		'recorded_at' => '2026-08-08T07:14:00+00:00',
		'values'      => array(
			'systolic'  => 118,
			'diastolic' => 76,
		),
		'note'        => 'Before coffee.',
	)
);

hp_is( 201, $created->get_status(), 'a create returns 201' );
hp_ok( '' !== (string) ( $created->get_headers()['Location'] ?? '' ), 'and a Location header' );

$body = $created->get_data();

hp_is( 'blood_pressure', $body['metric'], 'the response names the metric' );
hp_is( array( 'systolic' => 118, 'diastolic' => 76 ), $body['values'], 'the values came back' );
hp_is( array( 'systolic' => 'mmhg', 'diastolic' => 'mmhg' ), $body['units'], 'the response says what unit the numbers are in' );
hp_is( '2026-08-08T07:14:00+00:00', $body['recorded_at'], 'the timestamp came back in UTC' );

$reading_id = $body['id'];

hp_section( 'Unit conversion' );

$in_pounds = hp_request(
	'POST',
	'/healthpress/v1/readings',
	array(
		'metric' => 'weight',
		'values' => array( 'value' => 172.4 ),
		'unit'   => 'lb',
	)
);

hp_is( 201, $in_pounds->get_status(), 'a weight can be submitted in pounds' );

$weight_id = $in_pounds->get_data()['id'];

hp_is( '78.20', get_post_meta( $weight_id, '_hp_weight_value', true ), 'it is stored canonically, in kilograms' );

$back_in_kg = hp_request( 'GET', '/healthpress/v1/readings/' . $weight_id );

hp_is( 78.2, $back_in_kg->get_data()['values']['value'], 'reading it back gives kilograms by default' );
hp_is( 'kg', $back_in_kg->get_data()['units']['value'], 'and says so' );

$back_in_lb = hp_request( 'GET', '/healthpress/v1/readings/' . $weight_id, array(), array( 'unit' => 'lb' ) );

hp_is( 172.4, $back_in_lb->get_data()['values']['value'], 'asking for pounds returns the original number' );
hp_is( 'lb', $back_in_lb->get_data()['units']['value'], 'and reports pounds' );

$bad_unit = hp_request( 'GET', '/healthpress/v1/readings/' . $weight_id, array(), array( 'unit' => 'furlong' ) );

hp_is( 400, $bad_unit->get_status(), 'an unknown unit is rejected' );

$ambiguous = hp_request( 'GET', '/healthpress/v1/readings/' . $weight_id, array(), array( 'unit' => 'lb,st' ) );

hp_is( 400, $ambiguous->get_status(), 'two units of one dimension are rejected' );

hp_section( 'Rejection matrix' );

$rejections = array(
	'hp_future_date'    => array(
		'metric'      => 'weight',
		'recorded_at' => '2030-01-01T00:00:00Z',
		'values'      => array( 'value' => 70 ),
	),
	'hp_unknown_field'  => array(
		'metric' => 'weight',
		'values' => array( 'vlaue' => 70 ),
	),
	'hp_out_of_range'   => array(
		'metric' => 'blood_pressure',
		'values' => array(
			'systolic'  => 900,
			'diastolic' => 76,
		),
	),
	'hp_invalid_type'   => array(
		'metric' => 'steps',
		'values' => array( 'value' => 1234.5 ),
	),
	'hp_unknown_metric' => array(
		'metric' => 'nope',
		'values' => array( 'value' => 1 ),
	),
	'hp_missing_field'  => array(
		'metric' => 'blood_pressure',
		'values' => array( 'systolic' => 118 ),
	),
);

foreach ( $rejections as $expected_code => $payload ) {
	$response = hp_request( 'POST', '/healthpress/v1/readings', $payload );
	$data     = $response->get_data();
	$code     = is_array( $data ) ? ( $data['code'] ?? '' ) : '';

	hp_is( 400, $response->get_status(), sprintf( '%s is a 400', $expected_code ) );
	hp_is( $expected_code, $code, sprintf( '%s reports the right code', $expected_code ) );
}

hp_section( 'Listing' );

$list = hp_request( 'GET', '/healthpress/v1/readings', array(), array( 'per_page' => 10 ) );

hp_is( 200, $list->get_status(), 'the collection is served' );
hp_is( 2, count( $list->get_data() ), 'it holds both readings' );
hp_is( '2', (string) $list->get_headers()['X-WP-Total'], 'X-WP-Total is set' );
hp_is( '1', (string) $list->get_headers()['X-WP-TotalPages'], 'X-WP-TotalPages is set' );

$filtered = hp_request( 'GET', '/healthpress/v1/readings', array(), array( 'metric' => 'weight' ) );

hp_is( 1, count( $filtered->get_data() ), 'filtering by metric narrows the result' );

$windowed = hp_request(
	'GET',
	'/healthpress/v1/readings',
	array(),
	array(
		'metric' => 'blood_pressure',
		'after'  => '2026-08-01T00:00:00Z',
		'before' => '2026-08-09T00:00:00Z',
	)
);

hp_is( 1, count( $windowed->get_data() ), 'filtering by window narrows the result' );

hp_section( 'Latest' );

$latest = hp_request( 'GET', '/healthpress/v1/readings/latest', array(), array( 'metric' => 'weight' ) );

hp_is( 200, $latest->get_status(), 'latest is served' );
hp_is( $weight_id, $latest->get_data()['id'], 'and returns the most recent reading' );
hp_is( 404, hp_request( 'GET', '/healthpress/v1/readings/latest', array(), array( 'metric' => 'spo2' ) )->get_status(), 'latest is a 404 when there are no readings' );

hp_section( 'Updating' );

$patched = hp_request( 'PATCH', '/healthpress/v1/readings/' . $reading_id, array( 'values' => array( 'systolic' => 122 ) ) );

hp_is( 200, $patched->get_status(), 'a patch succeeds' );
hp_is( 122, $patched->get_data()['values']['systolic'], 'the patched field changed' );
hp_is( 76, $patched->get_data()['values']['diastolic'], 'the untouched field survived' );

$patch_in_lb = hp_request( 'PATCH', '/healthpress/v1/readings/' . $weight_id, array( 'values' => array( 'value' => 180 ), 'unit' => 'lb' ) );

hp_is( 200, $patch_in_lb->get_status(), 'a patch can be expressed in pounds' );
hp_is( '81.65', get_post_meta( $weight_id, '_hp_weight_value', true ), 'and is stored canonically' );

$bad_patch = hp_request( 'PATCH', '/healthpress/v1/readings/' . $reading_id, array( 'values' => array( 'systolic' => 900 ) ) );

hp_is( 400, $bad_patch->get_status(), 'an out-of-range patch is rejected' );

hp_section( 'Changing the metric over REST' );

/*
 * The admin screen allows this, so REST must too — one rule for one operation.
 */
$switch = hp_request(
	'POST',
	'/healthpress/v1/readings',
	array(
		'metric' => 'spo2',
		'values' => array( 'value' => 97 ),
	)
);

$switch_id = $switch->get_data()['id'];

$switched = hp_request(
	'PATCH',
	'/healthpress/v1/readings/' . $switch_id,
	array(
		'metric' => 'body_temperature',
		'values' => array( 'value' => 98.6 ),
		'unit'   => 'f',
	)
);

hp_is( 200, $switched->get_status(), 'a patch may change the metric' );
hp_is( 'body_temperature', $switched->get_data()['metric'], 'the reading reports the new metric' );

/*
 * Proves the incoming value was converted against the *target* metric. Against
 * spo2 (a ratio) the Fahrenheit request would have found no matching dimension
 * and stored 98.6 unconverted.
 */
hp_is( '37.0', get_post_meta( $switch_id, '_hp_body_temperature_value', true ), 'and converted the value against the metric it is becoming' );
hp_is( '', get_post_meta( $switch_id, '_hp_spo2_value', true ), 'the old metric value was swept' );

hp_is(
	400,
	hp_request( 'PATCH', '/healthpress/v1/readings/' . $switch_id, array( 'metric' => 'nope' ) )->get_status(),
	'switching to an unknown metric is rejected'
);

hp_is( 404, hp_request( 'GET', '/healthpress/v1/readings/999999' )->get_status(), 'an unknown ID is a 404' );

hp_section( 'Deleting' );

$deleted = hp_request( 'DELETE', '/healthpress/v1/readings/' . $reading_id );

hp_is( 200, $deleted->get_status(), 'a delete succeeds' );
hp_ok( true === $deleted->get_data()['deleted'], 'and reports the deletion' );
hp_ok( isset( $deleted->get_data()['previous']['values'] ), 'and echoes what was removed' );
hp_is( 404, hp_request( 'GET', '/healthpress/v1/readings/' . $reading_id )->get_status(), 'the reading is gone' );

hp_section( 'Permissions' );

wp_set_current_user( 0 );

/*
 * The POST carries a valid body on purpose. WP_REST_Server validates required
 * parameters before it runs the permission callback, so an empty body would
 * 400 on the missing parameter and never reach the check being tested here.
 */
$valid_body = array(
	'metric' => 'weight',
	'values' => array( 'value' => 70 ),
);

$count_before_refusal = count( get_posts( array( 'post_type' => 'hp_reading', 'fields' => 'ids', 'posts_per_page' => -1 ) ) );

foreach ( array(
	array( 'GET', '/healthpress/v1/readings', array() ),
	array( 'GET', '/healthpress/v1/metrics', array() ),
	array( 'GET', '/healthpress/v1/readings/latest', array() ),
	array( 'POST', '/healthpress/v1/readings', $valid_body ),
) as [$method, $route, $payload] ) {
	$response = hp_request( $method, $route, $payload, 'GET' === $method ? array( 'metric' => 'weight' ) : array() );

	hp_is( 401, $response->get_status(), sprintf( 'anonymous %s %s is refused', $method, $route ) );
}

hp_is(
	$count_before_refusal,
	count( get_posts( array( 'post_type' => 'hp_reading', 'fields' => 'ids', 'posts_per_page' => -1 ) ) ),
	'the refused POST wrote nothing'
);

$subscriber = wp_insert_user(
	array(
		'user_login' => 'hp_subscriber_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);

wp_set_current_user( $subscriber );

hp_is( 403, hp_request( 'GET', '/healthpress/v1/readings' )->get_status(), 'a subscriber is forbidden' );

wp_delete_user( $subscriber );
wp_set_current_user( 1 );

hp_no_db_error( 'every request ran cleanly' );

hp_reset_readings();

hp_done();
