<?php
/**
 * A request for readings.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Storage;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Describes which readings to fetch.
 *
 * Both filter dimensions map onto indexed columns: the metric resolves through
 * the taxonomy tables, and the window is a range scan on `post_date_gmt`.
 *
 * Ordering is by time only. Ordering by a measured value needs a `meta_key`
 * join and a `DECIMAL` cast whose behaviour differs between drivers; it can be
 * added when there is a use case that wants it.
 */
final readonly class Reading_Query {

	/**
	 * Largest page this query will return.
	 */
	public const MAX_LIMIT = 100;

	/**
	 * Describes a set of readings to fetch.
	 *
	 * @param list<string>           $metrics     Metric slugs to include; empty means all.
	 * @param DateTimeImmutable|null $after       Inclusive start of the window.
	 * @param DateTimeImmutable|null $before      Inclusive end of the window.
	 * @param int                    $limit       Maximum readings to return.
	 * @param int                    $offset      Readings to skip.
	 * @param string                 $order       `ASC` or `DESC`, by recorded time.
	 * @param bool                   $count_total Whether to also count matching rows.
	 *
	 * @throws InvalidArgumentException When the order is unrecognised or the paging is out of bounds.
	 */
	public function __construct(
		public array $metrics = array(),
		public ?DateTimeImmutable $after = null,
		public ?DateTimeImmutable $before = null,
		public int $limit = 20,
		public int $offset = 0,
		public string $order = 'DESC',
		public bool $count_total = false,
	) {
		if ( ! in_array( strtoupper( $order ), array( 'ASC', 'DESC' ), true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Order must be ASC or DESC, got "%s".', $order )
			);
		}

		if ( $limit < 1 || $limit > self::MAX_LIMIT ) {
			throw new InvalidArgumentException(
				sprintf( 'Limit must be between 1 and %d, got %d.', self::MAX_LIMIT, $limit )
			);
		}

		if ( $offset < 0 ) {
			throw new InvalidArgumentException( sprintf( 'Offset cannot be negative, got %d.', $offset ) );
		}
	}

	/**
	 * The order, normalised to upper case.
	 */
	public function direction(): string {
		return strtoupper( $this->order );
	}
}
