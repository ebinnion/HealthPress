<?php
/**
 * A single validation failure.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Validation;

/**
 * One reason a reading was rejected.
 *
 * Deliberately a plain struct rather than a `WP_Error`: keeping WordPress out
 * of the validator is what lets the entire rule set be exercised without a
 * bootstrap. Translation to `WP_Error` happens at the REST boundary.
 */
final readonly class Violation {

	/**
	 * Records one reason a reading was rejected.
	 *
	 * @param string               $code    Machine-readable code, e.g. `hp_out_of_range`.
	 * @param string               $message Human-readable explanation.
	 * @param string|null          $field   Field key this concerns, when applicable.
	 * @param array<string, mixed> $data    Supporting detail, e.g. min/max/received.
	 */
	public function __construct(
		public string $code,
		public string $message,
		public ?string $field = null,
		public array $data = array(),
	) {}
}
