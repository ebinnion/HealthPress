<?php
/**
 * Reshapes admin form input for the validator.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Admin;

/**
 * Turns the `hp` POST array into the shape `Reading_Validator` expects.
 *
 * Reshaping only. It does not coerce types, check ranges, or drop keys it does
 * not recognise — all of that belongs to the validator, which is the single
 * thing allowed to reject a value. In particular an unrecognised key is passed
 * straight through so `hp_unknown_field` can fire, rather than being quietly
 * discarded and turning a typo into a save that recorded nothing.
 *
 * Kept free of WordPress so it can be unit tested without a bootstrap; the
 * handler does the unslashing and sanitising before calling in.
 */
final class Form_Input {

	/**
	 * Builds validator input from a submitted form.
	 *
	 * Values arrive nested under their metric slug, because five of the shipped
	 * metrics declare a field called `value` and every metric's group is
	 * rendered — a flat map would collide across them.
	 *
	 * @param array<string, mixed> $raw         The unslashed `hp` POST array.
	 * @param string               $metric_slug The metric this save is for.
	 * @param string               $recorded_at The post's timestamp, or '' to let the validator default it.
	 *
	 * @return array<string, mixed>
	 */
	public static function from_request( array $raw, string $metric_slug, string $recorded_at = '' ): array {
		$input = array(
			'metric' => $metric_slug,
			'values' => self::values_for( $raw, $metric_slug ),
			'note'   => is_string( $raw['note'] ?? null ) ? $raw['note'] : '',

			// The admin form is by definition a manual entry.
			'source' => 'manual',
		);

		/*
		 * The timestamp comes from the post, not the form: the Publish box owns
		 * it. Passing it explicitly matters — omitting it would make the
		 * validator default to "now", and save() would then write that over the
		 * date the user actually chose.
		 */
		if ( '' !== $recorded_at ) {
			$input['recorded_at'] = $recorded_at;
		}

		return $input;
	}

	/**
	 * Extracts the selected metric's value group.
	 *
	 * @param array<string, mixed> $raw         The unslashed `hp` POST array.
	 * @param string               $metric_slug The metric this save is for.
	 *
	 * @return array<string, string>
	 */
	private static function values_for( array $raw, string $metric_slug ): array {
		$groups = $raw['values'] ?? null;

		if ( ! is_array( $groups ) || ! is_array( $groups[ $metric_slug ] ?? null ) ) {
			return array();
		}

		$values = array();

		foreach ( $groups[ $metric_slug ] as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			/*
			 * Blank means "not supplied", which is what lets a required field
			 * raise hp_missing_field and an optional one be legitimately
			 * omitted. Note this is a string comparison, not empty(): '0' is a
			 * real reading, and `steps` declares a minimum of zero.
			 */
			if ( '' === $value ) {
				continue;
			}

			$values[ (string) $key ] = $value;
		}

		return $values;
	}
}
