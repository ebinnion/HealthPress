<?php
/**
 * Physical dimensions a measurement can belong to.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Support;

/**
 * The dimension of a measurement.
 *
 * Unit conversion is resolved by dimension rather than by metric, so a request
 * for `?unit=lb` converts every mass-dimensioned field in the response and
 * leaves fields of any other dimension untouched.
 */
enum Dimension: string {
	case Mass          = 'mass';
	case Length        = 'length';
	case Temperature   = 'temperature';
	case Time          = 'time';
	case Pressure      = 'pressure';
	case Frequency     = 'frequency';
	case Count         = 'count';
	case Ratio         = 'ratio';
	case Concentration = 'concentration';
}
