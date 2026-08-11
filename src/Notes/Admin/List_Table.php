<?php
/**
 * The notes list screen.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Notes\Admin;

use HealthPress\Notes\Post_Type;
use WP_Query;

/**
 * Adds the filter bar and the snippet column to the notes list.
 *
 * Retrieval is the whole point of notes, so this class carries more weight than
 * its readings counterpart. Full-text search needs nothing at all: the body is
 * `post_content`, so core's own search box already covers it.
 *
 * What core does not give is filtering. The note taxonomies register
 * `query_var => false`, and `WP_Query::parse_tax_query()` only reads a
 * taxonomy's URL parameter when its `query_var` is truthy — so
 * `?hp_note_kind=transcript` is ignored entirely. `filter_query()` therefore
 * translates the parameters itself, which is also what makes the filter links
 * in the `show_admin_column` taxonomy columns work, since those links build
 * exactly those URLs.
 *
 * Search here is `LIKE '%term%'`, not ranked. Studio runs on SQLite, which has
 * no `FULLTEXT` support, so that is the available shape rather than a
 * compromise — and it is more than enough for a personal archive.
 */
final class List_Table {

	/**
	 * Words of body text shown in the snippet column.
	 */
	private const SNIPPET_WORDS = 15;

	/**
	 * The snippet column's key.
	 */
	private const SNIPPET_COLUMN = 'hp_snippet';

