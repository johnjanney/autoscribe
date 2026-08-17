<?php
/**
 * Plugin container and runtime hook registration.
 *
 * @package AutoScribe
 */

namespace AutoScribe;

use AutoScribe\Cli\Command;
use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Providers\Provider_Registry;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's collaborators and wires them to WordPress.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @since 0.1.0
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Registrar for the prompt post type.
	 *
	 * @since 0.1.0
	 * @var Prompt_Post_Type
	 */
	private Prompt_Post_Type $prompt_post_type;

	/**
	 * Registry resolving provider slugs to adapters.
	 *
	 * @since 0.2.0
	 * @var Provider_Registry
	 */
	private Provider_Registry $providers;

	/**
	 * Builds the container.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->prompt_post_type = new Prompt_Post_Type();
		$this->providers        = new Provider_Registry();
	}

	/**
	 * Returns the provider registry.
	 *
	 * @since 0.2.0
	 *
	 * @return Provider_Registry
	 */
	public function providers(): Provider_Registry {
		return $this->providers;
	}

	/**
	 * Returns the shared instance, creating it on first use.
	 *
	 * @since 0.1.0
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every hook the plugin needs at runtime.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( Activation::class, 'maybe_upgrade' ), 5 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this->prompt_post_type, 'register' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'autoscribe', new Command( $this->providers ) );
		}
	}

	/**
	 * Loads the plugin's translations.
	 *
	 * Deliberately hooked to init rather than called at load time. The plugin is
	 * not hosted on WordPress.org, so WordPress will not load its translations
	 * automatically, but WordPress 6.7 and newer emit a _doing_it_wrong notice
	 * when a text domain is loaded before init.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'autoscribe',
			false,
			dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages'
		);
	}
}
