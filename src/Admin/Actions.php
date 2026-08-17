<?php
/**
 * Admin form and link handlers.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Activation;
use AutoScribe\Content\Article_Validator;
use AutoScribe\Content\Topic_Deduplicator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Budget_Check;
use AutoScribe\Pipeline\Step_Generate_Body;
use AutoScribe\Pipeline\Step_Propose_Topic;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Handles every state-changing admin request.
 *
 * Each handler verifies a nonce and a capability before doing anything, per
 * section 8.2. Neither is inferred from the menu the user reached the control
 * through.
 *
 * @since 0.7.0
 */
final class Actions {

	/**
	 * Queues an immediate run of one prompt.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const ACTION_RUN_NOW = 'autoscribe_run_now';

	/**
	 * Generates an article without creating a post.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const ACTION_PREVIEW = 'autoscribe_preview';

	/**
	 * Re-runs a prompt after a failure.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const ACTION_RETRY = 'autoscribe_retry';

	/**
	 * Transient holding the most recent preview, keyed by user.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const PREVIEW_TRANSIENT = 'autoscribe_preview_';

	/**
	 * Provider registry.
	 *
	 * @since 0.7.0
	 * @var Provider_Registry
	 */
	private Provider_Registry $providers;

	/**
	 * Queue wrapper.
	 *
	 * @since 0.7.0
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Builds the handler set.
	 *
	 * @since 0.7.0
	 *
	 * @param Provider_Registry $providers Provider registry.
	 * @param Scheduler         $scheduler Queue wrapper.
	 */
	public function __construct( Provider_Registry $providers, Scheduler $scheduler ) {
		$this->providers = $providers;
		$this->scheduler = $scheduler;
	}

	/**
	 * Registers the handlers.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_RUN_NOW, array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_RETRY, array( $this, 'handle_retry' ) );
	}

	/**
	 * Builds a nonce-protected URL for one of the actions.
	 *
	 * @since 0.7.0
	 *
	 * @param string $action    Action constant.
	 * @param int    $prompt_id Prompt the action applies to.
	 * @return string
	 */
	public static function url( string $action, int $prompt_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'prompt' => $prompt_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $prompt_id
		);
	}

	/**
	 * Queues an immediate run.
	 *
	 * Section 9.2 asks this to stream the result, but section 2.4 puts a full
	 * run at thirty to a hundred and twenty seconds and is the reason the
	 * pipeline is queued at all. Holding an admin request open for that long is
	 * the exact failure the queue exists to avoid, so the run is enqueued and
	 * the run log reports the outcome.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function handle_run_now(): void {
		$prompt_id = $this->authorize( self::ACTION_RUN_NOW );

		$queued = $this->scheduler->schedule_retry( $prompt_id, 1 );

		if ( is_wp_error( $queued ) ) {
			$this->redirect_back( $prompt_id, 'error', $queued->get_error_message() );
		}

		$this->redirect_back(
			$prompt_id,
			'queued',
			__( 'Run queued. The run log will show the result once the queue processes it.', 'autoscribe' )
		);
	}

	/**
	 * Generates an article and stores it for display without creating a post.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function handle_preview(): void {
		$prompt_id = $this->authorize( self::ACTION_PREVIEW );
		$prompt    = Prompt::load( $prompt_id );

		if ( null === $prompt ) {
			$this->redirect_back( $prompt_id, 'error', __( 'That prompt no longer exists.', 'autoscribe' ) );
		}

		$run = Run::start( $prompt_id );

		$allowed = ( new Step_Budget_Check() )->run( $prompt, $run );

		if ( is_wp_error( $allowed ) ) {
			$this->redirect_back( $prompt_id, 'error', $allowed->get_error_message() );
		}

		$topic = ( new Step_Propose_Topic( $this->providers, new Topic_Deduplicator() ) )->run( $prompt, $run );

		if ( is_wp_error( $topic ) ) {
			$run->fail( $topic->get_error_message() );

			$this->redirect_back( $prompt_id, 'error', $topic->get_error_message() );
		}

		$article = ( new Step_Generate_Body( $this->providers, new Article_Validator() ) )->run( $prompt, $run, $topic );

		if ( is_wp_error( $article ) ) {
			$run->fail( $article->get_error_message() );

			$this->redirect_back( $prompt_id, 'error', $article->get_error_message() );
		}

		$run->succeed();

		set_transient(
			self::PREVIEW_TRANSIENT . get_current_user_id(),
			array(
				'title'   => $article->title(),
				'excerpt' => $article->excerpt(),
				'content' => $article->raw_content_html(),
			),
			HOUR_IN_SECONDS
		);

		$this->redirect_back( $prompt_id, 'preview', __( 'Preview generated. It is shown below and was charged against the budget.', 'autoscribe' ) );
	}

	/**
	 * Re-runs a prompt whose previous run failed.
	 *
	 * A retry opens a new run rather than reusing the failed row. Section 5
	 * makes each step idempotent keyed by run ID, so reusing the row would make
	 * the steps believe their work was already done and skip it.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function handle_retry(): void {
		$prompt_id = $this->authorize( self::ACTION_RETRY );

		$queued = $this->scheduler->schedule_retry( $prompt_id, 1 );

		if ( is_wp_error( $queued ) ) {
			$this->redirect_back( $prompt_id, 'error', $queued->get_error_message() );
		}

		$this->redirect_back( $prompt_id, 'queued', __( 'Retry queued as a new run.', 'autoscribe' ) );
	}

	/**
	 * Tests one provider's credentials.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug  Provider slug.
	 * @param string $model Model ID to probe with.
	 * @return string Human-readable result.
	 */
	public function test_connection( string $slug, string $model ): string {
		$provider = $this->providers->text_provider( $slug ) ?? $this->providers->image_provider( $slug );

		if ( null === $provider ) {
			return __( 'Unknown provider.', 'autoscribe' );
		}

		if ( '' === $model ) {
			return __( 'Set a default model for this provider first — the connection test asks the provider about a specific model.', 'autoscribe' );
		}

		$key = Key_Store::get( $slug );

		if ( is_wp_error( $key ) ) {
			return $key->get_error_message();
		}

		$result = $provider->test_connection( $key, $model );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return __( 'Connection succeeded.', 'autoscribe' );
	}

	/**
	 * Verifies the nonce and capability, and returns the prompt ID.
	 *
	 * Ends the request when either check fails.
	 *
	 * @since 0.7.0
	 *
	 * @param string $action Action constant.
	 * @return int
	 */
	private function authorize( string $action ): int {
		$prompt_id = isset( $_GET['prompt'] ) ? absint( wp_unslash( $_GET['prompt'] ) ) : 0;

		check_admin_referer( $action . '_' . $prompt_id );

		if ( ! current_user_can( Activation::MANAGE_CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to run AutoScribe prompts.', 'autoscribe' ),
				'',
				array( 'response' => 403 )
			);
		}

		return $prompt_id;
	}

	/**
	 * Returns to the prompt editor carrying a message, and ends the request.
	 *
	 * @since 0.7.0
	 *
	 * @param int    $prompt_id Prompt to return to.
	 * @param string $type      Message type.
	 * @param string $message   Message text.
	 * @return void
	 */
	private function redirect_back( int $prompt_id, string $type, string $message ): void {
		set_transient(
			'autoscribe_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( get_edit_post_link( $prompt_id, 'raw' ) );

		exit;
	}
}
