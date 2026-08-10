<?php
/**
 * Source of the current time.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Support;

use DateTimeImmutable;

/**
 * Supplies "now".
 *
 * Injected rather than called directly so that time-sensitive rules — chiefly
 * the rejection of future timestamps — are deterministic under test.
 */
interface Clock {

	/**
	 * Returns the current instant, in UTC.
	 */
	public function now(): DateTimeImmutable;
}
