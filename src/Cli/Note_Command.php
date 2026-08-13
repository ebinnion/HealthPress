<?php
/**
 * The note CLI command.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress\Cli;

use HealthPress\Notes\Admin\Query_Filters;
use HealthPress\Notes\Body;
use HealthPress\Notes\Post_Type;
use HealthPress\Notes\Taxonomies;
use WP_CLI;
use WP_CLI\Utils;
use WP_Query;

/**
 * Reads and records notes.
 *
 * Notes are a searchable archive, and a shell is a good place to search one: the
 * body reaches the terminal, so it composes with `grep`, `jq` and a pipe in ways
 * the admin list cannot.
 *
 * `note add` takes the body from `--body`, from `--body-file`, or from standard
 * input — the file being the counterpart of the browser's import control, and the
 * one to reach for under Studio, whose `studio wp` wrapper does not forward
 * standard input at all. Whichever route it arrives by, the body goes through
 * `Notes\Body::sanitize()`, so the CLI and the metabox cannot drift apart on a
 * decision that has a visible cost.
 */
final class Note_Command {

	/**
	 * Registers the command.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'healthpress note', new self() );
	}

	/**
	 * Lists notes, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<slug>]
	 * : Only notes of this kind.
	 *
	 * [--provider=<slug>]
	 * : Only notes from this provider.
	 *
	 * [--tag=<slug>]
	 * : Only notes carrying this tag.
	 *
	 * [--search=<text>]
	 * : Only notes whose title or body contains this text.
	 *
	 * [--after=<date>]
	 * : Only notes dated on or after this date. YYYY-MM-DD.
	 *
	 * [--before=<date>]
	 * : Only notes dated on or before this date. YYYY-MM-DD.
	 *
	 * [--limit=<n>]
	 * : How many to return. Use 0 for all of them.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns. Defaults to id,title,kind,provider,tags,date.
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
	 *     # Recent notes.
	 *     $ wp healthpress note list
	 *
	 *     # Every transcript from one clinic, as JSON.
	 *     $ wp healthpress note list --kind=transcript --provider=dr-smith --limit=0 --format=json
	 *
	 *     # Search the bodies.
	 *     $ wp healthpress note list --search="blood pressure"
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$limit  = (int) Utils\get_flag_value( $assoc_args, 'limit', 20 );
		$format = (string) Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$query_args = array(
			'post_type'      => Post_Type::SLUG,
			'post_status'    => 'any',
			'posts_per_page' => 0 === $limit ? -1 : $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$search = Utils\get_flag_value( $assoc_args, 'search' );

		if ( is_string( $search ) && '' !== $search ) {
			$query_args['s'] = $search;
		}

		/*
		 * The taxonomy and date clauses are built by the same Query_Filters the
		 * admin list uses, so a `--kind=` on the command line and a Kind dropdown
		 * in the browser cannot resolve differently.
		 */
		$request = array();

		foreach ( array(
			'kind'     => Taxonomies::KIND,
			'provider' => Taxonomies::PROVIDER,
			'tag'      => Taxonomies::TAG,
		) as $flag => $taxonomy ) {
			$value = Utils\get_flag_value( $assoc_args, $flag );

			if ( is_string( $value ) && '' !== $value ) {
				$request[ $taxonomy ] = $value;
			}
		}

		foreach ( array(
			'after'  => Query_Filters::FROM,
			'before' => Query_Filters::TO,
		) as $flag => $key ) {
			$value = Utils\get_flag_value( $assoc_args, $flag );

			if ( is_string( $value ) && '' !== $value ) {
				$request[ $key ] = $value;
			}
		}

		$tax_query = Query_Filters::tax_query( $request );

		if ( array() !== $tax_query ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering by term is the point of the command, and this runs on the command line rather than in a page load.
			$query_args['tax_query'] = $tax_query;
		}

		$date_query = Query_Filters::date_query( $request );

		if ( array() !== $date_query ) {
			$query_args['date_query'] = $date_query;
		} elseif ( isset( $request[ Query_Filters::FROM ] ) || isset( $request[ Query_Filters::TO ] ) ) {
			// Query_Filters drops a malformed bound silently; say so rather than filtering on nothing.
			WP_CLI::error( 'Could not read --after/--before as a date. Use YYYY-MM-DD.' );
		}

