<?php
/**
 * Recovery for runs the queue stopped advancing.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Prompts\Prompt;
use AutoScribe\Scheduling\Scheduler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Picks up runs that stopped part-way and either restarts or closes them.
 *
 * Splitting the pipeline across queued actions does not, by itself, give
 * recovery. Action Scheduler records a killed action as failed and stops; it
 * does not retry — which is why Retry_Policy exists at all. So a step killed by
 * a PHP timeout leaves a run open with nothing queued to advance it, and nothing
 * anywhere will ever pick it up again. The split shortened the window in which
 * that can happen, from a whole article to one provider call. This closes it.
 *
 * It also settles what such a run reserved. That matters more than it sounds:
 * Step_Budget_Check writes the estimated cost onto the run before the first paid
 * call so that concurrent runs can see it, and section 7.4's cap reads every open
 * run's reservation. A run abandoned mid-flight therefore holds its estimate
 * against the monthly cap for ever — the cap silently fills with money nobody
 * spent, and prompts start skipping for no visible reason. **That failure mode
 * did not exist before the split**; it is the debt the split took on, and this is
 * where it is paid.
 *
 * A stalled run is not a slow one. Age alone cannot tell them apart, because a
 * legitimate run can take several queue passes. The test is whether anything is
 * queued or running to advance it: a working run always has an action, and a
 * killed one has none. Age is used only to keep the sweeper away from runs too
 * young to judge.
 *
 * @since 1.1.0
 */
final class Stall_Sweeper {

	/**
	 * Action Scheduler hook for the sweep.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const HOOK = 'autoscribe_sweep_runs';

	/**
	 * How often the sweep runs, in seconds.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * How old an open run must be before the sweeper will judge it, in seconds.
	 *
	 * It has to exceed the longest a single step can legitimately take — a
	 * 120-second provider timeout — plus however long the queue takes to pick the
	 * next action up. Fifteen minutes is generous on both counts. Too low and the
	 * sweeper competes with runs that are simply waiting their turn.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const THRESHOLD = 15 * MINUTE_IN_SECONDS;

	/**
	 * How many times a run may be restarted before it is given up on.
	 *
	 * A run that stalls twice is not unlucky. Restarting for ever would keep a
	 * broken run cycling through paid steps, which is the opposite of what a
	 * budget cap is for.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const MAX_RESTARTS = 2;

	/**
	 * How old a preview must be before the sweeper will close it, in seconds.
	 *
	 * A preview has no queued action to look for, because it runs synchronously
	 * inside the request that asked for it — so the liveness test every other run
	 * gets does not apply to it, and age is all there is. That makes the age the
	 * whole guard, and it has to exceed the longest a preview can legitimately
	 * take: two topic proposals and a body call with its repair, each able to use
	 * the full 120-second provider timeout, plus whatever the host adds.
	 *
	 * Half an hour is generous on every count, and it is a floor rather than a
	 * replacement: preview_threshold() takes the larger of this and the queued-run
	 * threshold. Lowering the queued threshold — the filter allows two minutes,
	 * which is inside a normal preview — therefore cannot pull this down and
	 * report a failure for a request that then succeeds, while raising it for a
	 * slow host raises this with it.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	public const PREVIEW_THRESHOLD = 30 * MINUTE_IN_SECONDS;

	/**
	 * Most runs to recover in one sweep.
	 *
	 * A cap on what is acted on, not on what is looked at — see handle().
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const BATCH = 25;

	/**
	 * How many open runs one scan query reads.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const PAGE = 100;

	/**
	 * How many pages one sweep reads before leaving the rest to the next one.
	 *
	 * Bounds the work a single sweep does on a site with a very large backlog,
	 * which is the job the batch size used to be doing badly.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const MAX_PAGES = 20;

	/**
	 * Option holding where the last sweep stopped reading.
	 *
	 * Paging fixed starvation within one sweep and would have left it between
	 * sweeps: a backlog wider than one sweep reads means every sweep restarts at
	 * the oldest row and inspects the same rows again, so a stalled run past the
	 * end of that window is never reached and keeps its reservation. The same
	 * starvation, moved further out.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	public const CURSOR_OPTION = 'autoscribe_sweep_cursor';

	/**
	 * Queue wrapper.
	 *
	 * @since 1.1.0
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Queued run handler, which owns what happens after a run ends.
	 *
	 * @since 1.1.0
	 * @var Queued_Run_Handler
	 */
	private Queued_Run_Handler $handler;

	/**
	 * Builds the sweeper.
	 *
	 * @since 1.1.0
	 *
	 * @param Scheduler          $scheduler Queue wrapper.
	 * @param Queued_Run_Handler $handler   Queued run handler.
	 */
	public function __construct( Scheduler $scheduler, Queued_Run_Handler $handler ) {
		$this->scheduler = $scheduler;
		$this->handler   = $handler;
	}

