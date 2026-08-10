<?php
/**
 * Tests for schema generation.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Rest;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Rest\Schema_Factory;
use HealthPress\Support\Unit_Registry;
use PHPUnit\Framework\TestCase;

/**
 * The generated schema documents the catalog and drives the discovery
 * endpoint. It is explicitly *not* used for enforcement: the shape of `values`
 * depends on the value of a sibling argument (`metric`), which JSON Schema
 * cannot express, so Reading_Validator remains the only gate.
 *
 * @covers \HealthPress\Rest\Schema_Factory
 */
final class SchemaFactoryTest extends TestCase {

	private function factory(): Schema_Factory {
		return new Schema_Factory( Unit_Registry::create_default() );
	}

	private function blood_pressure(): Metric_Type {
		return new Metric_Type(
			'blood_pressure',
			'Blood Pressure',
			array(
				new Field( 'systolic', 'Systolic', Field_Type::Integer, 'mmhg', 40.0, 300.0, true, 0 ),
				new Field( 'diastolic', 'Diastolic', Field_Type::Integer, 'mmhg', 20.0, 200.0, true, 0 ),
			)
		);
	}

	private function sleep(): Metric_Type {
		return new Metric_Type(
			'sleep',
			'Sleep',
			array(
				new Field( 'duration', 'Duration', Field_Type::Number, 'minutes', 0.0, 1440.0, true, 0 ),
				new Field( 'quality', 'Quality', Field_Type::Integer, null, 1.0, 5.0, false, 0 ),
			),
			'duration'
		);
	}

	public function test_it_describes_a_metric(): void {
		$described = $this->factory()->describe_metric( $this->blood_pressure() );

		$this->assertSame( 'blood_pressure', $described['slug'] );
		$this->assertSame( 'Blood Pressure', $described['label'] );
		$this->assertSame( 'systolic', $described['primary_field'] );
	}

	public function test_it_describes_every_field(): void {
		$fields = $this->factory()->describe_metric( $this->blood_pressure() )['fields'];

		$this->assertCount( 2, $fields );
		$this->assertSame( 'systolic', $fields[0]['key'] );
		$this->assertSame( 'integer', $fields[0]['type'] );
		$this->assertSame( 'mmhg', $fields[0]['unit'] );
		$this->assertSame( 40.0, $fields[0]['min'] );
		$this->assertSame( 300.0, $fields[0]['max'] );
		$this->assertTrue( $fields[0]['required'] );
	}

	/**
	 * A client needs to know what it may ask for in the `unit` parameter.
	 */
	public function test_it_advertises_the_alternative_units_for_a_field(): void {
		$fields = $this->factory()->describe_metric( $this->blood_pressure() )['fields'];

		$this->assertSame( array( 'mmhg' ), $fields[0]['available_units'] );

		$weight = new Metric_Type( 'weight', 'Weight', array( new Field( 'value', 'Weight', Field_Type::Number, 'kg' ) ) );
		$units  = $this->factory()->describe_metric( $weight )['fields'][0]['available_units'];

		$this->assertContains( 'kg', $units );
		$this->assertContains( 'lb', $units );
		$this->assertContains( 'st', $units );
	}

	public function test_a_unitless_field_advertises_no_units(): void {
		$fields = $this->factory()->describe_metric( $this->sleep() )['fields'];

		$this->assertNull( $fields[1]['unit'] );
		$this->assertSame( array(), $fields[1]['available_units'] );
	}

	// -----------------------------------------------------------------
	// JSON Schema for the values object.
	// -----------------------------------------------------------------

	public function test_it_builds_a_json_schema_for_a_metric_values_object(): void {
		$schema = $this->factory()->values_schema( $this->blood_pressure() );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array( 'systolic', 'diastolic' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	public function test_the_schema_carries_each_field_type_and_bounds(): void {
		$properties = $this->factory()->values_schema( $this->blood_pressure() )['properties'];

		$this->assertSame( 'integer', $properties['systolic']['type'] );
		$this->assertSame( 40.0, $properties['systolic']['minimum'] );
		$this->assertSame( 300.0, $properties['systolic']['maximum'] );
	}

	public function test_an_optional_field_is_not_marked_required(): void {
		$schema = $this->factory()->values_schema( $this->sleep() );

		$this->assertSame( array( 'duration' ), $schema['required'] );
		$this->assertArrayHasKey( 'quality', $schema['properties'] );
	}

	/**
	 * The bounds in the schema are only meaningful alongside the unit they are
	 * expressed in.
	 */
	public function test_the_schema_records_the_unit_bounds_are_expressed_in(): void {
		$properties = $this->factory()->values_schema( $this->blood_pressure() )['properties'];

		$this->assertStringContainsString( 'mmhg', $properties['systolic']['description'] );
	}
}
