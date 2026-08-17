<?php
/**
 * Scheduled pruning of the run log.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Admin\Settings;
use AutoScribe\Scheduling\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the runs table from growing without bound.
 *
 * Section 3.2 requires a daily job that deletes run rows older than a configured
 * number of days. Without it a site generating daily accumulates rows forever,
 * and the run log and the month-to-date spend query both get slower every month
 * for history nobody reads.
 *
 * @since 0.7.0
 */
final class Run_Retention {

	/**
	 * Action Scheduler hook for the daily prune.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const HOOK = 'autoscribe_prune_runs';

	/**
	 * Arms the daily job when it is not already queued.
	 *
	 * @since 0.7.0
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
			time() + DAY_IN_SECONDS,
			DAY_IN_SECONDS,
			self::HOOK,
			array(),
			Scheduler::GROUP
		);
	}

	/**
	 * Cancels the daily job.
	 *
	 * @since 0.7.0
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
	 * Deletes run rows past the configured retention period.
	 *
	 * @since 0.7.0
	 *
	 * @return int Number of rows removed.
	 */
	public static function handle(): int {
		return Run::prune( Settings::retention_days() );
	}
}
