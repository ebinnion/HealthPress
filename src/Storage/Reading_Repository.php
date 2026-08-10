<?php
/**
 * Reading persistence contract.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Storage;

use HealthPress\Validation\Validated_Reading;
use WP_Error;

/**
 * Reads and writes readings.
 *
 * `create()` accepts only a `Validated_Reading`, which makes it structurally
 * impossible to persist data that has not been through the validator.
 */
interface Reading_Repository {

	/**
	 * Writes a validated reading over an existing post.
	 *
	 * The upsert every other write is expressed in terms of. `create()` inserts
	 * a bare row and calls this; `update()` merges, revalidates, and calls this;
	 * the admin screen validates its form and calls this against the post the
	 * editor is already holding. Reading data is therefore written in exactly
	 * one place, which is what keeps `Reading_Validator` unavoidable.
	 *
	 * @param int               $post_id An existing `hp_reading` post.
	 * @param Validated_Reading $reading A reading that has cleared validation.
	 * @param string|null       $status  Status to write, or null to keep the current one.
	 */
	public function save( int $post_id, Validated_Reading $reading, ?string $status = null ): Reading|WP_Error;

	/**
	 * Stores a new reading.
	 *
	 * @param Validated_Reading $reading A reading that has cleared validation.
	 */
	public function create( Validated_Reading $reading ): Reading|WP_Error;

	/**
	 * Fetches a reading by ID.
	 *
	 * @param int $id Post ID.
	 */
	public function get( int $id ): Reading|WP_Error;

	/**
	 * Applies a partial update.
	 *
	 * The patch is merged over the stored reading and the whole thing is
	 * revalidated, so a partial write can never leave a required field unset.
	 *
	 * @param int                  $id    Post ID.
	 * @param array<string, mixed> $patch Fields to change.
	 */
	public function update( int $id, array $patch ): Reading|WP_Error;

	/**
	 * Deletes a reading.
	 *
	 * @param int  $id    Post ID.
	 * @param bool $force Whether to bypass the trash.
	 */
	public function delete( int $id, bool $force = true ): bool|WP_Error;

	/**
	 * Fetches a page of readings.
	 *
	 * @param Reading_Query $query What to fetch.
	 */
	public function query( Reading_Query $query ): Reading_Collection;

	/**
	 * Fetches the most recent reading for a metric, or null if there is none.
	 *
	 * @param string $metric_slug Metric slug.
	 */
	public function latest( string $metric_slug ): ?Reading;
}
