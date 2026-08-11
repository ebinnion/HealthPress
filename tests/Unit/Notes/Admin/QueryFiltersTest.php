<?php
/**
 * Tests for the note filter query builders.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Tests\Unit\Notes\Admin;

use Brain\Monkey\Functions;
use HealthPress\Notes\Admin\Query_Filters;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * These translate request parameters into `tax_query` and `date_query`
 * fragments. They are pure so that "the wrong rows came back" is a unit test
 * failure rather than something noticed by eye in the admin.
 *
 * @covers \HealthPress\Notes\Admin\Query_Filters
 */
final class QueryFiltersTest extends TestCase {

	/**
	 * Stubs the sanitisers the builders use.
	 *
	 * The `sanitize_title` stub only lowercases and trims; the real function
	 * also replaces spaces with hyphens, strips accents and removes anything
	 * that is not slug-safe. Every value asserted below is already a valid slug
	 * — or '', '0', which the real function returns unchanged — so the stub and
	 * the real function agree on all of them. Nothing here is a test *of*
	 * slugification; that transform is exercised against real terms in the
	 * integration suite.
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_title' )->alias(
			static fn ( string $value ): string => strtolower( trim( $value ) )
		);
	}

	// -----------------------------------------------------------------
	// tax_query.
	// -----------------------------------------------------------------

	/**
	 * An unfiltered list must not gain a `tax_query` at all, since an empty one
	 * still costs a join.
	 */
	public function test_no_parameters_produce_no_tax_query(): void {
		$this->assertSame( array(), Query_Filters::tax_query( array() ) );
	}

	/**
	 * The shape of a single clause is the contract with `WP_Query`.
	 */
	public function test_it_builds_a_clause_for_one_taxonomy(): void {
		$query = Query_Filters::tax_query( array( 'hp_note_kind' => 'transcript' ) );

		$this->assertSame(
			array(
				array(
					'taxonomy'         => 'hp_note_kind',
					'field'            => 'slug',
					'terms'            => array( 'transcript' ),
					'include_children' => false,
				),
			),
			$query
		);
	}

	/**
	 * Filters compose: kind and provider together narrow rather than replace.
	 */
	public function test_it_builds_a_clause_per_taxonomy_supplied(): void {
		$query = Query_Filters::tax_query(
			array(
				'hp_note_kind'     => 'transcript',
				'hp_note_provider' => 'dr-smith',
			)
		);

		$this->assertCount( 2, $query );
	}

	/**
	 * An empty select submits '', and '0' is the "no kind" option's value. Both
	 * mean "do not filter", not "match the empty slug" — which would return
	 * nothing at all.
	 */
	public function test_it_ignores_empty_and_zero_values(): void {
		$this->assertSame( array(), Query_Filters::tax_query( array( 'hp_note_kind' => '' ) ) );
		$this->assertSame( array(), Query_Filters::tax_query( array( 'hp_note_kind' => '0' ) ) );
	}

	/**
	 * The builder reads `$_GET`, which carries plenty besides these filters.
	 */
	public function test_it_ignores_parameters_that_are_not_note_taxonomies(): void {
		$this->assertSame( array(), Query_Filters::tax_query( array( 'category' => 'health' ) ) );
	}

	// -----------------------------------------------------------------
	// date_query.
	// -----------------------------------------------------------------

	/**
	 * As with `tax_query`: absent bounds mean no clause.
	 */
	public function test_no_dates_produce_no_date_query(): void {
		$this->assertSame( array(), Query_Filters::date_query( array() ) );
	}

	/**
	 * Both bounds are widened to whole days, so a note recorded at any hour on
	 * either end day is inside the range.
	 */
	public function test_it_builds_an_inclusive_range(): void {
		$query = Query_Filters::date_query(
			array(
				'hp_note_from' => '2026-03-01',
				'hp_note_to'   => '2026-03-31',
			)
		);

		$this->assertSame(
			array(
				array(
					'after'     => '2026-03-01 00:00:00',
					'before'    => '2026-03-31 23:59:59',
					'inclusive' => true,
					'column'    => 'post_date',
				),
			),
			$query
		);
	}

	/**
	 * One bound is a legitimate filter — "everything since" — not a half-filled
	 * form to be discarded.
	 */
	public function test_it_builds_an_open_ended_range_from_one_bound(): void {
		$query = Query_Filters::date_query( array( 'hp_note_from' => '2026-03-01' ) );

		$this->assertSame( '2026-03-01 00:00:00', $query[0]['after'] );
		$this->assertArrayNotHasKey( 'before', $query[0] );
	}

	/**
	 * The inputs are `type="date"`, but a hand-edited URL is not, and a
	 * malformed bound must be dropped rather than passed to `date_query` where
	 * it would be interpreted by `strtotime()` as something unpredictable.
	 */
	public function test_it_drops_a_malformed_date(): void {
		$this->assertSame( array(), Query_Filters::date_query( array( 'hp_note_from' => 'last tuesday' ) ) );
		$this->assertSame( array(), Query_Filters::date_query( array( 'hp_note_to' => '2026-13-45' ) ) );
	}
}
