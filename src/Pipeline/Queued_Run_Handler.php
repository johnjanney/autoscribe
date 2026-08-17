<?php
/**
 * Handler for queued prompt runs.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Prompts\Prompt;
use AutoScribe\Scheduling\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a prompt from the queue and decides what happens next.
 *
 * Section 4.3 requires the next occurrence to be armed at the end of a run
 * whether it succeeded or failed, so that one bad night does not silently stop
 * a prompt forever. A retry is different from the next occurrence: it is an
 * extra attempt at the same run, and while retries are outstanding the regular
 * schedule is deliberately not armed, so the two cannot collide.
 *
 * @since 0.4.0
 */
final class Queued_Run_Handler {

	/**
	 * Meta key holding the consecutive failure count for a prompt.
	 *
	 * Not in the section 3.2 meta table. The runs table's attempt column counts
	 * attempts within one row, but a retry opens a new run, so the count has to
	 * live somewhere that survives across rows.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const ATTEMPT_META = '_autoscribe_attempt';

	/**
	 * Generation orchestrator.
	 *
	 * @since 0.4.0
	 * @var Generator
	 */
	private Generator $generator;

	/**
	 * Queue wrapper.
	 *
	 * @since 0.4.0
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Retry policy.
	 *
	 * @since 0.4.0
	 * @var Retry_Policy
	 */
	private Retry_Policy $policy;

	/**
	 * Builds the handler.
	 *
	 * @since 0.4.0
	 *
	 * @param Generator    $generator Generation orchestrator.
	 * @param Scheduler    $scheduler Queue wrapper.
	 * @param Retry_Policy $policy    Retry policy.
	 */
	public function __construct( Generator $generator, Scheduler $scheduler, Retry_Policy $policy ) {
		$this->generator = $generator;
		$this->scheduler = $scheduler;
		$this->policy    = $policy;
	}

	/**
	 * Runs one queued prompt.
	 *
	 * @since 0.4.0
	 *
	 * @param int $prompt_id Prompt to run.
	 * @return void
	 */
	public function handle( int $prompt_id ): void {
		$prompt = Prompt::load( $prompt_id );

		if ( null === $prompt || ! $prompt->enabled() ) {
			// Section 4.3: cancel everything for a prompt that is gone or off.
			$this->scheduler->cancel( $prompt_id );

			return;
		}

		$attempt = $this->attempt( $prompt_id );
		$result  = $this->generator->run( $prompt_id );

		if ( is_wp_error( $result ) && $this->policy->should_retry( $result, $attempt ) ) {
			update_post_meta( $prompt_id, self::ATTEMPT_META, $attempt + 1 );
			$this->scheduler->schedule_retry( $prompt_id, $this->policy->delay_seconds( $attempt ) );

			return;
		}

		delete_post_meta( $prompt_id, self::ATTEMPT_META );

		$this->rearm( $prompt );
	}

	/**
	 * Arms the next occurrence and caches it for display.
	 *
	 * @since 0.4.0
	 *
	 * @param Prompt $prompt Prompt to re-arm.
	 * @return void
	 */
	private function rearm( Prompt $prompt ): void {
		$schedule = $prompt->schedule();

		if ( is_wp_error( $schedule ) ) {
			return;
		}

		$timestamp = $this->scheduler->rearm( $prompt->id(), $schedule );

		if ( ! is_wp_error( $timestamp ) ) {
			$prompt->set_next_run_ts( $timestamp );
		}
	}

	/**
	 * Returns the current consecutive attempt number, starting at 1.
	 *
	 * @since 0.4.0
	 *
	 * @param int $prompt_id Prompt ID.
	 * @return int
	 */
	private function attempt( int $prompt_id ): int {
		$stored = (int) get_post_meta( $prompt_id, self::ATTEMPT_META, true );

		return $stored > 0 ? $stored : 1;
	}
}
