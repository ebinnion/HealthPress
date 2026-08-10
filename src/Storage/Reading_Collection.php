<?php
/**
 * A page of readings.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Storage;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A page of readings, plus the total when one was asked for.
 *
 * `total()` is null unless the query opted into counting — on the SQLite
 * driver that count is a second query, so it is never done speculatively.
 *
 * @implements IteratorAggregate<int, Reading>
 */
final readonly class Reading_Collection implements Countable, IteratorAggregate {

	/**
	 * Holds a page of readings.
	 *
	 * @param list<Reading> $items The readings on this page.
	 * @param int|null      $total Total matching readings, or null when not counted.
	 */
	public function __construct(
		public array $items,
		public ?int $total = null,
	) {}

	/**
	 * Returns the readings on this page.
	 *
	 * @return list<Reading>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Returns the total number of matching readings, or null when not counted.
	 */
	public function total(): ?int {
		return $this->total;
	}

	/**
	 * Returns the number of readings on this page.
	 */
	public function count(): int {
		return count( $this->items );
	}

	/**
	 * Allows the collection to be iterated directly.
	 *
	 * @return Traversable<int, Reading>
	 */
	public function getIterator(): Traversable {
		return new \ArrayIterator( $this->items );
	}
}
