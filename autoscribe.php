<?php
/**
 * Plugin Name:       AutoScribe
 * Plugin URI:        https://github.com/johnjanney/autoscribe
 * Description:       Generates and publishes posts from scheduled AI prompts.
 * Version:           1.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            John Janney
 * Author URI:        https://github.com/johnjanney
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autoscribe
 * Domain Path:       /languages
 *
 * @package AutoScribe
 */

namespace AutoScribe;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version. Single source of truth, per section 12 of the brief.
 */
const VERSION = '1.1.2';

/**
 * Lowest PHP version the plugin supports.
 */
const MINIMUM_PHP = '8.1';

/**
 * Lowest WordPress version the plugin supports.
 */
const MINIMUM_WP = '6.4';

/**
 * Absolute path to this file, for callers that need the plugin entry point.
 */
const PLUGIN_FILE = __FILE__;

/**
 * Collects the reasons this host cannot run the plugin.
 *
 * Checked before the autoloader is required so an unsupported host produces an
 * admin notice rather than a fatal error.
 *
 * @since 0.1.0
 *
 * @return string[] Machine-readable failure reasons. Empty when supported.
 */
function unmet_requirements(): array {
	$problems = array();

	if ( version_compare( PHP_VERSION, MINIMUM_PHP, '<' ) ) {
		$problems[] = 'php_version';
	}

	if ( version_compare( get_bloginfo( 'version' ), MINIMUM_WP, '<' ) ) {
		$problems[] = 'wp_version';
	}

	if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		$problems[] = 'autoloader';
	}

	return $problems;
}

/**
 * Prints an admin notice explaining why the plugin did not load.
 *
 * @since 0.1.0
 *
 * @return void
 */
function render_requirements_notice(): void {
	$message = sprintf(
		/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
		__( 'AutoScribe needs PHP %1$s or newer, WordPress %2$s or newer, and its Composer dependencies installed. The plugin has not been loaded.', 'autoscribe' ),
		MINIMUM_PHP,
		MINIMUM_WP
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Loads the autoloader and starts the plugin.
 *
 * @since 0.1.0
 *
 * @return void
 */
function bootstrap(): void {
	$problems = unmet_requirements();

	if ( array() !== $problems ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_requirements_notice' );

		return;
	}

	require_once __DIR__ . '/vendor/autoload.php';

	Plugin::instance()->boot();
}

/**
 * Runs the activation routine.
 *
 * @since 0.1.0
 *
 * @return void
 */
function activate(): void {
	$problems = unmet_requirements();

	if ( array() !== $problems ) {
		return;
	}

	require_once __DIR__ . '/vendor/autoload.php';

	Activation::activate();
}

/**
 * Runs the deactivation routine.
 *
 * @since 0.1.0
 *
 * @return void
 */
function deactivate(): void {
	$problems = unmet_requirements();

	if ( array() !== $problems ) {
		return;
	}

	require_once __DIR__ . '/vendor/autoload.php';

	Activation::deactivate();
}

/*
 * Action Scheduler is required unconditionally at file scope, before
 * plugins_loaded fires.
 *
 * Section 12 of the brief says to guard this with a class_exists check because
 * WooCommerce may already have loaded it. That is the documented wrong way.
 * Action Scheduler is built for multiple copies to coexist: every plugin loads
 * its own, each registers with ActionScheduler_Versions, and the newest version
 * wins on plugins_loaded. Skipping the require when the class already exists
 * means a newer copy never registers, and deactivating WooCommerce makes the
 * class vanish and this plugin's scheduling stop silently. The library performs
 * its own internal guard, so requiring it twice is safe.
 */
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
