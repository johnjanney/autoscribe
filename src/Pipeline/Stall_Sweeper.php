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
	 * Examines open runs and recovers the ones nothing is advancing.
	 *
	 * @since 1.1.0
	 *
	 * @return int How many runs were acted on.
	 */
	public function handle(): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::threshold() );
		$acted  = 0;
		$after  = 0;

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
		 */
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$candidates = Run::open_before( $cutoff, self::PAGE, $after );

			if ( array() === $candidates ) {
				break;
			}

			$after = (int) end( $candidates );

			foreach ( $candidates as $run_id ) {
				if ( $this->scheduler->has_step_action( $run_id ) ) {
					// Waiting its turn, or working. Not stalled.
					continue;
				}

				if ( $this->recover( $run_id ) ) {
					++$acted;
				}

				if ( $acted >= self::BATCH ) {
					return $acted;
				}
			}
		}

		return $acted;
	}

	/**
	 * Restarts one stalled run, or gives up on it.
	 *
	 * @since 1.1.0
	 *
	 * @param int $run_id Run to recover.
	 * @return bool Whether anything was done.
	 */
	private function recover( int $run_id ): bool {
		$run = Run::load( $run_id );

		if ( null === $run || Run::STATUS_RUNNING !== $run->status() ) {
			// Closed between the query and here.
			return false;
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
			$this->give_up(
				$run,
				null,
				new WP_Error(
					'autoscribe_run_stalled',
					__( 'This run stopped part-way and its prompt no longer exists, so it was closed and what it had reserved was released.', 'autoscribe' )
				)
			);

			return true;
		}

		if ( $run->sweeps() >= self::MAX_RESTARTS ) {
			$this->give_up(
				$run,
				$prompt,
				new WP_Error(
					'autoscribe_run_stalled',
					sprintf(
						/* translators: %d: number of restarts already attempted. */
						__( 'This run stopped part-way %d times and was given up on. Whatever it had reserved against the monthly cap has been released. A host that ends requests early is the usual cause; see the system cron guidance in the README.', 'autoscribe' ),
						self::MAX_RESTARTS
					)
				)
			);

			return true;
		}

		/*
		 * Count the restart before arming it. A restart that is recorded and then
		 * fails to arm is swept again and counted again, which converges on
		 * giving up; one that is armed and then fails to record would be restarted
		 * for ever.
		 */
		if ( ! $run->record_sweep() ) {
			return false;
		}

		$armed = $this->scheduler->schedule_step( $run_id );

		if ( is_wp_error( $armed ) ) {
			$this->give_up( $run, $prompt, $armed );
		}

		return true;
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
	 * @param WP_Error    $reason Why it was given up on.
	 * @return void
	 */
	private function give_up( Run $run, ?Prompt $prompt, WP_Error $reason ): void {
		$run->fail( $reason->get_error_message(), null, $run->grounded_calls() );

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

			return;
		}

		/*
		 * Through the handler, so a swept run ends the same way any other failed
		 * run does: the attempt counter cleared, the failure reported, and the
		 * next occurrence armed. Section 4.3 does not make an exception for runs
		 * that ended badly.
		 */
		$this->handler->conclude_run( $prompt, $run->attempt(), $reason );
	}
}
