<?php
/**
 * HTML constraints for a metric field.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Admin;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;

/**
 * Derives the HTML5 constraints a field's input should carry.
 *
 * The browser gets the same bounds the validator will apply, so a mistake is
 * caught before the round trip. The server stays authoritative: every one of
 * these is re-checked in `Reading_Validator`, and none of them can be relied on
 * — a client can simply not send them.
 *
 * Pure and static, so the whole matrix is unit-testable without a bootstrap.
 */
final class Field_Attributes {

	/**
	 * Builds the attribute map for a field's input.
	 *
	 * @param Field $field The field being rendered.
	 *
	 * @return array<string, string> Attribute name => value, still to be escaped.
	 */
	public static function for( Field $field ): array {
		$attributes = array(
			'type' => 'number',
			'step' => self::step( $field ),
		);

		if ( null !== $field->min ) {
			$attributes['min'] = $field->format( $field->min );
		}

		if ( null !== $field->max ) {
			$attributes['max'] = $field->format( $field->max );
		}

		if ( $field->required ) {
			$attributes['required'] = 'required';
		}

		return $attributes;
	}

	/**
	 * The smallest increment this field can actually store.
	 *
	 * Precision is the storage granularity — `Meta`'s sanitize_callback rounds
	 * to it on every write — so deriving `step` from it is what stops the form
	 * accepting a number that would silently change on the way into the
	 * database.
	 *
	 * An integer field steps by one whatever precision it declares, because
	 * that is what the type means; `Field_Type::coerce()` would reject a
	 * fraction regardless.
	 *
	 * @param Field $field The field being rendered.
	 */
	private static function step( Field $field ): string {
		if ( Field_Type::Integer === $field->type || 0 === $field->precision ) {
			return '1';
		}

		return sprintf( '%.' . $field->precision . 'F', 10 ** -$field->precision );
	}
}
