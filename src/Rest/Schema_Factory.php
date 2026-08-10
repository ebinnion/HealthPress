<?php
/**
 * Turns metric definitions into API documentation.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Rest;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Support\Unit;
use HealthPress\Support\Unit_Registry;

/**
 * Describes metrics for the discovery endpoint and for `get_item_schema()`.
 *
 * Explicitly not an enforcement mechanism. The permitted shape of `values`
 * depends on the value of a sibling argument (`metric`), which JSON Schema
 * cannot express, so this generates documentation from the same catalog the
 * validator reads and leaves rejection to `Reading_Validator`.
 */
final class Schema_Factory {

	/**
	 * Wires the catalog used to resolve unit slugs.
	 *
	 * @param Unit_Registry $units The unit catalog.
	 */
	public function __construct( private readonly Unit_Registry $units ) {}

	/**
	 * Describes a metric for the discovery endpoint.
	 *
	 * @param Metric_Type $metric The metric to describe.
	 *
	 * @return array<string, mixed>
	 */
	public function describe_metric( Metric_Type $metric ): array {
		return array(
			'slug'          => $metric->slug,
			'label'         => $metric->label,
			'description'   => (string) $metric->description,
			'primary_field' => $metric->primary_field_key(),
			'fields'        => array_map(
				fn ( Field $field ): array => $this->describe_field( $field ),
				$metric->fields
			),
		);
	}

	/**
	 * Describes a single field.
	 *
	 * @param Field $field The field to describe.
	 *
	 * @return array<string, mixed>
	 */
	private function describe_field( Field $field ): array {
		return array(
			'key'             => $field->key,
			'label'           => $field->label,
			'description'     => (string) $field->description,
			'type'            => $field->type->json_type(),
			'unit'            => $field->unit,
			'min'             => $field->min,
			'max'             => $field->max,
			'required'        => $field->required,
			'precision'       => $field->precision,
			'available_units' => $this->available_units( $field ),
		);
	}

	/**
	 * Lists the units a field's value may be requested or supplied in.
	 *
	 * @param Field $field The field in question.
	 *
	 * @return list<string>
	 */
	private function available_units( Field $field ): array {
		if ( ! $field->has_unit() || ! $this->units->has( $field->unit ) ) {
			return array();
		}

		return array_map(
			static fn ( Unit $unit ): string => $unit->slug,
			$this->units->in_dimension( $this->units->get( $field->unit )->dimension )
		);
	}

	/**
	 * Builds the JSON Schema for a metric's `values` object.
	 *
	 * @param Metric_Type $metric The metric to describe.
	 *
	 * @return array<string, mixed>
	 */
	public function values_schema( Metric_Type $metric ): array {
		$properties = array();

		foreach ( $metric->fields as $field ) {
			$property = array(
				'type'        => $field->type->json_type(),
				'description' => $this->field_description( $field ),
			);

			if ( null !== $field->min ) {
				$property['minimum'] = $field->min;
			}

			if ( null !== $field->max ) {
				$property['maximum'] = $field->max;
			}

			$properties[ $field->key ] = $property;
		}

		return array(
			'type'                 => 'object',
			'description'          => sprintf( 'Measured values for %s.', $metric->label ),
			'properties'           => $properties,
			'required'             => $metric->required_field_keys(),

			// Unknown keys are rejected, not ignored — a typo must not look like success.
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds a field's description, naming the unit its bounds are in.
	 *
	 * @param Field $field The field to describe.
	 */
	private function field_description( Field $field ): string {
		if ( ! $field->has_unit() ) {
			return $field->label;
		}

		return sprintf( '%s, in %s.', $field->label, $field->unit );
	}
}
