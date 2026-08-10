<?php
/**
 * Access control.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Rest;

use WP_Error;

/**
 * The single access check for every HealthPress route and meta key.
 *
 * HealthPress tracks one person's health data on a site they administer, so
 * read and write share one gate rather than drifting apart per route. If this
 * ever needs to serve more than one user, this is the one place to change.
 */
final class Permissions {

	/**
	 * The capability required to read or write health data.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Whether the current user may access HealthPress data.
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * REST permission callback.
	 *
	 * Returns 401 for anonymous callers and 403 for signed-in ones, which is
	 * what `rest_authorization_required_code()` resolves.
	 */
	public static function check(): bool|WP_Error {
		if ( self::can_manage() ) {
			return true;
		}

		return new WP_Error(
			'hp_forbidden',
			__( 'You are not allowed to access HealthPress data.', 'healthpress' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