		$notes = ( new WP_Query( $query_args ) )->posts;

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $notes ) );

			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $notes, 'ID' ) ) );

			return;
		}

		$rows   = array_map( static fn ( $note ): array => Rows::for_note( $note ), $notes );
		$fields = Utils\get_flag_value( $assoc_args, 'fields' );

		Utils\format_items(
			$format,
			$rows,
			is_string( $fields ) ? array_map( 'trim', explode( ',', $fields ) ) : Rows::note_fields()
		);
	}

	/**
	 * Shows one note, body and all.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The note's post ID.
	 *
	 * [--body-only]
	 * : Print just the body, so it can be piped.
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
	 *     $ wp healthpress note get 1597
	 *
	 *     # Pipe a transcript into something else.
	 *     $ wp healthpress note get 1597 --body-only | grep -i "blood pressure"
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function get( array $args, array $assoc_args ): void {
		$note = get_post( (int) $args[0] );

		if ( null === $note || Post_Type::SLUG !== $note->post_type ) {
			WP_CLI::error( sprintf( 'No note with ID %d.', (int) $args[0] ) );
		}

		if ( (bool) Utils\get_flag_value( $assoc_args, 'body-only', false ) ) {
			WP_CLI::line( (string) $note->post_content );

			return;
		}

		// 0 words means the whole body: `get` is the command that shows everything.
		$row    = Rows::for_note( $note, 0 );
		$format = (string) Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'table' === $format ) {
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
	 * Records a note.
	 *
	 * ## OPTIONS
	 *
	 * --title=<title>
	 * : What to call the note.
	 *
	 * [--body=<text>]
	 * : The note's body, inline.
	 *
	 * [--body-file=<path>]
	 * : Read the body from a file, the counterpart of the editor's import control.
	 *
	 * [--kind=<slug>]
	 * : The note's kind. Must be an existing term.
	 *
	 * [--provider=<name>]
	 * : Who the note came from. Created if it does not exist.
	 *
	 * [--tags=<list>]
	 * : Comma-separated tags. Created if they do not exist.
	 *
	 * [--date=<date>]
	 * : When the call or visit happened. Any format strtotime understands. Defaults to now.
	 *
	 * [--porcelain]
	 * : Output just the new note's ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # From a file, which is what a transcript usually wants.
	 *     $ wp healthpress note add --title="Cardiology call" --body-file=transcript.txt --kind=transcript
	 *
	 *     # Inline, for something short.
	 *     $ wp healthpress note add --title="Lab call" --body="Results were normal." --provider="Dr Smith"
	 *
	 *     # Piped, where the runner forwards standard input. Not under `studio wp`.
	 *     $ cat transcript.txt | wp healthpress note add --title="Cardiology call"
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function add( array $args, array $assoc_args ): void {
		$title = (string) Utils\get_flag_value( $assoc_args, 'title', '' );

		if ( '' === trim( $title ) ) {
			WP_CLI::error( 'A note needs a --title.' );
		}

		$body = $this->body( $assoc_args );

		/*
		 * Resolved before the insert, not after. A typo'd kind used to warn and
		 * save anyway, which left an unfiled note behind a message that — going to
		 * stderr — can even surface after the success line. Refusing up front
		 * matches `reading add`, which rejects an unrecognised field rather than
		 * recording a reading without it.
		 */
		$kind = $this->kind_term( $assoc_args );

		$date     = Utils\get_flag_value( $assoc_args, 'date' );
		$post_arr = array(
			'post_type'    => Post_Type::SLUG,
			'post_status'  => 'publish',
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => Body::sanitize( $body ),
		);

		if ( is_string( $date ) && '' !== trim( $date ) ) {
			$timestamp = strtotime( $date );

			if ( false === $timestamp ) {
				WP_CLI::error( sprintf( 'Could not read --date="%s" as a date.', $date ) );
			}

			$post_arr['post_date']     = wp_date( 'Y-m-d H:i:s', $timestamp );
			$post_arr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		$note_id = wp_insert_post( $post_arr, true );

		if ( is_wp_error( $note_id ) ) {
			WP_CLI::error( $note_id->get_error_message() );
		}

		if ( null !== $kind ) {
			wp_set_object_terms( (int) $note_id, array( $kind ), Taxonomies::KIND );
		}

		$this->assign_free_terms( (int) $note_id, $assoc_args );

		if ( (bool) Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( (string) $note_id );

			return;
		}

		WP_CLI::success( sprintf( 'Recorded note %d: %s', $note_id, $title ) );
	}

	/**
	 * Resolves the body from a flag, a file, or standard input.
	 *
	 * Three sources in order of explicitness. Standard input is last because it
	 * is the one that can misbehave: `studio wp` does not forward it, so reading
	 * it blind there fails with an I/O error rather than an explanation. The tty
	 * check turns that into a sentence telling you which flag to use, and also
	 * stops the command blocking forever on an interactive shell — a hang being
	 * the worst of the available failures.
	 *
	 * @param array<string, string> $assoc_args Flags.
	 */
	private function body( array $assoc_args ): string {
		$inline = Utils\get_flag_value( $assoc_args, 'body' );
		$path   = Utils\get_flag_value( $assoc_args, 'body-file' );

		if ( is_string( $inline ) && is_string( $path ) ) {
			WP_CLI::error( 'Give either --body or --body-file, not both.' );
		}

		if ( is_string( $inline ) ) {
			return $inline;
		}

		if ( is_string( $path ) ) {
			if ( ! is_readable( $path ) ) {
				WP_CLI::error( sprintf( 'Cannot read "%s".', $path ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local path the operator typed, not a URL; wp_remote_get() cannot read a file.
			$contents = file_get_contents( $path );

			if ( false === $contents ) {
				WP_CLI::error( sprintf( 'Cannot read "%s".', $path ) );
			}

			return $contents;
		}

		if ( stream_isatty( STDIN ) ) {
			WP_CLI::error( 'Give the body with --body or --body-file. Standard input is not available here.' );
		}

		return (string) file_get_contents( 'php://stdin' );
	}

	/**
	 * Resolves `--kind` to a term ID, or null when the flag is absent.
	 *
	 * Kinds are a controlled vocabulary — seeded on activation and managed on the
	 * term screen — so a `--kind` that does not exist is a typo rather than a new
	 * kind. Creating it on the fly would file the archive under two spellings of
	 * the same thing, which is exactly what a controlled vocabulary is for
	 * preventing. Providers and tags grow freely and are handled the other way.
	 *
	 * @param array<string, string> $assoc_args Flags.
	 */
	private function kind_term( array $assoc_args ): ?int {
		$kind = Utils\get_flag_value( $assoc_args, 'kind' );

		if ( ! is_string( $kind ) || '' === trim( $kind ) ) {
			return null;
		}

		$term = get_term_by( 'slug', sanitize_title( $kind ), Taxonomies::KIND );

		if ( false === $term ) {
			$known = get_terms(
				array(
					'taxonomy'   => Taxonomies::KIND,
					'hide_empty' => false,
					'fields'     => 'id=>slug',
				)
			);

			WP_CLI::error(
				sprintf(
					'Unknown kind "%s". Known kinds: %s',
					$kind,
					is_wp_error( $known ) ? '(none)' : implode( ', ', $known )
				)
			);
		}

		return (int) $term->term_id;
	}

	/**
	 * Assigns the provider and tags, creating terms as needed.
	 *
	 * Unlike kinds these are free-growing lists, so a name that does not exist yet
	 * is a new provider rather than a mistake. Names are passed through, not slugs,
	 * because `--provider="Dr Smith"` is what a person types; WordPress derives
	 * `dr-smith` itself.
	 *
	 * @param int                   $note_id    The new note.
	 * @param array<string, string> $assoc_args Flags.
	 */
	private function assign_free_terms( int $note_id, array $assoc_args ): void {
		$provider = Utils\get_flag_value( $assoc_args, 'provider' );

		if ( is_string( $provider ) && '' !== trim( $provider ) ) {
			wp_set_object_terms( $note_id, array( trim( $provider ) ), Taxonomies::PROVIDER );
		}

		$tags = Utils\get_flag_value( $assoc_args, 'tags' );

		if ( is_string( $tags ) && '' !== trim( $tags ) ) {
			$names = array_values( array_filter( array_map( 'trim', explode( ',', $tags ) ) ) );

			if ( array() !== $names ) {
				wp_set_object_terms( $note_id, $names, Taxonomies::TAG );
			}
		}
	}
}
