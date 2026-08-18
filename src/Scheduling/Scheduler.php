<?php
/**
 * Action Scheduler queue wrapper.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Scheduling;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Arms, re-arms, and cancels a prompt's queued runs.
 *
 * Section 2.4 chooses Action Scheduler over WP-Cron, and section 4.3 sets the
 * arming rules: one single action per occurrence, re-armed at the end of a run
 * whether it succeeded or failed, re-armed on save after cancelling the old
 * one, cancelled when a prompt is trashed or disabled, and never backfilled.
 *
 * The no-backfill rule is enforced by the calculator, which only ever returns a
 * future instant, so a site that was offline for a week arms one occurrence
 * rather than seven.
 *
 * @since 0.4.0
 */
final class Scheduler {

	/**
	 * Hook fired for a queued prompt run.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const HOOK_RUN_PROMPT = 'autoscribe_run_prompt';

	/**
	 * Action Scheduler group, so the plugin's actions are filterable in admin.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const GROUP = 'autoscribe';

	/**
	 * Next-run calculator.
	 *
	 * @since 0.4.0
	 * @var Next_Run_Calculator
	 */
	private Next_Run_Calculator $calculator;

	/**
	 * Builds the scheduler.
	 *
	 * @since 0.4.0
	 *
	 * @param Next_Run_Calculator|null $calculator Calculator, or null to build a default.
	 */
	public function __construct( ?Next_Run_Calculator $calculator = null ) {
		$this->calculator = $calculator instanceof Next_Run_Calculator
			? $calculator
			: new Next_Run_Calculator();
	}

	/**
	 * Whether Action Scheduler is loaded.
	 *
	 * @since 0.4.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Arms the next occurrence, leaving any existing one alone.
	 *
	 * @since 0.4.0
	 *
	 * @param int      $prompt_id Prompt to arm.
	 * @param Schedule $schedule  Schedule to evaluate.
	 * @return int|WP_Error Unix timestamp the run is armed for, or an error.
	 */
	public function arm( int $prompt_id, Schedule $schedule ): int|WP_Error {
		if ( ! $this->is_available() ) {
			return $this->unavailable();
		}

		$existing = $this->next_scheduled( $prompt_id );

		if ( null !== $existing ) {
			return $existing;
		}

		$next = $this->calculator->next( $schedule );

		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$timestamp = $next->getTimestamp();

		as_schedule_single_action( $timestamp, self::HOOK_RUN_PROMPT, $this->args( $prompt_id ), self::GROUP );

		return $timestamp;
	}

	/**
	 * Cancels any queued run and arms a fresh one.
	 *
	 * Section 4.3 requires this on prompt save, cancelling first so a changed
	 * schedule does not leave the previous occurrence armed alongside the new.
	 *
	 * @since 0.4.0
	 *
	 * @param int      $prompt_id Prompt to re-arm.
	 * @param Schedule $schedule  Schedule to evaluate.
	 * @return int|WP_Error Unix timestamp the run is armed for, or an error.
	 */
	public function rearm( int $prompt_id, Schedule $schedule ): int|WP_Error {
		if ( ! $this->is_available() ) {
			return $this->unavailable();
		}

		$this->cancel( $prompt_id );

		return $this->arm( $prompt_id, $schedule );
	}

	/**
	 * Cancels every queued action for a prompt.
	 *
	 * @since 0.4.0
	 *
	 * @param int $prompt_id Prompt to cancel.
	 * @return void
	 */
	public function cancel( int $prompt_id ): void {
		if ( ! $this->is_available() ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK_RUN_PROMPT, $this->args( $prompt_id ), self::GROUP );
	}

	/**
	 * Returns the timestamp of the queued run, if any.
	 *
	 * @since 0.4.0
	 *
	 * @param int $prompt_id Prompt to inspect.
	 * @return int|null
	 */
	public function next_scheduled( int $prompt_id ): ?int {
		if ( ! $this->is_available() ) {
			return null;
		}

		$timestamp = as_next_scheduled_action( self::HOOK_RUN_PROMPT, $this->args( $prompt_id ), self::GROUP );

		if ( is_int( $timestamp ) ) {
			return $timestamp;
		}

		// A boolean true means an action is running now rather than pending.
		return true === $timestamp ? time() : null;
	}

	/**
	 * Queues a retry a fixed delay from now.
	 *
	 * Action Scheduler does not retry failed actions by itself, so a retry is
	 * simply a new single action. See Retry_Policy for the delay and the cap.
	 *
	 * @since 0.4.0
	 *
	 * @param int $prompt_id     Prompt to retry.
	 * @param int $delay_seconds Delay before the retry runs.
	 * @return int|WP_Error Unix timestamp the retry is armed for, or an error.
	 */
	public function schedule_retry( int $prompt_id, int $delay_seconds ): int|WP_Error {
		if ( ! $this->is_available() ) {
			return $this->unavailable();
		}

		$timestamp = time() + max( 1, $delay_seconds );

		as_schedule_single_action( $timestamp, self::HOOK_RUN_PROMPT, $this->args( $prompt_id ), self::GROUP );

		return $timestamp;
	}

	/**
	 * Returns when the queue last finished one of this plugin's actions.
	 *
	 * Section 9.4 asks the health panel to report when the queue last processed
	 * something. "Action Scheduler is loaded" answers a different and much weaker
	 * question: the library can be present and correctly configured while nothing
	 * has actually run for a week, which is exactly the state a health panel
	 * exists to reveal.
	 *
	 * @since 1.0.1
	 *
	 * @return int|null Unix timestamp, or null when nothing has completed or the
	 *                  queue cannot be queried.
	 */
	public function last_processed(): ?int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		$actions = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'complete',
				'per_page' => 1,
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);

		if ( ! is_array( $actions ) || array() === $actions ) {
			return null;
		}

		$action = reset( $actions );

		if ( ! is_object( $action ) || ! method_exists( $action, 'get_schedule' ) ) {
			return null;
		}

		$schedule = $action->get_schedule();

		if ( ! is_object( $schedule ) || ! method_exists( $schedule, 'get_date' ) ) {
			return null;
		}

		$date = $schedule->get_date();

		return $date instanceof \DateTimeInterface ? $date->getTimestamp() : null;
	}

	/**
	 * Builds the argument array identifying a prompt's actions.
	 *
	 * @since 0.4.0
	 *
	 * @param int $prompt_id Prompt ID.
	 * @return array<string, int>
	 */
	private function args( int $prompt_id ): array {
		return array( 'prompt_id' => $prompt_id );
	}

	/**
	 * Error returned when Action Scheduler is not loaded.
	 *
	 * @since 0.4.0
	 *
	 * @return WP_Error
	 */
	private function unavailable(): WP_Error {
		return new WP_Error(
			'autoscribe_scheduler_unavailable',
			__( 'Action Scheduler is not loaded, so runs cannot be queued.', 'autoscribe' )
		);
	}
}