	/**
	 * Arms the recurring sweep when it is not already queued.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK, array(), Scheduler::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::INTERVAL,
			self::INTERVAL,
			self::HOOK,
			array(),
			Scheduler::GROUP
		);
	}

	/**
	 * Cancels the recurring sweep.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, array(), Scheduler::GROUP );
	}

	/**
	 * Returns the age an open run must reach before the sweeper judges it.
	 *
	 * @since 1.1.0
	 *
	 * @return int Seconds.
	 */
	public static function threshold(): int {
		/**
		 * Filters how long an open run may go unattended before it is treated as
		 * stalled.
		 *
		 * @since 1.1.0
		 *
		 * @param int $seconds Age in seconds.
		 */
		$seconds = (int) apply_filters( 'autoscribe_stall_threshold', self::THRESHOLD );

		// Below one provider timeout the sweeper would be racing working runs.
		return max( 2 * MINUTE_IN_SECONDS, $seconds );
	}

	/**
	 * Returns the age a preview must reach before the sweeper closes it.
	 *
	 * The larger of the two thresholds, so a site that has raised the queued-run
	 * threshold raises this with it and a site that has lowered it does not lower
	 * this below the length of a preview.
	 *
	 * @since 1.4.0
	 *
	 * @return int Seconds.
	 */
	public static function preview_threshold(): int {
		/**
		 * Filters how long a preview may run before it is treated as abandoned.
		 *
		 * @since 1.4.0
		 *
		 * @param int $seconds Age in seconds.
		 */
		$seconds = (int) apply_filters( 'autoscribe_preview_stall_threshold', self::PREVIEW_THRESHOLD );

		return max( self::threshold(), $seconds );
	}

