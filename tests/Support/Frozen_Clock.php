<?php
/**
 * Test double for the clock.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use HealthPress\Support\Clock;

/**
 * A clock stopped at a fixed instant, so time-sensitive rules are deterministic.
 */
final class Frozen_Clock implements Clock {

	/**
	 * The instant this clock reports.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * @param string $iso8601 Instant to freeze at, in UTC.
	 */
	public function __construct( string $iso8601 = '2026-08-09T12:00:00+00:00' ) {
		$this->now = new DateTimeImmutable( $iso8601, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Returns the frozen instant.
	 */
	public function now(): DateTimeImmutable {
		return $this->now;
	}
}
