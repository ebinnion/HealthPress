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

	public function test_it_ships_the_expected_kinds(): void {
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
	 * Slugs go straight into `wp_insert_term()` as the term slug, so anything
	 * `sanitize_title()` would rewrite is a latent duplicate-term bug.
	 */
	public function test_every_slug_is_already_slug_safe(): void {
		foreach ( array_keys( Default_Kinds::all() ) as $slug ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $slug );
		}
	}
}
