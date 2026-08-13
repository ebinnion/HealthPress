<?php
/**
 * Access control.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Support;

/**
 * The single access check for HealthPress data.
 *
 * HealthPress tracks one person's health data on a site they administer, so read
 * and write share one gate rather than drifting apart per entry point. If this
 * ever needs to serve more than one user, this is the one place to change.
 *
 * Lived in `HealthPress\Rest` while the REST API existed, and was the permission
 * callback for every route. The routes are gone; the gate is not, because the
 * admin save handler and the CLI both still need it. It sits in `Support` now
 * because it is a plain capability question with no transport attached.
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
}
