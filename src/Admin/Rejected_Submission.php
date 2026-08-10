<?php
/**
 * A form submission that failed validation.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Admin;

use HealthPress\Validation\Violation;

/**
 * What was submitted, and why it was refused.
 *
 * Survives one redirect so the edit screen can explain the refusal and hand the
 * user's own input back rather than making them retype it — including the
 * out-of-range number they need to see in order to correct it.
 */
final readonly class Rejected_Submission {

	/**
	 * Holds a refused submission.
	 *
	 * @param list<Violation>      $violations  Every reason it was refused.
	 * @param array<string, mixed> $input       The raw `hp` array as submitted.
	 * @param bool                 $quarantined Whether the post was demoted to a draft.
	 */
	public function __construct(
		public array $violations,
		public array $input,
		public bool $quarantined = false,
	) {}

	/**
	 * Flattens to plain arrays for storage.
	 *
	 * Deliberately not the objects themselves: a serialised object in the
	 * options table becomes `__PHP_Incomplete_Class` if the plugin is
	 * deactivated between the write and the read.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$violations = array();

		foreach ( $this->violations as $violation ) {
			$violations[] = array(
				'code'    => $violation->code,
				'message' => $violation->message,
				'field'   => $violation->field,
				'data'    => $violation->data,
			);
		}

		return array(
			'violations'  => $violations,
			'input'       => $this->input,
			'quarantined' => $this->quarantined,
		);
	}

	/**
	 * Rebuilds from storage.
	 *
	 * @param array<string, mixed> $stored A value previously produced by to_array().
	 */
	public static function from_array( array $stored ): self {
		$violations = array();

		foreach ( is_array( $stored['violations'] ?? null ) ? $stored['violations'] : array() as $violation ) {
			if ( ! is_array( $violation ) ) {
				continue;
			}

			$violations[] = new Violation(
				(string) ( $violation['code'] ?? 'hp_invalid' ),
				(string) ( $violation['message'] ?? '' ),
				isset( $violation['field'] ) ? (string) $violation['field'] : null,
				is_array( $violation['data'] ?? null ) ? $violation['data'] : array()
			);
		}

		return new self(
			$violations,
			is_array( $stored['input'] ?? null ) ? $stored['input'] : array(),
			(bool) ( $stored['quarantined'] ?? false )
		);
	}

	/**
	 * Returns the value submitted for a field, for repopulating the form.
	 *
	 * @param string $metric_slug The metric group the field belongs to.
	 * @param string $field_key   The field within that group.
	 */
	public function value_for( string $metric_slug, string $field_key ): string {
		$value = $this->input['values'][ $metric_slug ][ $field_key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Returns the metric that was submitted, if any.
	 */
	public function metric(): string {
		return is_string( $this->input['metric'] ?? null ) ? $this->input['metric'] : '';
	}

	/**
	 * Returns the note that was submitted.
	 */
	public function note(): string {
		return is_string( $this->input['note'] ?? null ) ? $this->input['note'] : '';
	}
}
