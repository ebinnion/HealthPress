<?php
/**
 * The real clock.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reports the actual current time, in UTC.
 */
final class System_Clock implements Clock {

	/**
	 * Returns the current instant, in UTC.
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}