	/**
	 * Examines open runs and recovers the ones nothing is advancing.
	 *
	 * @since 1.1.0
	 *
	 * @return int How many runs were acted on.
	 */
	public function handle(): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::threshold() );
		$acted  = 0;
		$after  = (int) get_option( self::CURSOR_OPTION, 0 );

		/*
		 * The batch caps how many runs are *recovered*, not how many are looked
		 * at, and the scan pages on past the healthy ones. Capping the look
		 * instead is subtly useless on the sites that need this most: a busy
		 * queue can hold more healthy open runs than a batch, so the same healthy
		 * rows are re-read on every sweep and anything newer is never reached —
		 * leaving a stalled run holding its reservation against the monthly cap
		 * for as long as the backlog lasts.
		 *
		 * The page count bounds the work instead, so one sweep of a very busy
		 * site is still a short request.
		 *
		 * The queue's active set is read once for the whole sweep rather than once
		 * per page. The statement cannot filter by run — Action Scheduler stores
		 * the arguments as JSON — so asking per page read the same rows again for
		 * every page, up to twenty times, on exactly the busy sites the paging
		 * exists for. Staleness across pages is already handled: recover()
		 * re-asks about the individual run before it does anything.
		 */
		$active = $this->scheduler->active_step_runs();

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$candidates = Run::open_before( $cutoff, self::PAGE, $after );

			if ( array() === $candidates ) {
				// Nothing left above the cursor, so the next sweep starts over.
				$after = 0;

				break;
			}

			$busy     = null === $active ? $this->scheduler->runs_with_step_actions( $candidates ) : $active;
			$observed = Run::steps_for( $candidates );

			foreach ( $candidates as $run_id ) {
				/*
				 * The cursor follows the run being examined, not the end of the
				 * page. Advancing it a page at a time would have the early return
				 * below record a position past runs this sweep never looked at,
				 * and the next sweep would skip them — which is the starvation
				 * the cursor exists to prevent, one page wide.
				 */
				$after = $run_id;

				if ( isset( $busy[ $run_id ] ) ) {
					// Waiting its turn, or working. Not stalled.
					continue;
				}

				if ( $this->recover( $run_id, (string) ( $observed[ $run_id ] ?? '' ) ) ) {
					++$acted;
				}

				if ( $acted >= self::BATCH ) {
					$this->remember_cursor( $after );

					return $acted;
				}
			}
		}

		$this->remember_cursor( $after );

		return $acted;
	}

	/**
	 * Stores where this sweep stopped reading, so the next one carries on.
	 *
	 * Zero means "start from the beginning", which is both the initial state and
	 * what a sweep that reached the end writes back. The cursor is an optimisation
	 * for finding stalled runs, not a record of anything, so a lost or stale value
	 * costs a repeated scan rather than correctness.
	 *
	 * @since 1.1.0
	 *
	 * @param int $after Highest run ID examined.
	 * @return void
	 */
	private function remember_cursor( int $after ): void {
		update_option( self::CURSOR_OPTION, max( 0, $after ), false );
	}

	/**
	 * Restarts one stalled run, or gives up on it.
	 *
	 * Public because it is the unit of recovery — one run, judged and acted on —
	 * and because the guards inside it protect against interleavings the page
	 * scan above cannot reproduce: a caller that reaches this directly can put a
	 * run in a state the scan would have filtered out, which is the only way to
	 * exercise what happens when the scan's view has gone stale.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $run_id   Run to recover.
	 * @param string $observed The step value seen when the run was judged idle.
	 * @return bool Whether anything was done.
	 */
	public function recover( int $run_id, string $observed ): bool {
		$run = Run::load( $run_id );

		if ( null === $run || Run::STATUS_RUNNING !== $run->status() ) {
			// Closed between the query and here.
			return false;
		}

		if ( $run->is_preview() ) {
			if ( $run->age_seconds() < self::preview_threshold() ) {
				// Young enough that the request making it may still be running.
				return false;
			}

			/*
			 * A preview is a run in every accounting sense and in no other: it
			 * makes paid calls, holds a reservation, and creates no post. Sending
			 * it down the ordinary recovery path put an article-shaped process
			 * over the top of it — the queued handler finds no step left to take,
			 * decides the run is ready to publish, and finalises a post that does
			 * not exist, then concludes the *prompt*: a failure notice, a retry
			 * decision, and a re-armed schedule, all for a button somebody pressed
			 * once. Closing it here is the whole of what an abandoned preview
			 * needs, because the only thing it left behind is its reservation.
			 */
			return $this->close_preview( $run, $observed );
		}

		$prompt = Prompt::load( $run->prompt_id() );

		/*
		 * A disabled prompt is treated like a removed one. It still loads, so the
		 * ordinary give-up path would conclude the run and arm the prompt's next
		 * occurrence — leaving a prompt somebody switched off with a queued action
		 * and a next-run time the editor displays. The action cancels itself when
		 * it eventually fires, but until then the readout says the opposite of
		 * what the setting says.
		 */
		if ( null !== $prompt && ! $prompt->enabled() ) {
			$prompt = null;
		}

		if ( null === $prompt ) {
			return $this->give_up(
				$run,
				null,
				new WP_Error(
					'autoscribe_run_stalled',
					__( 'This run stopped part-way and its prompt has since been removed or switched off, so it was closed and what it had reserved was released.', 'autoscribe' )
				),
				$observed
			);
		}

		/*
		 * Re-asked before anything terminal, not only before a release. Sweeps
		 * overlap, and the candidate scan can be many pages old by now: another
		 * sweep may have counted the restart that takes this run to its limit,
		 * armed it, and left a worker part-way through a paid call. Giving up
		 * would close the run under that worker's feet. The scan says a run is
		 * worth looking at; this says it is still true.
		 */
		if ( $this->scheduler->has_step_action( $run_id ) ) {
			return false;
		}

		$sweeps = $run->sweeps();

		if ( $sweeps >= self::MAX_RESTARTS ) {
			return $this->give_up(
				$run,
				$prompt,
				new WP_Error(
					'autoscribe_run_stalled',
					sprintf(
						/* translators: %d: number of restarts already attempted. */
						__( 'This run stopped part-way %d times and was given up on. Whatever it had reserved against the monthly cap has been released. The usual cause is a host ending requests before they finish: check this site\'s PHP max_execution_time and memory limit.', 'autoscribe' ),
						self::MAX_RESTARTS
					)
				),
				$observed
			);
		}

		/*
		 * Give up any claim the killed worker was holding. Nothing is queued or
		 * running for this run — that is how it got here — so the claim belongs
		 * to a worker that is not coming back, and leaving it would make the
		 * restart fail its claim and stall again immediately.
		 *
		 * A refused release is different from having nothing to release, and the
		 * difference matters: arming a restart that is guaranteed to lose an
		 * unchanged claim spends one of this run's attempts to achieve nothing,
		 * and doing that twice gives up on a run that was recoverable. Leave it
		 * for the next sweep instead.
		 */
		if ( false === $this->recover_claim( $run_id, $observed ) ) {
			return false;
		}

		/*
		 * Count the restart before arming it, and take the count as this sweep's
		 * claim on the run. A restart that is recorded and then fails to arm is
		 * swept again and counted again, which converges on giving up; one that is
		 * armed and then fails to record would be restarted for ever.
		 *
		 * The claim half matters where the release above does not reach. A run
		 * whose worker died between finishing a step and arming the next one has
		 * no claim to release, so two overlapping sweeps would both get this far
		 * and both arm a restart. The count is a compare-and-swap, so only one
		 * does.
		 */
		if ( ! $run->record_sweep( $sweeps ) ) {
			return false;
		}

		$armed = $this->scheduler->schedule_step( $run_id );

		if ( is_wp_error( $armed ) ) {
			$this->give_up( $run, $prompt, $armed, $run->raw_step() );
		}

		return true;
	}

	/**
	 * Closes an abandoned preview, and does nothing else.
	 *
	 * No re-arm, no failure notice, and no attempt counter: none of them belong
	 * to a preview. Settling the row is what releases the reservation it made
	 * against the monthly cap, which is the only lasting effect an abandoned
	 * preview has.
	 *
	 * @since 1.3.0
	 *
	 * @param Run    $run      Preview run to close.
	 * @param string $observed Position this sweep saw the run at.
	 * @return bool Whether this sweep closed it.
	 */
	private function close_preview( Run $run, string $observed ): bool {
		$closed = $run->fail(
			__( 'This preview stopped part-way and was closed. Whatever it had reserved against the monthly cap has been released. No post is created by a preview, so nothing else was affected.', 'autoscribe' ),
			null,
			$run->grounded_calls(),
			$observed,
			str_starts_with( $observed, Run::CLAIM_PREFIX )
		);

		if ( ! $closed->ended() ) {
			return false;
		}

		$this->scheduler->cancel_step_actions( $run->id() );

		return true;
	}

	/**
	 * Releases a claim left behind by a worker that never returned.
	 *
	 * @since 1.1.1
	 *
	 * @param int    $run_id   Run to free.
	 * @param string $observed The claim seen when the run was judged idle.
	 * @return bool|null True when a claim was released, false when the release
	 *                   was refused, and null when there was no claim.
	 */
	public function recover_claim( int $run_id, string $observed ): ?bool {
		$run = Run::load( $run_id );

		return null === $run ? null : $run->release_claim( $observed );
	}

	/**
	 * Closes a run the sweeper will not restart again.
	 *
	 * Failing the run is what releases the reservation: settlement replaces the
	 * estimate written before the first paid call with the cost of the usage
	 * actually recorded, so a run that stopped early gives back everything it did
	 * not spend.
	 *
	 * @since 1.1.0
	 *
	 * @param Run         $run    Run to close.
	 * @param Prompt|null $prompt Its prompt, or null when it no longer exists.
	 * @param WP_Error    $reason   Why it was given up on.
	 * @param string      $observed Position this sweep saw the run at.
	 * @return bool True when this sweep is the one that closed the run.
	 */
	private function give_up( Run $run, ?Prompt $prompt, WP_Error $reason, string $observed ): bool {
		/*
		 * Tied to the position this sweep observed. Another sweep can arm the
		 * restart that reaches the limit between this one's scan and this write,
		 * and its worker can already be claiming the step — closing the run then
		 * would cancel a paid call in flight. If the position has moved, that
		 * worker wins and this sweep leaves the run alone.
		 *
		 * A run interrupted while it held a claim may have paid for work nothing
		 * recorded: the provider answered, and the request died before — or while
		 * — the usage write landed. Settling such a run from its counters alone
		 * writes a figure that is known to be incomplete, and the monthly cap then
		 * has real spending missing from it for the rest of the month. The
		 * reservation is kept as a floor for that case only. An estimate that is
		 * too high costs the site a little of its own cap; an unrecorded charge
		 * costs it the cap.
		 */
		$interrupted = str_starts_with( $observed, Run::CLAIM_PREFIX );

		if ( ! $run->fail( $reason->get_error_message(), null, $run->grounded_calls(), $observed, $interrupted )->ended() ) {
			return false;
		}

		// Nothing should wake up for a run that is finished with.
		$this->scheduler->cancel_step_actions( $run->id() );

		if ( null === $prompt ) {
			/*
			 * Nothing to conclude for a prompt that is gone or switched off: there
			 * is no next occurrence to arm, and no failure worth mailing about a
			 * deliberate act. The attempt counter still belongs to the run series
			 * that just ended, so it goes.
			 */
			Queued_Run_Handler::forget_attempts( $run->prompt_id() );
			$this->scheduler->cancel( $run->prompt_id() );

			return true;
		}

		/*
		 * Through the handler, so a swept run ends the same way any other failed
		 * run does: the attempt counter cleared, the failure reported, and the
		 * next occurrence armed. Section 4.3 does not make an exception for runs
		 * that ended badly.
		 */
		$this->handler->conclude_run( $prompt, $run->attempt(), $reason );

		return true;
	}
}
