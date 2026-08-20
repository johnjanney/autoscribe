<?php
/**
 * Action Scheduler queue wrapper.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Scheduling;

use AutoScribe\Concurrency\Named_Lock;
use AutoScribe\Prompts\Prompt;
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
	 * Hook advancing an open run by one step.
	 *
	 * Section 5 requires each step to be its own queued request. One hook rather
	 * than one per step, with the position read from the run row: it keeps
	 * cancel() able to clear a prompt's whole chain in a single call, and it
	 * means the queue never holds routing that the run row could contradict.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const HOOK_RUN_STEP = 'autoscribe_run_step';

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

		/*
		 * Asking whether a prompt is queued and queueing it are two statements,
		 * and three different callers make that pair: saving a prompt, concluding
		 * a run, and the schedule sweep. Two of them running at once both read
		 * "nothing queued" and both queue — and a single action is not unique by
		 * default, so both rows survive, both dispatch, and one occurrence buys two
		 * articles. The claims that stop two workers spending on a run are scoped
		 * to a run row, so they cannot see two run rows for one occurrence either.
		 *
		 * The lock is per prompt, so arming one prompt never waits on another. A
		 * database that will not give it degrades to the check alone, which is the
		 * behaviour this had before and is weaker rather than equivalent — the
		 * duplicate guard when a run opens is what catches the rest.
		 */
		$lock = new Named_Lock( 'prompt_' . $prompt_id );

		$lock->acquire();

		try {
			return $this->arm_held( $prompt_id, $schedule );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Arms the next occurrence, with this prompt's lock already held.
	 *
	 * @since 1.13.4
	 *
	 * @param int      $prompt_id Prompt to arm.
	 * @param Schedule $schedule  Schedule to evaluate.
	 * @return int|WP_Error Unix timestamp the run is armed for, or an error.
	 */
	private function arm_held( int $prompt_id, Schedule $schedule ): int|WP_Error {
		$existing = $this->next_scheduled( $prompt_id );

		if ( null !== $existing ) {
			return $existing;
		}

		$next = $this->calculator->next( $schedule );

		if ( is_wp_error( $next ) ) {
			return $next;
		}

		$timestamp = $next->getTimestamp();

		$armed = as_schedule_single_action( $timestamp, self::HOOK_RUN_PROMPT, $this->args( $prompt_id ), self::GROUP );

		return $this->armed( $armed ) ? $timestamp : $this->not_armed();
	}

	/**
	 * Cancels any queued run and arms a fresh one.
	 *
	 * Section 4.3 requires this on prompt save, cancelling first so a changed
	 * schedule does not leave the previous occurrence armed alongside the new.
	 *
	 * Cancel and insert are one critical section, which they were not until
	 * 1.13.4: the cancel sat outside the lock arm() takes, so a prompt save and a
	 * finishing run could interleave as cancel, cancel, insert, insert — and the
	 * second insert found an occurrence already queued and left it there. The one
	 * that survived was whichever worker got the lock, not whichever schedule the
	 * prompt actually holds.
	 *
	 * The schedule is re-read inside the lock for the same reason. A caller
	 * resolves it before it calls, and a run that finishes seconds after a save
	 * carries a snapshot of the schedule that save replaced. What is persisted
	 * now is the only version worth arming; the caller's copy is the fallback for
	 * a prompt that has since been deleted.
	 *
	 * @since 0.4.0
	 *
	 * @param int      $prompt_id Prompt to re-arm.
	 * @param Schedule $schedule  Schedule to evaluate when the prompt cannot be read.
	 * @return int|WP_Error Unix timestamp the run is armed for, or an error.
	 */
	public function rearm( int $prompt_id, Schedule $schedule ): int|WP_Error {
		if ( ! $this->is_available() ) {
			return $this->unavailable();
		}

		$lock = new Named_Lock( 'prompt_' . $prompt_id );

		$lock->acquire();

		try {
			$this->cancel( $prompt_id );

			return $this->arm_held( $prompt_id, $this->persisted_schedule( $prompt_id, $schedule ) );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Returns the schedule the prompt currently holds, or the caller's copy.
	 *
	 * @since 1.13.4
	 *
	 * @param int      $prompt_id Prompt to read.
	 * @param Schedule $fallback  Used when the prompt is gone or unreadable.
	 * @return Schedule
	 */
	private function persisted_schedule( int $prompt_id, Schedule $fallback ): Schedule {
		$prompt = Prompt::load( $prompt_id );

		if ( null === $prompt ) {
			return $fallback;
		}

		$schedule = $prompt->schedule();

		return $schedule instanceof Schedule ? $schedule : $fallback;
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
	 * Arms the next step of an open run.
	 *
	 * Armed for now rather than for a delay: the split exists to shorten each
	 * request, not to slow the run down. Action Scheduler will pick it up on its
	 * next pass, which is where the extra wall-clock time comes from.
	 *
	 * @since 1.1.0
	 *
	 * @param int $run_id Run to advance.
	 * @return true|WP_Error
	 */
	public function schedule_step( int $run_id ): bool|WP_Error {
		if ( ! $this->is_available() ) {
			return $this->unavailable();
		}

		/*
		 * Not unique, and that is not an oversight. Action Scheduler's uniqueness
		 * counts actions that are pending *or* in progress, and every step arms
		 * its successor from inside itself — so the action doing the arming is
		 * itself the duplicate, and the whole chain stops after its first step.
		 * Tried, and it does exactly that.
		 *
		 * Duplicate delivery is guarded where it can actually be guarded:
		 * Run::claim_step() is a compare-and-swap on the run's position, so of
		 * two workers reaching the same run only one proceeds. Uniqueness would
		 * have stopped a second row existing; the claim stops a second worker
		 * spending, which is the property that matters.
		 */
		$armed = as_schedule_single_action( time(), self::HOOK_RUN_STEP, array( 'run_id' => $run_id ), self::GROUP );

		return $this->armed( $armed ) ? true : $this->not_armed();
	}

	/**
	 * Cancels any queued action for one run.
	 *
	 * Cancelling a prompt clears the prompt's own actions, and step actions are
	 * keyed by run rather than by prompt, so nothing else reaches them. A run that has been
	 * closed does not need advancing, and leaving its action queued means a
	 * pointless wake-up that finds the run already finished.
	 *
	 * @since 1.1.0
	 *
	 * @param int $run_id Run whose actions should be dropped.
	 * @return void
	 */
	public function cancel_step_actions( int $run_id ): void {
		if ( ! $this->is_available() ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK_RUN_STEP, array( 'run_id' => $run_id ), self::GROUP );
	}

	/**
	 * Returns which of these runs have a queued or running step action.
	 *
	 * One query for a page of runs rather than one per run. Asking individually
	 * turned a bounded sweep into two thousand queue-store round trips against
	 * the very queue it is there to watch, which is a strange way to look after
	 * something.
	 *
	 * @since 1.1.1
	 *
	 * @param int[] $run_ids Runs to ask about.
	 * @return array<int, true> The subset that has an action, keyed by run ID.
	 */
	public function runs_with_step_actions( array $run_ids ): array {
		$run_ids = array_values( array_unique( array_map( 'intval', $run_ids ) ) );

		if ( array() === $run_ids || ! $this->is_available() ) {
			return array();
		}

		$active = $this->active_step_runs();

		if ( null === $active ) {
			return $this->one_by_one( $run_ids );
		}

		$busy = array();

		foreach ( $run_ids as $run_id ) {
			if ( isset( $active[ $run_id ] ) ) {
				$busy[ $run_id ] = true;
			}
		}

		return $busy;
	}

	/**
	 * Whether the queue's active store is the one these tables belong to.
	 *
	 * The table existing is not the same question. Action Scheduler picks its
	 * store through the action_scheduler_store_class filter, so a site running
	 * the legacy post store or one of its own can leave a perfectly good
	 * actionscheduler_actions table behind that nothing writes to any more.
	 * Reading it would then report an empty active set, and every open run would
	 * look unattended.
	 *
	 * What that costs is not a wrong recovery — recover() re-asks the public API
	 * about each run before it does anything — but the two thousand per-run reads
	 * the bulk query exists to remove. Asking the store what it is costs one
	 * method call.
	 *
	 * The hybrid store counts. It is not a different place to keep actions but a
	 * migration wrapper whose destination is the database store, so every action
	 * created while it is in place — which is every action this plugin schedules —
	 * is in the table this reads. It is also the store a stock Action Scheduler
	 * install runs while it migrates, which is to say the ordinary case rather
	 * than an exotic one. An unmigrated legacy action would be missed, and the
	 * per-run re-check is what makes that safe rather than merely unlikely.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	private function uses_database_store(): bool {
		if ( ! class_exists( '\ActionScheduler' ) || ! class_exists( '\ActionScheduler_DBStore' ) ) {
			return false;
		}

		$store = \ActionScheduler::store();

		return $store instanceof \ActionScheduler_DBStore
			|| ( class_exists( '\ActionScheduler_HybridStore' ) && $store instanceof \ActionScheduler_HybridStore );
	}

	/**
	 * Returns every prompt with a queued or running run action, keyed by prompt ID.
	 *
	 * The schedule sweep asks about a page of prompts at a time, and asking one at
	 * a time made that a queue lookup per prompt every five minutes — against the
	 * queue the sweep exists to look after. The same reasoning, and the same
	 * store check, as active_step_runs().
	 *
	 * @since 1.11.0
	 *
	 * @return array<int, true>|null Null when the answer cannot be had this way.
	 */
	public function active_prompt_actions(): ?array {
		$rows = $this->active_action_args( self::HOOK_RUN_PROMPT );

		if ( null === $rows ) {
			return null;
		}

		$active = array();

		foreach ( $rows as $args ) {
			$decoded   = json_decode( (string) $args, true );
			$prompt_id = is_array( $decoded ) ? (int) ( $decoded['prompt_id'] ?? 0 ) : 0;

			if ( $prompt_id > 0 ) {
				$active[ $prompt_id ] = true;
			}
		}

		return $active;
	}

	/**
	 * Returns the arguments of every pending or running action for one hook.
	 *
	 * @since 1.11.0
	 *
	 * @param string $hook Hook to read.
	 * @return string[]|null Null when the active store is not the database one.
	 */
	private function active_action_args( string $hook ): ?array {
		global $wpdb;

		if ( ! $this->is_available() || ! $this->uses_database_store() ) {
			return null;
		}

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';

		// The table can also be missing outright on a fresh or partial install.
		if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) ) {
			return null;
		}

		$wpdb->last_error = '';

		$rows = $groups === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $groups ) )
			? $wpdb->get_col(
				$wpdb->prepare(
					'SELECT a.args FROM %i a INNER JOIN %i g ON a.group_id = g.group_id
					WHERE a.hook = %s AND a.status IN ( %s, %s ) AND g.slug = %s',
					$actions,
					$groups,
					$hook,
					\ActionScheduler_Store::STATUS_PENDING,
					\ActionScheduler_Store::STATUS_RUNNING,
					self::GROUP
				)
			)
			: $wpdb->get_col(
				$wpdb->prepare(
					'SELECT args FROM %i WHERE hook = %s AND status IN ( %s, %s )',
					$actions,
					$hook,
					\ActionScheduler_Store::STATUS_PENDING,
					\ActionScheduler_Store::STATUS_RUNNING
				)
			);

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Returns every run with a queued or running step action, keyed by run ID.
	 *
	 * One query for the whole queue rather than one per page of candidates. The
	 * statement was already unfiltered by run — the args are JSON, so the run ID
	 * cannot be a WHERE clause — and calling it once per page therefore read the
	 * same rows again for every page, up to twenty times a sweep on the busy
	 * sites the paging exists for. The intersection with a page's candidates is
	 * cheap; the read is not.
	 *
	 * Returns null when the answer cannot be had this way, which means the store
	 * is the legacy post-based one or something has replaced it. The caller falls
	 * back to asking about each run through the public API.
	 *
	 * @since 1.3.0
	 *
	 * @return array<int, true>|null
	 */
	public function active_step_runs(): ?array {
		$rows = $this->active_action_args( self::HOOK_RUN_STEP );

		if ( null === $rows ) {
			return null;
		}

		$active = array();

		foreach ( $rows as $args ) {
			$decoded = json_decode( (string) $args, true );
			$run_id  = is_array( $decoded ) ? (int) ( $decoded['run_id'] ?? 0 ) : 0;

			if ( $run_id > 0 ) {
				$active[ $run_id ] = true;
			}
		}

		return $active;
	}

	/**
	 * Falls back to asking about each run separately.
	 *
	 * For a store this code cannot query directly — the legacy post-based one,
	 * or a replacement someone has substituted. Slow, but correct, and the
	 * alternative is guessing about runs that may be mid-flight.
	 *
	 * @since 1.1.1
	 *
	 * @param int[] $run_ids Runs to ask about.
	 * @return array<int, true>
	 */
	private function one_by_one( array $run_ids ): array {
		$busy = array();

		foreach ( $run_ids as $run_id ) {
			if ( $this->has_step_action( $run_id ) ) {
				$busy[ $run_id ] = true;
			}
		}

		return $busy;
	}

	/**
	 * Whether anything is queued or running to advance this run.
	 *
	 * This is what tells a stalled run from a slow one. A run that is working has
	 * an action either waiting or in progress; a run whose action was killed has
	 * neither, and nothing will ever pick it up again, because Action Scheduler
	 * records a failure and stops rather than retrying.
	 *
	 * @since 1.1.0
	 *
	 * @param int $run_id Run to look for.
	 * @return bool
	 */
	public function has_step_action( int $run_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			// Unknowable, so assume it is in hand rather than act on a guess.
			return true;
		}

		$found = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK_RUN_STEP,
				'args'     => array( 'run_id' => $run_id ),
				'status'   => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
				'per_page' => 1,
			),
			'ids'
		);

		return is_array( $found ) && array() !== $found;
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

		$armed = as_schedule_single_action( $timestamp, self::HOOK_RUN_PROMPT, $this->args( $prompt_id ), self::GROUP );

		return $this->armed( $armed ) ? $timestamp : $this->not_armed();
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
	 * Getting the right time out of Action Scheduler takes some care, and 1.0.1
	 * got it wrong. Its query ordered by 'date' and read the schedule's own date,
	 * and both of those are the time the action was *due*: the store maps 'date'
	 * onto scheduled_date_gmt, and a schedule object holds the time it was armed
	 * for. An action due last Tuesday and run a moment ago was reported as having
	 * run last Tuesday — precisely inverting the panel's purpose, since a badly
	 * backed-up queue looked stalest exactly when it was catching up.
	 *
	 * 'modified' orders by last_attempt_gmt instead, and the store's get_date()
	 * returns last_attempt_gmt for any action that is no longer pending. That is
	 * the completion time.
	 *
	 * @since 1.0.1
	 *
	 * @return int|null Unix timestamp, or null when nothing has completed or the
	 *                  queue cannot be queried.
	 */
	public function last_processed(): ?int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return null;
		}

		$ids = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'complete',
				'per_page' => 1,
				'orderby'  => 'modified',
				'order'    => 'DESC',
			),
			'ids'
		);

		if ( ! is_array( $ids ) || array() === $ids ) {
			return null;
		}

		try {
			$date = \ActionScheduler::store()->get_date( (int) reset( $ids ) );
		} catch ( \Exception $e ) {
			// The row can be pruned between the query and the lookup.
			return null;
		}

		return $date instanceof \DateTimeInterface ? $date->getTimestamp() : null;
	}

	/**
	 * Whether Action Scheduler accepted an action.
	 *
	 * It returns the new action's ID, and 0 when it could not create one — a
	 * queue-table write failure, most plainly. Every arming call in this class
	 * discarded that, and each discarded it into a different silence: a failed
	 * step left a run in `running` with no next action and its budget
	 * reservation held indefinitely; a failed retry dropped the attempt; and a
	 * failed re-arm stopped the prompt for ever, which is the one thing section
	 * 4.3 exists to prevent.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $result Whatever as_schedule_single_action() returned.
	 * @return bool
	 */
	private function armed( $result ): bool {
		return is_numeric( $result ) && (int) $result > 0;
	}

	/**
	 * Returns the error for an action the queue would not accept.
	 *
	 * @since 1.1.0
	 *
	 * @return WP_Error
	 */
	private function not_armed(): WP_Error {
		return new WP_Error(
			'autoscribe_action_not_scheduled',
			__( 'The action queue would not accept the job. Check that the Action Scheduler tables exist and are writable.', 'autoscribe' )
		);
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
