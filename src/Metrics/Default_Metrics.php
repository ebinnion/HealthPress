<?php
/**
 * The metric catalog shipped with the plugin.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Metrics;

/**
 * Builds the built-in metric types.
 *
 * All bounds are expressed in the field's canonical unit and are deliberately
 * generous: they exist to catch transposed digits and unit mix-ups, not to
 * make a clinical judgement about what is normal.
 *
 * Labels are translated here; nothing that hashes this catalog may include
 * them, or a change of site locale would look like a schema change.
 */
final class Default_Metrics {

	/**
	 * Returns the built-in metric types, in display order.
	 *
	 * @return list<Metric_Type>
	 */
	public static function all(): array {
		return array(
			new Metric_Type(
				'blood_pressure',
				__( 'Blood Pressure', 'healthpress' ),
				array(
					new Field(
						'systolic',
						__( 'Systolic', 'healthpress' ),
						Field_Type::Integer,
						'mmhg',
						40.0,
						300.0,
						true,
						0
					),
					new Field(
						'diastolic',
						__( 'Diastolic', 'healthpress' ),
						Field_Type::Integer,
						'mmhg',
						20.0,
						200.0,
						true,
						0
					),
				)
			),
			new Metric_Type(
				'weight',
				__( 'Weight', 'healthpress' ),
				array(
					new Field( 'value', __( 'Weight', 'healthpress' ), Field_Type::Number, 'kg', 1.0, 500.0, true, 2 ),
				)
			),
			new Metric_Type(
				'resting_heart_rate',
				__( 'Resting Heart Rate', 'healthpress' ),
				array(
					new Field( 'value', __( 'Resting Heart Rate', 'healthpress' ), Field_Type::Integer, 'bpm', 20.0, 250.0, true, 0 ),
				)
			),
			new Metric_Type(
				'body_temperature',
				__( 'Body Temperature', 'healthpress' ),
				array(
					new Field( 'value', __( 'Body Temperature', 'healthpress' ), Field_Type::Number, 'c', 25.0, 45.0, true, 1 ),
				)
			),
			new Metric_Type(
				'steps',
				__( 'Steps', 'healthpress' ),
				array(
					new Field( 'value', __( 'Steps', 'healthpress' ), Field_Type::Integer, 'count', 0.0, 200000.0, true, 0 ),
				)
			),
			new Metric_Type(
				'sleep',
				__( 'Sleep', 'healthpress' ),
				array(
					new Field( 'duration', __( 'Duration', 'healthpress' ), Field_Type::Number, 'minutes', 0.0, 1440.0, true, 0 ),
					new Field(
						'quality',
						__( 'Quality', 'healthpress' ),
						Field_Type::Integer,
						null,
						1.0,
						5.0,
						false,
						0,
						__( 'Subjective sleep quality, from 1 (poor) to 5 (excellent).', 'healthpress' )
					),
				),
				'duration'
			),
			new Metric_Type(
				'blood_glucose',
				__( 'Blood Glucose', 'healthpress' ),
				array(
					new Field( 'value', __( 'Blood Glucose', 'healthpress' ), Field_Type::Number, 'mg_dl', 10.0, 1000.0, true, 1 ),
				)
			),
			new Metric_Type(
				'spo2',
				__( 'Blood Oxygen', 'healthpress' ),
				array(
					new Field( 'value', __( 'Blood Oxygen Saturation', 'healthpress' ), Field_Type::Integer, 'percent', 50.0, 100.0, true, 0 ),
				)
			),
			new Metric_Type(
				'height',
				__( 'Height', 'healthpress' ),
				array(
					new Field( 'value', __( 'Height', 'healthpress' ), Field_Type::Number, 'cm', 30.0, 260.0, true, 1 ),
				)
			),
		);
	}
}