	/**
	 * Registers the screen's hooks.
	 */
	public function register(): void {
		add_filter( 'manage_' . Post_Type::SLUG . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Post_Type::SLUG . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filters' ), 10, 1 );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
	}

	/**
	 * Adds a snippet column and relabels Date.
	 *
	 * The snippet is inserted immediately after the title rather than appended,
	 * so it lands before the three taxonomy columns core adds for
	 * `show_admin_column`. A keyword archive is scanned down the left-hand side;
	 * pushing the matched text out past Kind, Provider and Tags would defeat it.
	 *
	 * A column rather than a line under the title, because core renders the
	 * title cell itself and reaching inside it would mean filtering `the_title`
	 * — which would also change the text on every other screen.
	 *
	 * @param array<string, string> $columns Column key => heading.
	 *
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		if ( isset( $columns['date'] ) ) {
			// `post_date` is when the call or visit happened, not when it was typed.
			$columns['date'] = __( 'Occurred', 'healthpress' );
		}

		$ordered = array();

		foreach ( $columns as $key => $heading ) {
			$ordered[ $key ] = $heading;

			if ( 'title' === $key ) {
				$ordered[ self::SNIPPET_COLUMN ] = __( 'Snippet', 'healthpress' );
			}
		}

		return $ordered;
	}

	/**
	 * Renders the snippet cell.
	 *
	 * `int $post_id` is safe despite this file declaring `strict_types`, and the
	 * reason is worth recording: PHP decides coercion by the file the *call* is
	 * made from, not the file the callback is declared in. Core dispatches this
	 * through `WP_Hook`, which is not strict, so a `post_date`-style string ID
	 * coerces rather than throwing. Verified rather than assumed.
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id The row's post.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::SNIPPET_COLUMN !== $column ) {
			return;
		}

		$body = (string) get_post_field( 'post_content', $post_id );

		if ( '' === trim( $body ) ) {
			return;
		}

		echo esc_html( wp_trim_words( $body, self::SNIPPET_WORDS ) );
	}

	/**
	 * Renders the filter bar above the list.
	 *
	 * Core fires `restrict_manage_posts` with `( $post_type, $which )`; only the
	 * first is taken, because the bar is identical top and bottom.
	 *
	 * @param string $post_type The post type the list is showing.
	 */
	public function render_filters( string $post_type ): void {
		if ( Post_Type::SLUG !== $post_type ) {
			return;
		}

		foreach ( Query_Filters::taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( false === $object ) {
				continue;
			}

			/*
			 * `value_field => 'slug'` makes each option's value the term slug,
			 * which is what Query_Filters expects and what the taxonomy columns'
			 * own filter links already use. Walker_CategoryDropdown compares
			 * `selected` against that same field, so the control keeps its value
			 * after filtering.
			 *
			 * The "All" option is the exception: core hardcodes it to `value='0'`
			 * regardless of `value_field`, and matches it against a literal `'0'`
			 * when deciding what is selected. So choosing it submits
			 * `?hp_note_kind=0`, and what makes that mean "unfiltered" rather
			 * than "match a term slugged 0" — which would return nothing — is
			 * the `'0'` guard in Query_Filters. That guard therefore carries two
			 * unrelated sentinels: this one, and the Kind metabox's "None".
			 * `option_none_value` is deliberately not passed; it applies only to
			 * `show_option_none`, so setting it here would look load-bearing
			 * while doing nothing.
			 *
			 * `hide_if_empty` matters too: provider and tag start with no terms
			 * at all, and an empty dropdown is a control that cannot do anything.
			 */
			wp_dropdown_categories(
				array(
					'taxonomy'        => $taxonomy,
					'name'            => $taxonomy,
					'show_option_all' => $object->labels->all_items,
					'value_field'     => 'slug',
					'selected'        => $this->selected( $taxonomy ),
					'hierarchical'    => (bool) $object->hierarchical,
					'orderby'         => 'name',
					'hide_empty'      => false,
					'hide_if_empty'   => true,
					'show_count'      => false,
				)
			);
		}

		$this->render_date_input(
			Query_Filters::FROM,
			__( 'Occurred on or after', 'healthpress' ),
			__( 'From', 'healthpress' )
		);

		$this->render_date_input(
			Query_Filters::TO,
			__( 'Occurred on or before', 'healthpress' ),
			__( 'To', 'healthpress' )
		);
	}

	/**
	 * Renders one bound of the date range.
	 *
	 * A visible label would crowd the filter bar, so the accessible name comes
	 * from a screen-reader label and the placeholder carries the short version.
	 *
	 * @param string $key         Request parameter name, used as id and name.
	 * @param string $label       Accessible label.
	 * @param string $placeholder Short visible hint.
	 */
	private function render_date_input( string $key, string $label, string $placeholder ): void {
		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="date" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s">',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $this->selected( $key ) ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Applies the filter parameters to the notes list query.
	 *
	 * Guarded on `is_main_query()` and on the queried post type. `edit.php`
	 * resolves `post_type` through an `in_array( …, get_post_types() )` check, so
	 * it is always a string here and never an array that would slip past `!==`.
	 *
	 * Nothing else on the screen is affected. The months dropdown and the
	 * post-status count links do not run a `WP_Query` at all — they issue their
	 * own SQL and `wp_count_posts()` respectively — so they are untouched by
	 * this, and their totals stay whole-archive counts rather than reflecting the
	 * active filter. That is how every core list table behaves.
	 *
	 * Existing clauses are merged rather than overwritten, so a filter composes
	 * with anything another plugin has already added instead of silently
	 * dropping it.
	 *
	 * @param WP_Query $query The query about to run.
	 */
	public function filter_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( Post_Type::SLUG !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filtering; every value is sanitised in Query_Filters.
		$request = $_GET;

		$tax_query = Query_Filters::tax_query( $request );

		if ( array() !== $tax_query ) {
			$existing = $query->get( 'tax_query' );

			$query->set( 'tax_query', array_merge( is_array( $existing ) ? $existing : array(), $tax_query ) );
		}

		$date_query = Query_Filters::date_query( $request );

		if ( array() !== $date_query ) {
			$existing = $query->get( 'date_query' );

			$query->set( 'date_query', array_merge( is_array( $existing ) ? $existing : array(), $date_query ) );
		}
	}

	/**
	 * Reads a filter parameter back so the control keeps its value.
	 *
	 * Only for redisplay. Everything that reaches a query goes through
	 * `Query_Filters`, which validates far more narrowly than this does.
	 *
	 * @param string $key Parameter name.
	 */
	private function selected( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filtering.
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filtering.
		return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
	}
}
