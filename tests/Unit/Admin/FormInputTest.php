<?php
/**
 * Tests for shaping form input into validator input.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Admin;

use HealthPress\Admin\Form_Input;
use PHPUnit\Framework\TestCase;

/**
 * This is the seam between the form and the validator. It reshapes and nothing
 * else: no type checking, no range checking, no dropping of keys it does not
 * recognise — all of which belong to Reading_Validator, which is the only thing
 * allowed to reject a value.
 *
 * @covers \HealthPress\Admin\Form_Input
 */
final class FormInputTest extends TestCase {

	public function test_it_carries_the_metric_through(): void {
		$input = Form_Input::from_request( array(), 'weight' );

		$this->assertSame( 'weight', $input['metric'] );
	}

	/**
	 * Every metric's fields are rendered, so the POST carries groups for all of
	 * them. Only the selected metric's group is a submission.
	 */
	public function test_it_reads_only_the_selected_metrics_values(): void {
		$input = Form_Input::from_request(
			array(
				'values' => array(
					'weight' => array( 'value' => '78.2' ),
					'steps'  => array( 'value' => '9999' ),
				),
			),
			'weight'
		);

		$this->assertSame( array( 'value' => '78.2' ), $input['values'] );
	}

	public function test_it_yields_no_values_when_the_metric_has_no_group(): void {
		$input = Form_Input::from_request( array( 'values' => array( 'steps' => array( 'value' => '1' ) ) ), 'weight' );

		$this->assertSame( array(), $input['values'] );
	}

	public function test_it_handles_a_multi_field_metric(): void {
		$input = Form_Input::from_request(
			array(
				'values' => array(
					'blood_pressure' => array(
						'systolic'  => '118',
						'diastolic' => '76',
					),
				),
			),
			'blood_pressure'
		);

		$this->assertSame(
			array(
				'systolic'  => '118',
				'diastolic' => '76',
			),
			$input['values']
		);
	}

	// -----------------------------------------------------------------
	// Blank versus zero — the regression trap.
	// -----------------------------------------------------------------

	/**
	 * A blank input means "not supplied", which is what lets a required field
	 * raise `hp_missing_field` and an optional one be legitimately omitted.
	 */
	public function test_it_drops_a_blank_value(): void {
		$input = Form_Input::from_request(
			array(
				'values' => array(
					'sleep' => array(
						'duration' => '445',
						'quality'  => '',
					),
				),
			),
			'sleep'
		);

		$this->assertSame( array( 'duration' => '445' ), $input['values'] );
	}

	public function test_it_drops_a_whitespace_only_value(): void {
		$input = Form_Input::from_request(
			array( 'values' => array( 'weight' => array( 'value' => '   ' ) ) ),
			'weight'
		);

		$this->assertSame( array(), $input['values'] );
	}

	/**
	 * Zero is a real reading — `steps` declares `min = 0`. Any implementation
	 * built on `empty()` drops it, which is why this case exists.
	 */
	public function test_it_keeps_a_zero(): void {
		$input = Form_Input::from_request(
			array( 'values' => array( 'steps' => array( 'value' => '0' ) ) ),
			'steps'
		);

		$this->assertSame( array( 'value' => '0' ), $input['values'] );
	}

	public function test_it_keeps_a_negative_value(): void {
		$input = Form_Input::from_request(
			array( 'values' => array( 'body_temperature' => array( 'value' => '-5' ) ) ),
			'body_temperature'
		);

		$this->assertSame( array( 'value' => '-5' ), $input['values'] );
	}

	public function test_it_trims_surrounding_whitespace(): void {
		$input = Form_Input::from_request(
			array( 'values' => array( 'weight' => array( 'value' => ' 78.2 ' ) ) ),
			'weight'
		);

		$this->assertSame( array( 'value' => '78.2' ), $input['values'] );
	}

	// -----------------------------------------------------------------
	// Passing problems through rather than hiding them.
	// -----------------------------------------------------------------

	/**
	 * A key the metric does not declare must reach the validator so it can raise
	 * `hp_unknown_field`. Silently dropping it here would turn a typo into a
	 * successful save that recorded nothing.
	 */
	public function test_it_passes_an_unknown_key_through(): void {
		$input = Form_Input::from_request(
			array( 'values' => array( 'weight' => array( 'vlaue' => '70' ) ) ),
			'weight'
		);

		$this->assertSame( array( 'vlaue' => '70' ), $input['values'] );
	}

	/**
	 * Numeric strings pass through verbatim so `Field_Type::coerce()` remains
	 * the only authority on what is a valid number.
	 */
	public function test_it_does_not_coerce_types(): void {
		$input = Form_Input::from_request(
			array( 'values' => array( 'steps' => array( 'value' => 'lots' ) ) ),
			'steps'
		);

		$this->assertSame( array( 'value' => 'lots' ), $input['values'] );
	}

	public function test_it_survives_a_malformed_values_group(): void {
		$input = Form_Input::from_request( array( 'values' => 'not an array' ), 'weight' );

		$this->assertSame( array(), $input['values'] );
	}

	// -----------------------------------------------------------------
	// Note and source.
	// -----------------------------------------------------------------

	public function test_it_carries_a_note(): void {
		$input = Form_Input::from_request( array( 'note' => 'Before coffee.' ), 'weight' );

		$this->assertSame( 'Before coffee.', $input['note'] );
	}

	public function test_it_defaults_the_note_to_empty(): void {
		$this->assertSame( '', Form_Input::from_request( array(), 'weight' )['note'] );
	}

	/**
	 * The admin form is by definition a manual entry.
	 */
	public function test_it_always_reports_a_manual_source(): void {
		$input = Form_Input::from_request( array( 'source' => 'api' ), 'weight' );

		$this->assertSame( 'manual', $input['source'] );
	}

	// -----------------------------------------------------------------
	// The timestamp.
	// -----------------------------------------------------------------

	/**
	 * The Publish box owns the timestamp, so it arrives from the post rather
	 * than the form. It has to be passed through explicitly: omitting it would
	 * make the validator default to "now", and `save()` would then write that
	 * over whatever date the user actually set.
	 */
	public function test_it_takes_the_timestamp_from_the_post(): void {
		$input = Form_Input::from_request( array(), 'weight', '2026-08-08 07:14:00' );

		$this->assertSame( '2026-08-08 07:14:00', $input['recorded_at'] );
	}

	/**
	 * The form's own fields can never influence the timestamp.
	 */
	public function test_it_ignores_a_timestamp_submitted_by_the_form(): void {
		$input = Form_Input::from_request( array( 'recorded_at' => '1999-01-01T00:00:00Z' ), 'weight', '2026-08-08 07:14:00' );

		$this->assertSame( '2026-08-08 07:14:00', $input['recorded_at'] );
	}

	public function test_it_omits_the_timestamp_when_the_post_has_none(): void {
		$this->assertArrayNotHasKey( 'recorded_at', Form_Input::from_request( array(), 'weight' ) );
	}
}
