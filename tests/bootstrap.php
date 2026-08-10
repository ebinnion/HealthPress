<?php
/**
 * PHPUnit bootstrap for the HealthPress unit suite.
 *
 * The unit suite deliberately does NOT load WordPress. Everything under
 * tests/Unit exercises either pure PHP (Support, Metrics, Validation) or code
 * whose WordPress calls are stubbed by Brain Monkey. Integration coverage lives
 * in tests/Integration and runs via `studio wp eval-file` against the real site.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';
