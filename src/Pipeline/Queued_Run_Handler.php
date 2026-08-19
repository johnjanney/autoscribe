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
			$this->abandon( $prompt_id );

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
			$run->fail( $armed->get_error_message(), null, $run->grounded_calls() );
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
				$run->grounded_calls()
			);
			$this->scheduler->cancel_step_actions( $run_id );
			$this->abandon( $prompt_id );

			return;
		}

		$changed = $this->config_changed( $prompt, $run );

		if ( null !== $changed ) {
			$run->fail( $changed->get_error_message(), null, $run->grounded_calls() );
			$this->conclude( $prompt, $run->attempt(), $changed );

			return;
		}

		$step = $this->generator->advance( $prompt, $run );

		if ( is_wp_error( $step ) ) {
			$this->generator->close( $run, $step, $run->grounded_calls() );
			$this->conclude( $prompt, $run->attempt(), $step );

			return;
		}

		if ( null !== $step ) {
			$armed = $this->scheduler->schedule_step( $run_id );

			if ( is_wp_error( $armed ) ) {
				$this->generator->close( $run, $armed, $run->grounded_calls() );
				$this->conclude( $prompt, $run->attempt(), $armed );
			}

			return;
		}

		$this->finish( $prompt, $run );
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
	 * @param Prompt $prompt Prompt being run.
	 * @param Run    $run    Run to finish.
	 * @return void
	 */
	private function finish( Prompt $prompt, Run $run ): void {
		$article = $this->generator->article( $run );

		if ( is_wp_error( $article ) ) {
			$this->generator->close( $run, $article, $run->grounded_calls() );
			$this->conclude( $prompt, $run->attempt(), $article );

			return;
		}

		$result = $this->generator->finalise( $prompt, $run, $article, null, new Pricing_Table(), $run->grounded_calls() );

		$this->conclude( $prompt, $run->attempt(), is_wp_error( $result ) ? $result : null );
	}

	/**
	 * Gives up on a prompt that is gone or switched off.
	 *
	 * The two paths that abandon a run this way do not go through conclude(),
	 * and should not: there is no next occurrence to arm for a prompt somebody
	 * has just turned off, and no failure worth mailing about a deliberate act.
	 *
	 * What they do still owe is the attempt counter. It lives on the prompt
	 * rather than the run, because a retry opens a new row and the count has to
	 * survive across them — so leaving it raised means a prompt switched back on
	 * later resumes mid-series and quietly gets fewer attempts than it should.
	 *
	 * @since 1.1.0
	 *
	 * @param int $prompt_id Prompt to give up on.
	 * @return void
	 */
	private function abandon( int $prompt_id ): void {
		$this->scheduler->cancel( $prompt_id );

		self::forget_attempts( $prompt_id );
	}

	/**
	 * Clears a prompt's retry counter.
	 *
	 * Shared with the prompt's own lifecycle rather than kept to the queue
	 * callbacks, because the ordinary way a prompt is switched off is while
	 * nothing is executing: the only queued action is a pending retry, and saving
	 * the prompt cancels it. No callback runs, so nothing in this class would
	 * ever reach the cleanup, and re-enabling the prompt would resume midway
	 * through a retry series it had abandoned.
	 *
	 * @since 1.1.0
	 *
	 * @param int $prompt_id Prompt to reset.
	 * @return void
	 */
	public static function forget_attempts( int $prompt_id ): void {
		delete_post_meta( $prompt_id, self::ATTEMPT_META );
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
	public function conclude_run( Prompt $prompt, int $attempt, ?WP_Error $error ): void {
		$this->conclude( $prompt, $attempt, $error );
	}

	/**
	 * Decides what happens after a run ends, however it ended.
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

			$queued = $this->scheduler->schedule_retry( $prompt_id, $this->policy->delay_seconds( $attempt ) );

			if ( ! is_wp_error( $queued ) ) {
				return;
			}

			/*
			 * The retry could not be armed. This branch deliberately leaves the
			 * regular occurrence unarmed, because a retry is outstanding — so
			 * returning here would leave the prompt with a raised attempt
			 * counter, no queued action of any kind, and nothing said: a refusal
			 * that is reported all the way to this line and still stops the
			 * prompt silently.
			 *
			 * Falling through instead treats the run as finished: the counter is
			 * cleared, the next occurrence is armed, and the notice below reports
			 * the refusal rather than the transient failure that prompted the
			 * retry. The transient failure is on the run row either way; that the
			 * queue would not take the retry is the part nobody would otherwise
			 * learn.
			 */
			$error = $queued;
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

			return;
		}

		/*
		 * A prompt whose next occurrence cannot be armed does not run again, and
		 * section 4.3 exists to prevent exactly that. Nothing else in the system
		 * will notice — there is no queued action left to fail and no run to
		 * record it against — so the only useful thing left to do is say so.
		 */
		Generator::send_failure_notice( $prompt->id(), $timestamp );
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
