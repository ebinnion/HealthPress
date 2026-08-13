<?php
/**
 * The reading CLI command.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Cli;

use DateTimeImmutable;
use Exception;
use HealthPress\Metrics\Metric_Registry;
use HealthPress\Storage\Reading_Query;
use HealthPress\Storage\Reading_Repository;
use HealthPress\Validation\Reading_Validator;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Reads and records measurements.
 *
 * Deliberately read and create only. Updating and deleting stay in wp-admin,
 * where the surrounding context — what the reading was, what it sits next to —
 * is on screen. Every extra write path is another place the validator can be
 * bypassed, and this one earns its place because scripting a measurement in is
 * something a browser is bad at.
 *
 * `add` goes through `Reading_Validator` and the repository, exactly as the admin
 * form does, so there is still one set of rules rather than one per entry point.
 */
final class Reading_Command {

	/**
	 * Registers the command.
	 *
	 * @param Metric_Registry    $metrics   The metric catalog.
	 * @param Reading_Repository $readings  The reading repository.
	 * @param Reading_Validator  $validator The validator.
	 */
	public static function register(
		Metric_Registry $metrics,
		Reading_Repository $readings,
		Reading_Validator $validator
	): void {
		WP_CLI::add_command( 'healthpress reading', new self( $metrics, $readings, $validator ) );
	}

	/**
	 * Wires the graph this command needs.
	 *
	 * @param Metric_Registry    $metrics   The metric catalog.
	 * @param Reading_Repository $readings  The reading repository.
	 * @param Reading_Validator  $validator The validator.
	 */
	private function __construct(
		private readonly Metric_Registry $metrics,
		private readonly Reading_Repository $readings,
		private readonly Reading_Validator $validator,
	) {}

