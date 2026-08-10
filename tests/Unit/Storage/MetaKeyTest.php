<?php
/**
 * Tests for meta key derivation.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Storage;

use HealthPress\Metrics\Field;
use HealthPress\Metrics\Field_Type;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Storage\Meta;
use PHPUnit\Framework\TestCase;

/**
 * Meta keys are namespaced per metric rather than per field name. Five of the
 * shipped metrics want a field called `value`; sharing one `_hp_value` key
 * would make it impossible to register a per-metric type, and would make the
 * key non-selective in queries.
 *
 * @covers \HealthPress\Storage\Meta
 */
final class MetaKeyTest extends TestCase {

	private function weight(): Metric_Type {
		return new Metric_Type( 'weight', 'Weight', array( new Field( 'value', 'Weight', Field_Type::Number, 'kg' ) ) );
	}

	private function steps(): Metric_Type {
		return new Metric_Type( 'steps', 'Steps', array( new Field( 'value', 'Steps', Field_Type::Integer, 'count' ) ) );
	}

	public function test_it_namespaces_the_key_by_metric_and_field(): void {
		$this->assertSame( '_hp_weight_value', Meta::key( $this->weight(), 'value' ) );
	}

	public function test_metrics_sharing_a_field_name_get_distinct_keys(): void {
		$this->assertNotSame(
			Meta::key( $this->weight(), 'value' ),
			Meta::key( $this->steps(), 'value' )
		);
	}

	public function test_it_handles_multi_field_metrics(): void {
		$blood_pressure = new Metric_Type(
			'blood_pressure',
			'Blood Pressure',
			array(
				new Field( 'systolic', 'Systolic', Field_Type::Integer, 'mmhg' ),
				new Field( 'diastolic', 'Diastolic', Field_Type::Integer, 'mmhg' ),
			)
		);

		$this->assertSame( '_hp_blood_pressure_systolic', Meta::key( $blood_pressure, 'systolic' ) );
		$this->assertSame( '_hp_blood_pressure_diastolic', Meta::key( $blood_pressure, 'diastolic' ) );
	}

	/**
	 * The underscore prefix marks the key protected, keeping it out of the
	 * custom fields UI and out of REST unless explicitly exposed.
	 */
	public function test_every_key_is_protected(): void {
		$this->assertStringStartsWith( '_', Meta::key( $this->weight(), 'value' ) );
	}

	/**
	 * MySQL indexes meta_key on a 191-character prefix, and the column itself
	 * is 255. The longest shipped key is nowhere near either, but a future
	 * metric with a long slug could be.
	 */
	public function test_keys_stay_within_the_indexable_prefix(): void {
		$this->assertLessThan( 191, strlen( Meta::key( $this->weight(), 'value' ) ) );
	}
}
