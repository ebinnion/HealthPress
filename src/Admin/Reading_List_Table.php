<?php
/**
 * The readings list screen.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Admin;

use HealthPress\Storage\Post_Type;
use WP_Post;

/**
 * Adjusts the stock list table for readings.
 *
 * Deliberately minimal. No value columns are added: the generated title already
 * reads "Blood Pressure — 118/76 mmHg — 2026-08-08 07:14", so a Systolic column
 * would duplicate a substring of the cell beside it, and to mean anything it
 * would have to be per-metric — nine columns, eight empty on any given row.
 * The taxonomy column already carries the metric with a working filter link,
 * and the Date column already sorts on `post_date`, which *is* the measured
 * time.
 */
final class Reading_List_Table {

	/**
	 * Registers the screen's hooks.
	 */
	public function register(): void {
		add_filter( 'manage_' . Post_Type::SLUG . '_posts_columns', array( $this, 'columns' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'the_title', array( $this, 'title' ), 10, 2 );
	}

	/**
	 * Names a reading that has no title.
	 *
	 * Titles are generated on a successful save, so an empty one means the save
	 * was refused. Core's "(no title)" is accurate but says nothing useful;
	 * this at least tells the reader what they are looking at and that opening
	 * it will explain itself.
	 *
	 * Reads the post type from cache, which the list table has already primed,
	 * so this costs no queries.
	 *
	 * @param string $title   The post title.
	 * @param int    $post_id The post it belongs to.
	 */
	public function title( string $title, int $post_id = 0 ): string {
		if ( '' !== trim( $title ) || 0 === $post_id ) {
			return $title;
		}

		if ( Post_Type::SLUG !== get_post_type( $post_id ) ) {
			return $title;
		}

		return __( 'Incomplete reading', 'healthpress' );
	}

	/**
	 * Relabels the columns to describe a measurement rather than a post.
	 *
	 * @param array<string, string> $columns Column key => heading.
	 *
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		if ( isset( $columns['title'] ) ) {
			$columns['title'] = __( 'Reading', 'healthpress' );
		}

		if ( isset( $columns['date'] ) ) {
			$columns['date'] = __( 'Measured', 'healthpress' );
		}

		return $columns;
	}

	/**
	 * Removes Quick Edit from readings.
	 *
	 * It cannot produce a valid reading — it renders none of the value fields —
	 * and because it submits no reading nonce the save handler ignores it
	 * entirely. Leaving it would be a control that silently does nothing.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    The row's post.
	 *
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, WP_Post $post ): array {
		if ( Post_Type::SLUG !== $post->post_type ) {
			return $actions;
		}

		unset( $actions['inline hide-if-no-js'] );

		return $actions;
	}
}
