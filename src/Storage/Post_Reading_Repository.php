<?php
/**
 * Reading persistence, backed by posts.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Storage;

use DateTimeImmutable;
use DateTimeZone;
use HealthPress\Metrics\Metric_Registry;
use HealthPress\Metrics\Metric_Type;
use HealthPress\Support\Unit_Registry;
use HealthPress\Validation\Reading_Validator;
use HealthPress\Validation\Validated_Reading;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Stores readings as posts: the metric is a term, the timestamp is
 * `post_date_gmt`, and the values are post meta.
 *
 * Both hot filters therefore land on indexed columns rather than on meta.
 */
final class Post_Reading_Repository implements Reading_Repository {

	/**
	 * Wires the collaborators a reading needs to be written and read back.
	 *
	 * @param Metric_Registry   $registry  The metric catalog.
	 * @param Unit_Registry     $units     Resolves a unit slug to its written form.
	 * @param Reading_Validator $validator The single enforcement point.
	 * @param Registry_Sync     $sync      Resolves metric slugs to term IDs.
	 */
	public function __construct(
		private readonly Metric_Registry $registry,
		private readonly Unit_Registry $units,
		private readonly Reading_Validator $validator,
		private readonly Registry_Sync $sync,
	) {}

	// -----------------------------------------------------------------
	// Query construction — pure, so it can be asserted without a database.
	// -----------------------------------------------------------------

	/**
	 * Translates a query into `WP_Query` arguments.
	 *
	 * Static and side-effect free on purpose: the whole matrix of filters,
	 * windows, and cache flags is asserted in the unit suite without running
	 * anything.
	 *
	 * @param Reading_Query $query What to fetch.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_query_args( Reading_Query $query ): array {
		$args = array(
			'post_type'              => Post_Type::SLUG,
			'post_status'            => 'publish',
			'posts_per_page'         => $query->limit,
			'offset'                 => $query->offset,

			// post_date is covered by the type_status_date index.
			'orderby'                => 'date',
			'order'                  => $query->direction(),
			'ignore_sticky_posts'    => true,

			/*
			 * This SQLite driver emulates SQL_CALC_FOUND_ROWS with a second
			 * counting query, so a total is a genuine 2x cost. Opt in per call.
			 */
			'no_found_rows'          => ! $query->count_total,

			/*
			 * Primes update_meta_cache() once for every result ID. This is the
			 * entire N+1 defence: each get_post_meta() during hydration is then
			 * a cache hit rather than a query.
			 */
			'update_post_meta_cache' => true,

