<?php
/**
 * Composition root.
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

namespace HealthPress;

use HealthPress\Admin\Reading_Form;
use HealthPress\Admin\Reading_List_Table;
use HealthPress\Admin\Reading_Save_Handler;
use HealthPress\Admin\Reading_Screen;
use HealthPress\Admin\Submission_Store;
use HealthPress\Metrics\Metric_Registry;
use HealthPress\Notes\Post_Type as Note_Post_Type;
use HealthPress\Notes\Taxonomies as Note_Taxonomies;
use HealthPress\Rest\Metrics_Controller;
use HealthPress\Rest\Readings_Controller;
use HealthPress\Rest\Unit_Negotiator;
use HealthPress\Storage\Post_Reading_Repository;
use HealthPress\Storage\Post_Type;
use HealthPress\Storage\Publish_Guard;
use HealthPress\Storage\Reading_Repository;
use HealthPress\Storage\Registry_Sync;
use HealthPress\Storage\Taxonomy;
use HealthPress\Support\System_Clock;
use HealthPress\Support\Unit_Registry;
use HealthPress\Validation\Reading_Validator;

/**
 * Builds the object graph and wires it into WordPress.
 *
 * Hook ordering is load-bearing — the metric registry has to exist before
 * anything derived from it is registered:
 *
 *     init:5   build the registries (this is when `healthpress_metrics` fires)
 *     init:10  register the post types and taxonomies
 *     init:12  register the admin save handler and, in wp-admin, the screen
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * The unit catalog.
	 *
	 * @var Unit_Registry|null
	 */
	private ?Unit_Registry $units = null;

	/**
	 * The metric catalog.
	 *
	 * @var Metric_Registry|null
	 */
	private ?Metric_Registry $metrics = null;

	/**
	 * Taxonomy sync.
	 *
	 * @var Registry_Sync|null
	 */
	private ?Registry_Sync $sync = null;

	/**
	 * The reading repository.
	 *
	 * @var Reading_Repository|null
	 */
	private ?Reading_Repository $readings = null;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers this plugin's hooks. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'build_registries' ), 5 );
		add_action( 'init', array( $this, 'register_object_types' ), 10 );
		add_action( 'admin_init', array( $this, 'maybe_sync' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Registered unconditionally: it guards every write path, not just admin.
		( new Publish_Guard() )->register();

		/*
		 * On init rather than here, because building the handler builds the
		 * metric registry, whose labels are translated — and boot() runs at
		 * plugin load, well before translations may be requested.
		 */
		add_action( 'init', array( $this, 'register_save_handler' ), 12 );
	}

	/**
	 * Builds the unit and metric catalogs.
	 */
	public function build_registries(): void {
		$this->units   = Unit_Registry::create_default();
		$this->metrics = Metric_Registry::create();

		/**
		 * Fires once the metric registry is final.
		 *
		 * A safe read-only point for extenders; `healthpress_metrics` has
		 * already run by now, so this is too late to add a metric.
		 *
		 * @since 0.1.0
		 *
		 * @param Metric_Registry $metrics The metric catalog.
		 */
		do_action( 'healthpress_registry_ready', $this->metrics );
	}

	/**
	 * Registers the reading and note post types and their taxonomies.
	 *
	 * Within each pair the taxonomy goes first, and that order is load-bearing:
	 * a post type's `taxonomies` argument runs through
	 * `register_taxonomy_for_object_type()`, which returns `false` without a
	 * word if the taxonomy is not registered yet. Registering the post type
	 * first would silently leave it with no taxonomies attached.
	 */
	public function register_object_types(): void {
		( new Taxonomy() )->register();
		( new Post_Type() )->register();

		/*
		 * Notes read nothing from the metric registry, so the note pair needs no
		 * ordering relative to the registries or to readings. The two lines
		 * below are ordered relative to each other for the reason above.
		 */
		( new Note_Taxonomies() )->register();
		( new Note_Post_Type() )->register();
	}

	/**
	 * Registers the admin form's save handler.
	 *
	 * Deliberately not gated on `is_admin()`: the handler discriminates on its
	 * own nonce, and `is_admin()` is false under WP-CLI, which would make the
	 * admin save path untestable from the integration suite.
	 */
	public function register_save_handler(): void {
		$store = new Submission_Store();

		( new Reading_Save_Handler(
			$this->readings(),
			$this->validator(),
			$store
		) )->register();

		if ( ! is_admin() ) {
			return;
		}

		// Purely presentational, so unlike the handler these are admin-only.
		$form = new Reading_Form( $this->metrics(), $this->units(), $this->readings() );

		( new Reading_Screen( $form, $store ) )->register();
		( new Reading_List_Table() )->register();
	}

	/**
	 * Syncs the taxonomy when the registry's structure has changed.
	 */
	public function maybe_sync(): void {
		$this->sync()->maybe_sync();
	}

	/**
	 * Registers the REST controllers.
	 */
	public function register_rest_routes(): void {
		$negotiator = new Unit_Negotiator( $this->units() );

		( new Metrics_Controller( $this->metrics(), $this->units() ) )->register_routes();
		( new Readings_Controller( $this->metrics(), $this->readings(), $this->validator(), $negotiator ) )->register_routes();
	}

	// -----------------------------------------------------------------
	// Accessors. Each builds on first use so that CLI and test callers can
	// reach the graph without a full request lifecycle.
	// -----------------------------------------------------------------

	/**
	 * Returns the unit catalog.
	 */
	public function units(): Unit_Registry {
		if ( null === $this->units ) {
			$this->units = Unit_Registry::create_default();
		}

		return $this->units;
	}

	/**
	 * Returns the metric catalog.
	 */
	public function metrics(): Metric_Registry {
		if ( null === $this->metrics ) {
			$this->metrics = Metric_Registry::create();
		}

		return $this->metrics;
	}

	/**
	 * Returns the taxonomy sync.
	 */
	public function sync(): Registry_Sync {
		if ( null === $this->sync ) {
			$this->sync = new Registry_Sync( $this->metrics(), HEALTHPRESS_VERSION );
		}

		return $this->sync;
	}

	/**
	 * Returns a validator configured for this site.
	 */
	public function validator(): Reading_Validator {
		/**
		 * Filters how far into the future a reading may be dated.
		 *
		 * Exists to absorb clock skew between a browser and the server, not to
		 * permit recording measurements that have not happened.
		 *
		 * @since 0.1.0
		 *
		 * @param int $seconds Grace period in seconds.
		 */
		$grace = (int) apply_filters( 'healthpress_max_future_seconds', 300 );

		return new Reading_Validator(
			$this->metrics()->all(),
			new System_Clock(),
			$grace,
			wp_timezone()
		);
	}

	/**
	 * Returns the reading repository.
	 */
	public function readings(): Reading_Repository {
		if ( null === $this->readings ) {
			$this->readings = new Post_Reading_Repository(
				$this->metrics(),
				$this->units(),
				$this->validator(),
				$this->sync()
			);
		}

		return $this->readings;
	}
}
