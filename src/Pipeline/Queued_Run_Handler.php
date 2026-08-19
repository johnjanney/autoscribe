<?php
/**
 * Handler for queued prompt runs.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Scheduling\Scheduler;
use WP_Error;

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
		$run     = $this->generator->open( $prompt_id, $attempt );

		if ( is_wp_error( $run ) ) {
			$this->conclude( $prompt, $attempt, $run );

			return;
		}

		/*
		 * Opening a run is cheap; everything after it is not. This action stops
		 * here and arms the first step as its own request, which is the whole of
		 * section 5's split — a host with a short max_execution_time now kills at
		 * most one provider call rather than a whole article.
		 */
		$armed = $this->scheduler->schedule_step( $run->id() );

		if ( is_wp_error( $armed ) ) {
			$run->fail( $armed->get_error_message(), null, $this->grounded_calls( $prompt ) );
			$this->conclude( $prompt, $attempt, $armed );
		}
	}

	/**
	 * Advances one open run by a single step, and arms the next.
	 *
	 * @since 1.1.0
	 *
	 * @param int $run_id Run to advance.
	 * @return void
	 */
	public function handle_step( int $run_id ): void {
		$run = Run::load( $run_id );

		if ( null === $run ) {
			// The row was pruned, or the action outlived the run it was armed for.
			return;
		}

		if ( Run::STATUS_RUNNING !== $run->status() ) {
			// Something already closed it — a duplicate skip, or a second worker.
			return;
		}

		$prompt_id = $run->prompt_id();
		$prompt    = Prompt::load( $prompt_id );

		if ( null === $prompt || ! $prompt->enabled() ) {
			/*
			 * A prompt turned off or trashed part-way through its own chain.
			 * Scheduler::cancel() cannot reach these actions, because they are
			 * keyed by run rather than by prompt, so the check lives here instead
			 * — and this way it also catches a prompt disabled between two steps.
			 */
			$run->fail(
				__( 'The prompt was disabled or removed while this run was in progress.', 'autoscribe' ),
				null,
				$this->grounded_calls( $prompt )
			);
			$this->scheduler->cancel( $prompt_id );

			return;
		}

		$changed = $this->config_changed( $prompt, $run );

		if ( null !== $changed ) {
			$run->fail( $changed->get_error_message(), null, $this->grounded_calls( $prompt ) );
			$this->conclude( $prompt, $run->attempt(), $changed );

			return;
		}

		$grounded = $prompt->grounding_enabled() ? 1 : 0;
		$step     = $this->generator->advance( $prompt, $run );

		if ( is_wp_error( $step ) ) {
			$this->generator->close( $run, $step, $grounded );
			$this->conclude( $prompt, $run->attempt(), $step );

			return;
		}

		if ( null !== $step ) {
			$armed = $this->scheduler->schedule_step( $run_id );

			if ( is_wp_error( $armed ) ) {
				$this->generator->close( $run, $armed, $grounded );
				$this->conclude( $prompt, $run->attempt(), $armed );
			}

			return;
		}

		$this->finish( $prompt, $run, $grounded );
	}

	/**
	 * Returns the number of grounded requests to settle a run against.
	 *
	 * Run::fail() settles from measured usage, and the grounded-request charge is
	 * not part of that measurement — it has to be passed in. Every abort path
	 * here left it at zero, so a run that had already paid for a grounded body
	 * call settled for less than it spent and the month-to-date total the section
	 * 7.4 cap reads was short by the difference.
	 *
	 * The prompt's setting rather than a count of calls actually made: settlement
	 * ignores it entirely when no usage was recorded, so a run that stopped
	 * before the body call is unaffected, and over-stating a cap is the safe
	 * direction to be wrong in.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @return int
	 */
	private function grounded_calls( Prompt $prompt ): int {
		return $prompt->grounding_enabled() ? 1 : 0;
	}

	/**
	 * Returns an error when the prompt was edited after this run started.
	 *
	 * A run used to read its configuration once, because it happened inside a
	 * single request. Spread across queued actions it reads the prompt again at
	 * every step, so an edit landing part-way through applies to the remaining
	 * steps: a larger model or a newly required image spends against a cap that
	 * was checked for the old settings, and a change of publication mode can
	 * publish a run that began under review — section 10's safety model turned
	 * off retrospectively for work already in progress.
	 *
	 * Stopping is the honest answer rather than a cautious one. Carrying on means
	 * finishing under settings nobody checked, and the alternative — re-checking
	 * the budget and continuing — still leaves the earlier steps' output built
	 * from configuration the run no longer has. The next occurrence runs with the
	 * new settings from the start, which is what the editor asked for.
	 *
	 * A run with no recorded fingerprint is left alone. That is a run opened by
	 * an earlier version whose chain is still in flight across the upgrade, and
	 * failing it would be a worse answer than finishing it.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @param Run    $run    Run in progress.
	 * @return WP_Error|null
	 */
	private function config_changed( Prompt $prompt, Run $run ): ?WP_Error {
		$recorded = $run->payload()['config'] ?? '';

		if ( ! is_string( $recorded ) || '' === $recorded ) {
			return null;
		}

		if ( hash_equals( $recorded, $prompt->config_fingerprint() ) ) {
			return null;
		}

		return new WP_Error(
			'autoscribe_prompt_changed',
			__( 'This prompt was edited while the run was in progress, so the run was stopped rather than finishing under settings it was never checked against. The next scheduled run will use the new settings.', 'autoscribe' )
		);
	}

	/**
	 * Publishes a run that has run out of steps.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt $prompt   Prompt being run.
	 * @param Run    $run      Run to finish.
	 * @param int    $grounded Number of grounded requests made.
	 * @return void
	 */
	private function finish( Prompt $prompt, Run $run, int $grounded ): void {
		$article = $this->generator->article( $run );

		if ( is_wp_error( $article ) ) {
			$this->generator->close( $run, $article, $grounded );
			$this->conclude( $prompt, $run->attempt(), $article );

			return;
		}

		$result = $this->generator->finalise( $prompt, $run, $article, null, new Pricing_Table(), $grounded );

		$this->conclude( $prompt, $run->attempt(), is_wp_error( $result ) ? $result : null );
	}

	/**
	 * Decides what happens after a run ends, however it ended.
	 *
	 * Every path that ends a run comes through here, which is the point: section
	 * 4.3 requires the next occurrence to be armed whether the run succeeded or
	 * failed, and a chain spread across several actions has many more ways to
	 * end than one that ran in a single request did.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt        $prompt  Prompt that ran.
	 * @param int           $attempt Attempt number that just ended.
	 * @param WP_Error|null $error   The failure, or null on success.
	 * @return void
	 */
	private function conclude( Prompt $prompt, int $attempt, ?WP_Error $error ): void {
		$prompt_id = $prompt->id();

		if ( null !== $error && $this->policy->should_retry( $error, $attempt ) ) {
			update_post_meta( $prompt_id, self::ATTEMPT_META, $attempt + 1 );
			$this->scheduler->schedule_retry( $prompt_id, $this->policy->delay_seconds( $attempt ) );

			return;
		}

		/*
		 * Section 5 asks for one notification once the attempts are spent, not
		 * one per attempt. This is the only point that knows the difference: the
		 * branch above has already taken every failure that will be tried again.
		 * Skips are outcomes rather than faults, so they are not mailed.
		 */
		if ( null !== $error && ! $this->is_skip( $error ) ) {
			Generator::send_failure_notice( $prompt_id, $error );
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
	 * Whether a failure is a deliberate skip rather than a fault.
	 *
	 * @since 1.0.1
	 *
	 * @param WP_Error $error The failure.
	 * @return bool
	 */
	private function is_skip( WP_Error $error ): bool {
		return in_array(
			$error->get_error_code(),
			array( 'autoscribe_duplicate_topic', 'autoscribe_budget_exceeded' ),
			true
		);
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