			// One query primes every result's metric term.
			'update_post_term_cache' => true,
			'cache_results'          => true,
		);

		if ( array() !== $query->metrics ) {
			/*
			 * The sniff flags tax_query as potentially slow, which is aimed at
			 * using taxonomies for arbitrary filtering. Here the metric *is* the
			 * taxonomy, and this join runs on the indexed term relationship
			 * tables — it is the fast path, not a workaround for one.
			 */
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = array(
				array(
					'taxonomy'         => Taxonomy::SLUG,
					'field'            => 'slug',
					'terms'            => $query->metrics,
					'include_children' => false,
					'operator'         => 'IN',
				),
			);
		}

		if ( null !== $query->after || null !== $query->before ) {
			/*
			 * Always the GMT column. It is identical to post_date on a site with
			 * no timezone set, which is precisely why filtering on the local
			 * column would be invisible until someone sets one.
			 */
			$clause = array(
				'column'    => 'post_date_gmt',
				'inclusive' => true,
			);

			if ( null !== $query->after ) {
				$clause['after'] = self::to_gmt_string( $query->after );
			}

			if ( null !== $query->before ) {
				$clause['before'] = self::to_gmt_string( $query->before );
			}

			$args['date_query'] = array( $clause );
		}

		return $args;
	}

	/**
	 * Formats an instant as the `Y-m-d H:i:s` UTC string WordPress stores.
	 *
	 * @param DateTimeImmutable $moment Any instant, in any timezone.
	 */
	private static function to_gmt_string( DateTimeImmutable $moment ): string {
		return $moment->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	// -----------------------------------------------------------------
	// Writing.
	// -----------------------------------------------------------------

	/**
	 * Writes a validated reading over an existing post.
	 *
	 * The single place reading data is written. `create()` inserts a bare row
	 * and calls this, `update()` merges and revalidates and calls this, and the
	 * admin screen validates its form and calls this against the post the editor
	 * is already holding. Keeping it to one method is what makes it impossible
	 * to persist a reading that has not been through `Reading_Validator`.
	 *
	 * @param int               $post_id An existing `hp_reading` post.
	 * @param Validated_Reading $reading A reading that has cleared validation.
	 * @param string|null       $status  Status to write, or null to keep the current one.
	 */
	public function save( int $post_id, Validated_Reading $reading, ?string $status = null ): Reading|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Post_Type::SLUG !== $post->post_type ) {
			return new WP_Error(
				'hp_reading_not_found',
				__( 'No reading with that ID.', 'healthpress' ),
				array( 'status' => 404 )
			);
		}

		$term_id = $this->sync->ensure_term( $reading->metric->slug );

		if ( is_wp_error( $term_id ) ) {
			return $term_id;
		}

		$gmt = $reading->recorded_at->format( 'Y-m-d H:i:s' );

		$updated = wp_update_post(
			array(
				'ID'            => $post_id,

				/*
				 * The trash is the one status a write must never silently undo,
				 * so it survives even an explicit request.
				 */
				'post_status'   => 'trash' === $post->post_status ? 'trash' : ( $status ?? $post->post_status ),
				'post_title'    => $this->title_for( $reading->metric, $reading->values, $reading->recorded_at ),
				'post_content'  => sanitize_textarea_field( $reading->note ),
				'post_date_gmt' => $gmt,
				'post_date'     => get_date_from_gmt( $gmt ),

				// Without this, wp_update_post() silently discards post_date.
				'edit_date'     => true,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		/*
		 * Explicitly, not via tax_input: that path is capability-gated on
		 * assign_terms, which this plugin denies outright so that the admin
		 * screen cannot write a metric around the validator.
		 */
		wp_set_object_terms( $post_id, array( (int) $term_id ), Taxonomy::SLUG, false );

		foreach ( $this->meta_for( $reading ) as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$this->sweep_stale_values( $post_id, $reading );

		return $this->get( $post_id );
	}

	/**
	 * Removes every stored value that does not belong to this reading.
	 *
	 * Covers two cases at once: an optional field cleared by a patch, and a
	 * reading whose metric has been changed. The latter is why the sweep walks
	 * the whole registry rather than just this metric's fields — the previous
	 * metric's rows would otherwise be stranded under keys nothing reads back,
	 * and would reappear if the metric were ever switched again.
	 *
	 * @param int               $post_id The reading being written.
	 * @param Validated_Reading $reading The reading's new content.
	 */
	private function sweep_stale_values( int $post_id, Validated_Reading $reading ): void {
		foreach ( $this->registry->all() as $known ) {
			$is_current = $known->slug === $reading->metric->slug;

			foreach ( $known->fields as $field ) {
				if ( $is_current && array_key_exists( $field->key, $reading->values ) ) {
					continue;
				}

				delete_post_meta( $post_id, Meta::key( $known, $field->key ) );
			}
		}
	}

	/**
	 * Stores a new reading.
	 *
	 * @param Validated_Reading $reading A reading that has cleared validation.
	 */
	public function create( Validated_Reading $reading ): Reading|WP_Error {
		/*
		 * A placeholder for save() to write over. Inserted as a draft and never
		 * observed: query() filters on `publish`, and save() runs synchronously
		 * in this same call.
		 */
		$post_id = wp_insert_post(
			array(
				'post_type'   => Post_Type::SLUG,
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$saved = $this->save( (int) $post_id, $reading, 'publish' );

		// Leaving the placeholder behind would be a row that is not a reading.
		if ( is_wp_error( $saved ) ) {
			wp_delete_post( (int) $post_id, true );
		}

		return $saved;
	}

	/**
	 * Applies a partial update and revalidates the whole reading.
	 *
	 * @param int                  $id    Post ID.
	 * @param array<string, mixed> $patch Fields to change.
	 */
	public function update( int $id, array $patch ): Reading|WP_Error {
		$existing = $this->get( $id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		/*
		 * Merge, then run the full validator. There is no partial validation
		 * mode, so a patch can never leave a required field unset and there is
		 * only ever one code path through the rules.
		 */
		$input = array(
			'metric'      => $existing->metric->slug,
			'recorded_at' => $existing->recorded_at->format( DATE_ATOM ),
			'values'      => $existing->values,
			'note'        => $existing->note,
			'source'      => $existing->source,
		);

		$metric_changed = isset( $patch['metric'] ) && $patch['metric'] !== $existing->metric->slug;

		if ( array_key_exists( 'values', $patch ) && is_array( $patch['values'] ) ) {
			/*
			 * Merging only makes sense within one metric. A patch that switches
			 * metric supplies the whole value set, because carrying the old
			 * metric's keys across would fail as `hp_unknown_field` against the
			 * new schema.
			 */
			$input['values'] = $metric_changed
				? $patch['values']
				: array_merge( $existing->values, $patch['values'] );

			unset( $patch['values'] );
		} elseif ( $metric_changed ) {
			// Nothing to carry over, and the old values do not fit the new metric.
			$input['values'] = array();
		}

		$input = array_merge( $input, $patch );

		$result = $this->validator->validate( $input );

		if ( ! $result->is_valid() ) {
			return self::to_wp_error( $result->violations );
		}

		/*
		 * Null preserves the status. A patch is a correction, not a decision
		 * about whether the reading is published — the admin screen's Save Draft
		 * button and the trash both need that left alone.
		 */
		return $this->save( $id, $result->reading, null );
	}

	/**
	 * Deletes a reading.
	 *
	 * @param int  $id    Post ID.
	 * @param bool $force Whether to bypass the trash.
	 */
	public function delete( int $id, bool $force = true ): bool|WP_Error {
		$existing = $this->get( $id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		return false !== wp_delete_post( $id, $force );
	}

	// -----------------------------------------------------------------
	// Reading.
	// -----------------------------------------------------------------

	/**
	 * Fetches a reading by ID.
	 *
	 * @param int $id Post ID.
	 */
	public function get( int $id ): Reading|WP_Error {
		$post = get_post( $id );

		if ( ! $post instanceof WP_Post || Post_Type::SLUG !== $post->post_type ) {
			return new WP_Error(
				'hp_reading_not_found',
				__( 'No reading with that ID.', 'healthpress' ),
				array( 'status' => 404 )
			);
		}

		$reading = $this->hydrate( $post );

		if ( null === $reading ) {
			return $this->rejection_for( $post );
		}

		return $reading;
	}

	/**
	 * Explains why a post that exists is nonetheless not a reading.
	 *
	 * Both cases are 409 rather than 404: the row is real and the request is
	 * well-formed, but the resource is in a state that has no representation.
	 * They carry different codes because they need different fixes — one wants
	 * the metric registering again, the other wants values entering.
	 *
	 * @param WP_Post $post A post of the reading type that failed to hydrate.
	 */
	private function rejection_for( WP_Post $post ): WP_Error {
		if ( null === $this->metric_for( $post ) ) {
			return new WP_Error(
				'hp_orphaned_reading',
				__( 'This reading belongs to a metric that is no longer registered.', 'healthpress' ),
				array( 'status' => 409 )
			);
		}

		return new WP_Error(
			'hp_incomplete_reading',
			__( 'This reading has no recorded values.', 'healthpress' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Fetches a page of readings.
	 *
	 * @param Reading_Query $query What to fetch.
	 */
	public function query( Reading_Query $query ): Reading_Collection {
		$wp_query = new WP_Query( self::build_query_args( $query ) );

		$readings = array();

		foreach ( $wp_query->posts as $post ) {
			$reading = $this->hydrate( $post );

			if ( null !== $reading ) {
				$readings[] = $reading;
			}
		}

		return new Reading_Collection(
			$readings,
			$query->count_total ? (int) $wp_query->found_posts : null
		);
	}

	/**
	 * Fetches the most recent reading for a metric.
	 *
	 * @param string $metric_slug Metric slug.
	 */
	public function latest( string $metric_slug ): ?Reading {
		$collection = $this->query(
			new Reading_Query(
				metrics: array( $metric_slug ),
				limit: 1,
				order: 'DESC',
			)
		);

		return $collection->items()[0] ?? null;
	}

	// -----------------------------------------------------------------
	// Mapping.
	// -----------------------------------------------------------------

	/**
	 * Builds a Reading from a post, or null when its metric is unregistered.
	 *
	 * Reads only from caches primed by the query, so hydrating a page of
	 * results issues no further queries.
	 *
	 * @param WP_Post $post A reading post.
	 */
	private function hydrate( WP_Post $post ): ?Reading {
		$metric = $this->metric_for( $post );

		if ( null === $metric ) {
			return null;
		}

		$values = array();

		foreach ( $metric->fields as $field ) {
			$raw = get_post_meta( $post->ID, Meta::key( $metric, $field->key ), true );

			if ( '' === $raw || null === $raw ) {
				continue;
			}

			$coerced = $field->type->coerce( $raw );

			if ( null !== $coerced ) {
				$values[ $field->key ] = $coerced;
			}
		}

		/*
		 * A reading with a registered metric and not one recorded value is not a
		 * measurement of anything. Returning null skips it in query() and turns
		 * get() into an error, so no such row can reach the API however it came
		 * to exist — including rows already in a database from before the admin
		 * write hole was closed.
		 *
		 * Deliberately "no values at all", not "every required field present".
		 * Required-ness is a property of the *current* registry; tightening it
		 * later would retroactively hide history that was complete under the
		 * rules in force when it was recorded. Emptiness is not a judgement call.
		 */
		if ( array() === $values ) {
			return null;
		}

		$source = get_post_meta( $post->ID, Meta::SOURCE, true );

		return new Reading(
			(int) $post->ID,
			$metric,
			new DateTimeImmutable( $post->post_date_gmt, new DateTimeZone( 'UTC' ) ),
			$values,
			(string) $post->post_content,
			is_string( $source ) && '' !== $source ? $source : 'manual'
		);
	}

	/**
	 * Resolves which metric a reading post measures.
	 *
	 * @param WP_Post $post A reading post.
	 */
	private function metric_for( WP_Post $post ): ?Metric_Type {
		$terms = get_the_terms( $post, Taxonomy::SLUG );

		if ( ! is_array( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			$metric = $this->registry->get( $term->slug );

			if ( null !== $metric ) {
				return $metric;
			}
		}

		return null;
	}

	/**
	 * Builds the meta map for a reading.
	 *
	 * @param Validated_Reading $reading The reading being written.
	 *
	 * @return array<string, string|int>
	 */
	private function meta_for( Validated_Reading $reading ): array {
		$meta = array( Meta::SOURCE => $reading->source );

		foreach ( $reading->values as $key => $value ) {
			$field = $reading->metric->field( $key );

			if ( null === $field ) {
				continue;
			}

			// Fixed decimal places keep string and numeric comparisons agreeing.
			$meta[ Meta::key( $reading->metric, $key ) ] = $field->format( $value );
		}

		return $meta;
	}

	/**
	 * Builds the generated post title.
	 *
	 * Exists so the default admin list screen is legible while there is no
	 * custom UI. It is never parsed back — the meta is the record.
	 *
	 * @param Metric_Type              $metric      The metric measured.
	 * @param array<string, int|float> $values      Canonical values.
	 * @param DateTimeImmutable        $recorded_at When it was measured.
	 */
	private function title_for( Metric_Type $metric, array $values, DateTimeImmutable $recorded_at ): string {
		$parts = array();

		foreach ( $metric->fields as $field ) {
			if ( ! array_key_exists( $field->key, $values ) ) {
				continue;
			}

			$parts[] = $field->format( $values[ $field->key ] );
		}

		/*
		 * The unit's written form, not its slug: a title reading
		 * "118/76 mmhg" or "5.4 mg_dl" is the machine name leaking into
		 * something a person reads.
		 */
		$primary_unit = $metric->field( $metric->primary_field_key() )?->unit;
		$unit_label   = null !== $primary_unit && $this->units->has( $primary_unit )
			? $this->units->get( $primary_unit )->label
			: (string) $primary_unit;

		return sprintf(
			'%s — %s%s — %s',
			$metric->label,
			implode( '/', $parts ),
			'' !== $unit_label ? ' ' . $unit_label : '',
			get_date_from_gmt( $recorded_at->format( 'Y-m-d H:i:s' ), 'Y-m-d H:i' )
		);
	}

	/**
	 * Converts validation violations into a REST-ready error.
	 *
	 * This is the boundary the framework-free validator is deliberately kept
	 * behind: `Violation` structs become `WP_Error` here and nowhere else.
	 *
	 * @param list<\HealthPress\Validation\Violation> $violations Collected violations.
	 */
	public static function to_wp_error( array $violations ): WP_Error {
		$error = new WP_Error();

		foreach ( $violations as $violation ) {
			$error->add(
				$violation->code,
				$violation->message,
				array(
					'status' => 400,
					'field'  => $violation->field,
					'data'   => $violation->data,
				)
			);
		}

		return $error;
	}
}