	/**
	 * Lists recorded readings, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--metric=<slug>]
	 * : Only readings for this metric. Repeat as a comma-separated list.
	 *
	 * [--after=<date>]
	 * : Only readings recorded on or after this date. Any format strtotime understands.
	 *
	 * [--before=<date>]
	 * : Only readings recorded on or before this date.
	 *
	 * [--limit=<n>]
	 * : How many to return. Use 0 for all of them, which pages internally.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--offset=<n>]
	 * : How many to skip. Ignored when --limit=0.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--order=<order>]
	 * : Sort direction by recorded time.
	 * ---
	 * default: desc
	 * options:
	 *   - asc
	 *   - desc
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns to show. Defaults to id,metric,value,unit,recorded_at.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # The twenty most recent readings.
	 *     $ wp healthpress reading list
	 *
	 *     # Every weight reading ever recorded, as CSV.
	 *     $ wp healthpress reading list --metric=weight --limit=0 --format=csv
	 *
	 *     # A window, oldest first.
	 *     $ wp healthpress reading list --after=2026-01-01 --before=2026-06-30 --order=asc
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$metrics = array();
		$raw     = (string) Utils\get_flag_value( $assoc_args, 'metric', '' );

		if ( '' !== $raw ) {
			$metrics = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

			foreach ( $metrics as $slug ) {
				if ( ! $this->metrics->has( $slug ) ) {
					WP_CLI::error(
						sprintf(
							'Unknown metric "%s". Known metrics: %s',
							$slug,
							implode( ', ', $this->metrics->slugs() )
						)
					);
				}
			}
		}

		$limit  = (int) Utils\get_flag_value( $assoc_args, 'limit', 20 );
		$offset = (int) Utils\get_flag_value( $assoc_args, 'offset', 0 );
		$order  = (string) Utils\get_flag_value( $assoc_args, 'order', 'desc' );
		$format = (string) Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$after  = $this->date( $assoc_args, 'after' );
		$before = $this->date( $assoc_args, 'before' );

		$readings = 0 === $limit
			? $this->all( $metrics, $after, $before, $order )
			: $this->page( $metrics, $after, $before, $order, $limit, $offset );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $readings ) );

			return;
		}

		$rows = array_map( array( Rows::class, 'for_reading' ), $readings );

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );

			return;
		}

		$fields = Utils\get_flag_value( $assoc_args, 'fields' );

		Utils\format_items(
			$format,
			$rows,
			is_string( $fields ) ? array_map( 'trim', explode( ',', $fields ) ) : Rows::reading_fields()
		);
	}

	/**
	 * Shows one reading in full.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The reading's post ID.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp healthpress reading get 1592
	 *     $ wp healthpress reading get 1592 --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function get( array $args, array $assoc_args ): void {
		$reading = $this->readings->get( (int) $args[0] );

		if ( is_wp_error( $reading ) ) {
			WP_CLI::error( $reading->get_error_message() );
		}

		$row    = Rows::for_reading( $reading );
		$format = (string) Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'table' === $format ) {
			// One record reads better down the page than across it.
			$pairs = array();

			foreach ( $row as $key => $value ) {
				$pairs[] = array(
					'field' => $key,
					'value' => $value,
				);
			}

			Utils\format_items( 'table', $pairs, array( 'field', 'value' ) );

			return;
		}

		Utils\format_items( $format, array( $row ), array_keys( $row ) );
	}

	/**
	 * Records a reading.
	 *
	 * Values are given as one flag per field, named after the field, and are
	 * always in the field's canonical unit — `--value=72.5` for weight is
	 * kilograms. `wp healthpress metric list` shows the fields and units.
	 *
	 * ## OPTIONS
	 *
	 * --metric=<slug>
	 * : Which metric is being measured.
	 *
	 * [--<field>=<value>]
	 * : One flag per field of the metric, in its canonical unit.
	 *
	 * [--date=<date>]
	 * : When the measurement was taken. Any format strtotime understands. Defaults to now.
	 *
	 * [--note=<text>]
	 * : A free-text note.
	 *
	 * [--source=<source>]
	 * : How the reading arrived.
	 * ---
	 * default: cli
	 * options:
	 *   - cli
	 *   - manual
	 *   - import
	 * ---
	 *
	 * [--porcelain]
	 * : Output just the new reading's ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # A weight, in kilograms.
	 *     $ wp healthpress reading add --metric=weight --value=72.5
	 *
	 *     # A blood pressure, back-dated, with a note.
	 *     $ wp healthpress reading add --metric=blood_pressure --systolic=118 --diastolic=76 \
	 *         --date="2026-03-14 09:00" --note="after a walk"
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function add( array $args, array $assoc_args ): void {
		$slug   = (string) Utils\get_flag_value( $assoc_args, 'metric', '' );
		$metric = $this->metrics->get( $slug );

		if ( null === $metric ) {
			WP_CLI::error(
				sprintf(
					'Unknown metric "%s". Known metrics: %s',
					$slug,
					implode( ', ', $this->metrics->slugs() )
				)
			);
		}

		/*
		 * Only the metric's own field keys are read out of the flags. An
		 * unrecognised one is reported rather than ignored, matching how the
		 * validator treats an unknown key — a typo should fail loudly instead of
		 * silently recording nothing.
		 */
		$values = array();

		foreach ( $metric->field_keys() as $key ) {
			$flag = str_replace( '_', '-', $key );

			if ( isset( $assoc_args[ $key ] ) ) {
				$values[ $key ] = $assoc_args[ $key ];
			} elseif ( isset( $assoc_args[ $flag ] ) ) {
				$values[ $key ] = $assoc_args[ $flag ];
			}
		}

		$known = array_merge(
			array( 'metric', 'date', 'note', 'source', 'porcelain' ),
			$metric->field_keys(),
			array_map( static fn ( string $k ): string => str_replace( '_', '-', $k ), $metric->field_keys() )
		);

		foreach ( array_keys( $assoc_args ) as $flag ) {
			if ( ! in_array( $flag, $known, true ) ) {
				WP_CLI::error(
					sprintf(
						'Unrecognised field "--%s" for metric "%s". Its fields are: %s',
						$flag,
						$metric->slug,
						implode( ', ', $metric->field_keys() )
					)
				);
			}
		}

		$date = Utils\get_flag_value( $assoc_args, 'date' );

		$result = $this->validator->validate(
			array(
				'metric'      => $metric->slug,
				'values'      => $values,
				'recorded_at' => is_string( $date ) ? $this->to_iso( $date ) : null,
				'note'        => (string) Utils\get_flag_value( $assoc_args, 'note', '' ),
				'source'      => (string) Utils\get_flag_value( $assoc_args, 'source', 'cli' ),
			)
		);

