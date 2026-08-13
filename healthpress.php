<?php
/**
 * Plugin Name:       HealthPress
 * Plugin URI:        https://github.com/ericbinnion/healthpress
 * Description:       Personal health metric tracking — a metric registry, readings, and a REST API.
 * Version:           0.4.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Eric Binnion
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       healthpress
 *
 * @package HealthPress
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/*
 * Bail politely rather than fatally on an unsupported runtime. The plugin uses
 * `final readonly class`, which is 8.2+.
 */
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'HealthPress requires PHP 8.2 or newer and has not been loaded.', 'healthpress' )
			);
		}
	);

	return;
}

define( 'HEALTHPRESS_VERSION', '0.4.0' );
define( 'HEALTHPRESS_FILE', __FILE__ );
define( 'HEALTHPRESS_DIR', plugin_dir_path( __FILE__ ) );

if ( ! is_readable( HEALTHPRESS_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'HealthPress is missing its Composer dependencies. Run `composer install` in the plugin directory.', 'healthpress' )
			);
		}
	);

	return;
}

require_once HEALTHPRESS_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( HealthPress\Activator::class, 'activate' ) );

HealthPress\Plugin::instance()->boot();
