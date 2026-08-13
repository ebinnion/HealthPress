<?php
/**
 * Turns an admin form submission into a stored reading.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Admin;

use DateTimeImmutable;
use DateTimeZone;
use HealthPress\Support\Permissions;
use HealthPress\Storage\Post_Type;
use HealthPress\Storage\Reading_Repository;
use HealthPress\Validation\Reading_Validator;
use HealthPress\Validation\Violation;
use WP_Error;
use WP_Post;

/**
 * Validates the Reading metabox and writes it through the repository.
 *
 * This is what keeps the admin screen honest: it does not touch post meta or
 * terms itself, it validates and then hands a `Validated_Reading` to
 * `save()` — the same method the CLI's `reading add` reaches through.
 */
final class Reading_Save_Handler {

	/**
	 * Nonce action, per post.
	 */
	public const NONCE_ACTION = 'hp_save_reading_';

	/**
	 * Nonce field name.
	 */
	public const NONCE_FIELD = 'hp_reading_nonce';

	/**
	 * Whether this handler is the reason the post is being written.
	 *
	 * `save()` writes the post again from inside `save_post`, which would
	 * re-enter this handler. A flag rather than remove_action()/add_action()
	 * because it is immune to callable identity and to any priority a third
	 * party may have reordered us to.
	 *
	 * @var bool
	 */
	private bool $writing = false;

	/**
	 * Wires the collaborators a save needs.
	 *
	 * @param Reading_Repository $readings  Persistence.
	 * @param Reading_Validator  $validator The single enforcement point.
	 * @param Submission_Store   $store     Flash storage for refusals.
	 */
	public function __construct(
		private readonly Reading_Repository $readings,
		private readonly Reading_Validator $validator,
		private readonly Submission_Store $store,
	) {}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'save_post_' . Post_Type::SLUG, array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * Validates and stores a submitted reading.
	 *
	 * @param int     $post_id The post being saved.
	 * @param WP_Post $post    That post.
	 */
	public function handle( int $post_id, WP_Post $post ): void {
		if ( $this->writing ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		/*
		 * The nonce is what says "the reading form was submitted", rather than
		 * merely "this post was written". Every other write — the CLI, and this
		 * handler's own save() — has to fall straight through.
		 *
		 * Deliberately not is_admin(): that is false under `wp eval-file`, which
		 * would make this path untestable from the integration suite.
		 */
		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! Permissions::can_manage() ) {
			return;
		}

		/*
		 * map_deep() satisfies the input-sanitisation sniff on a nested array
		 * without flattening it. Numbers survive untouched and the note keeps
		 * its newlines; type checking belongs to the validator, not here.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw = isset( $_POST['hp'] ) && is_array( $_POST['hp'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			? map_deep( wp_unslash( $_POST['hp'] ), 'sanitize_textarea_field' )
			: array();

		$input = Form_Input::from_request(
			$raw,
			is_string( $raw['metric'] ?? null ) ? $raw['metric'] : '',
			$this->recorded_at( $post )
		);

		$result = $this->validator->validate( $input );

		$this->writing = true;

		try {
			if ( ! $result->is_valid() ) {
				$this->reject( $post_id, $result->violations, $raw );

				return;
			}

			$saved = $this->readings->save( $post_id, $result->reading, $this->requested_status() );

			if ( is_wp_error( $saved ) ) {
				$this->reject( $post_id, self::violations_from( $saved ), $raw );
			}
		} finally {
			$this->writing = false;
		}
	}

	/**
	 * The measurement time, taken from the post rather than the form.
	 *
	 * The Publish box owns the timestamp, and core has already resolved it into
	 * `post_date_gmt` by the time this runs. Formatted with an explicit offset
	 * so the validator cannot misread it as site-local time and shift it.
	 *
	 * @param WP_Post $post The post being saved.
	 */
	private function recorded_at( WP_Post $post ): string {
		if ( '' === $post->post_date_gmt || '0000-00-00 00:00:00' === $post->post_date_gmt ) {
			return '';
		}

		return ( new DateTimeImmutable( $post->post_date_gmt, new DateTimeZone( 'UTC' ) ) )->format( DATE_ATOM );
	}

	/**
	 * The status the user asked for, read from the button they pressed.
	 *
	 * Not the post's current status: the titleless-publish guard fires on the
	 * outer write, before any title exists, and has already demoted it. The
	 * button is the only honest record of the intent.
	 */
	private function requested_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		return isset( $_POST['publish'] ) ? 'publish' : 'draft';
	}

	/**
	 * Records a refusal and quarantines the post if it is not already a reading.
	 *
	 * @param int                  $post_id    The post being saved.
	 * @param list<Violation>      $violations Why it was refused.
	 * @param array<string, mixed> $raw        What was submitted.
	 */
	private function reject( int $post_id, array $violations, array $raw ): void {
		/*
		 * Only quarantine a post that has never held a valid reading. Demoting
		 * one that already passes would hide good history because of a bad
		 * edit — nothing overwrote the stored values, so they are still the last
		 * set that passed.
		 *
		 * Testing with get() rather than the post status or the $update flag
		 * matters: those can be spoofed by a hand-crafted POST, the database
		 * cannot.
		 */
		$quarantine = is_wp_error( $this->readings->get( $post_id ) );

		// Recorded, because the two outcomes need different wording.
		$this->store->put( $post_id, new Rejected_Submission( $violations, $raw, $quarantine ) );

		if ( ! $quarantine ) {
			return;
		}

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * Flattens a WP_Error back into violations, so a storage failure is
	 * reported to the user the same way a validation failure is.
	 *
	 * @param WP_Error $error The failure.
	 *
	 * @return list<Violation>
	 */
	private static function violations_from( WP_Error $error ): array {
		$violations = array();

		foreach ( $error->get_error_codes() as $code ) {
			$violations[] = new Violation( (string) $code, (string) $error->get_error_message( $code ) );
		}

		return $violations;
	}
}
