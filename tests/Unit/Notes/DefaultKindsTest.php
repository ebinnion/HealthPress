<?php
/**
 * Tests for the shipped note kinds.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Notes;

use HealthPress\Notes\Default_Kinds;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * The kind list is plain data seeded into a taxonomy on activation, so these
 * tests exist to catch a typo in a slug — which would seed a second term
 * alongside the one already there rather than failing.
 *
 * @covers \HealthPress\Notes\Default_Kinds
 */
final class DefaultKindsTest extends TestCase {

	/**
	 * Stubs the translation functions the labels rely on.
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->stubTranslationFunctions();
	}

	/**
	 * Asserts the order too, deliberately. These slugs are persisted
	 * identifiers, so renaming one orphans real terms and every note attached
	 * to them; needing to edit this line to change the list is the point. The
	 * order is contractual for the same reason `all()` documents it — it is the
	 * order the Kind select renders in.
	 */
	public function test_it_ships_the_expected_kinds_in_order(): void {
		$this->assertSame(
			array( 'transcript', 'doctor_note', 'lab_summary', 'personal_log' ),
			array_keys( Default_Kinds::all() )
		);
	}

	public function test_every_kind_has_a_label(): void {
		foreach ( Default_Kinds::all() as $slug => $label ) {
			$this->assertNotSame( '', trim( $label ), "The {$slug} kind has no label." );
		}
	}

	/**
	 * Slugs go straight into `wp_insert_term()` as the term slug, so one that
	 * `sanitize_title()` would rewrite is a latent duplicate-term bug.
	 *
	 * This enforces the narrower house style rather than slug-safety as such:
	 * `sanitize_title()` leaves hyphens alone, so `follow-up` would be
	 * perfectly safe and still fail here. Underscores are what the existing
	 * `hp_metric` slugs use — `blood_pressure`, `resting_heart_rate` — and
	 * matching them is worth more than the extra latitude. Named for what it
	 * actually checks, so a future hyphenated kind reads as a style decision to
	 * make rather than a bug to fix.
	 */
	public function test_every_slug_uses_the_house_underscore_style(): void {
		foreach ( array_keys( Default_Kinds::all() ) as $slug ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $slug );
		}
	}
}