		if ( ! $result->is_valid() ) {
			/*
			 * Every violation is printed, not just the first. The validator
			 * collects them for exactly this reason: one round trip should tell
			 * you everything that is wrong with a write.
			 */
			foreach ( $result->violations as $violation ) {
				WP_CLI::warning(
					null === $violation->field
						? $violation->message
						: sprintf( '%s: %s', $violation->field, $violation->message )
				);
			}

			WP_CLI::error( 'The reading was not recorded.' );
		}

		$reading = $this->readings->create( $result->reading );

		if ( is_wp_error( $reading ) ) {
			WP_CLI::error( $reading->get_error_message() );
		}

		if ( (bool) Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( (string) $reading->id );

			return;
		}

		WP_CLI::success(
			sprintf(
				'Recorded reading %d: %s %s %s at %s.',
				$reading->id,
				$reading->metric->slug,
				Rows::for_reading( $reading )['value'],
				Rows::for_reading( $reading )['unit'],
				$reading->recorded_at->format( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Fetches one page of readings.
	 *
	 * @param list<string>           $metrics Metric slugs to include.
	 * @param DateTimeImmutable|null $after   Inclusive start.
	 * @param DateTimeImmutable|null $before  Inclusive end.
	 * @param string                 $order   Sort direction.
	 * @param int                    $limit   Page size.
	 * @param int                    $offset  Rows to skip.
	 *
	 * @return list<\HealthPress\Storage\Reading>
	 */
	private function page( array $metrics, ?DateTimeImmutable $after, ?DateTimeImmutable $before, string $order, int $limit, int $offset ): array {
		try {
			$query = new Reading_Query( $metrics, $after, $before, $limit, $offset, $order );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		return $this->readings->query( $query )->items();
	}

	/**
	 * Fetches every matching reading, a page at a time.
	 *
	 * `Reading_Query` caps a single query at `MAX_LIMIT`, which is a deliberate
	 * guard against an unbounded query rather than a limit on what you may
	 * export. So `--limit=0` walks pages here instead of raising that ceiling.
	 *
	 * @param list<string>           $metrics Metric slugs to include.
	 * @param DateTimeImmutable|null $after   Inclusive start.
	 * @param DateTimeImmutable|null $before  Inclusive end.
	 * @param string                 $order   Sort direction.
	 *
	 * @return list<\HealthPress\Storage\Reading>
	 */
	private function all( array $metrics, ?DateTimeImmutable $after, ?DateTimeImmutable $before, string $order ): array {
		$found  = array();
		$offset = 0;

		do {
			$batch = $this->page( $metrics, $after, $before, $order, Reading_Query::MAX_LIMIT, $offset );
			$size  = count( $batch );

			foreach ( $batch as $reading ) {
				$found[] = $reading;
			}

			$offset += Reading_Query::MAX_LIMIT;

			/*
			 * A short page means the last one. Stopping on `< MAX_LIMIT` rather
			 * than on an empty page saves a final query in the common case, and a
			 * page that is exactly full costs one extra empty query — which is the
			 * right way round.
			 */
		} while ( Reading_Query::MAX_LIMIT === $size );

		return $found;
	}

	/**
	 * Reads a date flag, or null when absent.
	 *
	 * @param array<string, string> $assoc_args Flags.
	 * @param string                $key        Flag to read.
	 */
	private function date( array $assoc_args, string $key ): ?DateTimeImmutable {
		$raw = Utils\get_flag_value( $assoc_args, $key );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$timestamp = strtotime( $raw );

		if ( false === $timestamp ) {
			WP_CLI::error( sprintf( 'Could not read --%s="%s" as a date.', $key, $raw ) );
		}

		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
	}

	/**
	 * Normalises a date flag into something the validator accepts.
	 *
	 * The validator takes the same ISO-8601 strings the REST API used to hand it,
	 * so `strtotime` does the loose parsing here and the validator keeps doing the
	 * strict checking — including refusing a future date.
	 *
	 * @param string $raw The raw flag value.
	 */
	private function to_iso( string $raw ): string {
		$timestamp = strtotime( $raw );

		if ( false === $timestamp ) {
			WP_CLI::error( sprintf( 'Could not read --date="%s" as a date.', $raw ) );
		}

		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( 'c' );
	}
}
