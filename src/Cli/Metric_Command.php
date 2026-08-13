<?php
/**
 * The metric CLI command.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Cli;

use HealthPress\Metrics\Metric_Registry;
use HealthPress\Support\Unit_Registry;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Reads the metric catalog.
 *
 * Read-only by design, and not because writing was left for later: metrics are
 * defined in code and registered through the `healthpress_metrics` filter. A
 * command that added one would create a term with no schema behind it — no
 * fields, no units, no bounds — which is the same reason the Metrics term screen
 * is unavailable.
 */
final class Metric_Command {

	/**
	 * Registers the command.
	 *
	 * @param Metric_Registry $metrics The metric catalog.
	 * @param Unit_Registry   $units   The unit catalog.
	 */
	public static function register( Metric_Registry $metrics, Unit_Registry $units ): void {
		WP_CLI::add_command( 'healthpress metric', new self( $metrics, $units ) );
	}

	/**
	 * Wires the catalogs this command reads.
	 *
	 * @param Metric_Registry $metrics The metric catalog.
	 * @param Unit_Registry   $units   The unit catalog.
	 */
	private function __construct(
		private readonly Metric_Registry $metrics,
		private readonly Unit_Registry $units,
	) {}

	/**
	 * Lists the metrics this site knows about.
	 *
	 * ## OPTIONS
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
	 *     # Every metric, with its fields and units.
	 *     $ wp healthpress metric list
	 *
	 *     # Just the slugs, for scripting.
	 *     $ wp healthpress metric list --format=ids
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( $this->metrics->all() as $metric ) {
			$fields = array();
			$units  = array();

			foreach ( $metric->fields as $field ) {
				$fields[] = $field->key;

				if ( null !== $field->unit ) {
					$unit    = $this->units->get( $field->unit );
					$units[] = null !== $unit ? $unit->slug : $field->unit;
				}
			}

			$rows[] = array(
				'slug'    => $metric->slug,
				'label'   => $metric->label,
				'fields'  => implode( ',', $fields ),
				'units'   => implode( ',', array_unique( $units ) ),
				'primary' => $metric->primary_field_key(),
			);
		}

		if ( 'ids' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'slug' ) ) );

			return;
		}

		Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$rows,
			array( 'slug', 'label', 'fields', 'units', 'primary' )
		);
	}
}
