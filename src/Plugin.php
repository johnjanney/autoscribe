<?php
/**
 * Plugin container and runtime hook registration.
 *
 * @package AutoScribe
 */

namespace AutoScribe;

use AutoScribe\Cli\Command;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
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
	 * Action Scheduler queue wrapper.
	 *
	 * @since 0.4.0
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Handler for queued prompt runs.
	 *
	 * @since 0.4.0
	 * @var Queued_Run_Handler
	 */
	private Queued_Run_Handler $queued_runs;

	/**
	 * Builds the container.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->prompt_post_type = new Prompt_Post_Type();
		$this->providers        = new Provider_Registry();
		$this->scheduler        = new Scheduler();
		$this->queued_runs      = new Queued_Run_Handler(
			new Generator( $this->providers ),
			$this->scheduler,
			new Retry_Policy()
		);
	}

	/**
	 * Returns the queue wrapper.
	 *
	 * @since 0.4.0
	 *
	 * @return Scheduler
	 */
	public function scheduler(): Scheduler {
		return $this->scheduler;
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

		add_action( Scheduler::HOOK_RUN_PROMPT, array( $this->queued_runs, 'handle' ) );
		add_action( 'save_post_' . Prompt_Post_Type::POST_TYPE, array( $this, 'rearm_prompt' ) );
		add_action( 'trashed_post', array( $this, 'cancel_prompt' ) );
		add_action( 'untrashed_post', array( $this, 'rearm_prompt' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'autoscribe', new Command( $this->providers ) );
		}
	}

	/**
	 * Re-arms a prompt's queue entry after it is saved or restored.
	 *
	 * Section 4.3 requires the old occurrence to be cancelled first, so a
	 * changed schedule does not leave the previous one armed alongside the new.
	 *
	 * @since 0.4.0
	 *
	 * @param int $post_id Post being saved.
	 * @return void
	 */
	public function rearm_prompt( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$prompt = Prompt::load( $post_id );

		if ( null === $prompt ) {
			return;
		}

		if ( ! $prompt->enabled() ) {
			$this->scheduler->cancel( $post_id );

			return;
		}

		$schedule = $prompt->schedule();

		if ( is_wp_error( $schedule ) ) {
			return;
		}

		$timestamp = $this->scheduler->rearm( $post_id, $schedule );

		if ( ! is_wp_error( $timestamp ) ) {
			$prompt->set_next_run_ts( $timestamp );
		}
	}

	/**
	 * Cancels a prompt's queue entries when it is trashed.
	 *
	 * @since 0.4.0
	 *
	 * @param int $post_id Post being trashed.
	 * @return void
	 */
	public function cancel_prompt( int $post_id ): void {
		if ( Prompt_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$this->scheduler->cancel( $post_id );
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
