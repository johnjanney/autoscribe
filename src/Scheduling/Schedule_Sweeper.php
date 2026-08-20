<?php
/**
 * Recovery for prompts that have fallen out of the queue.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Scheduling;

use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Prompts\Prompt_Post_Type;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Arms an enabled prompt that nothing is going to run.
 *
 * A prompt is armed in exactly two places: when it is saved, and when one of its
 * runs concludes. Both are events. Nothing anywhere asks the standing question —
 * is this enabled prompt actually queued? — so a prompt that falls out of the
 * queue stays out of it until somebody opens the editor and presses Update.
 *
 * Falling out is not exotic. Action Scheduler records an action killed by a PHP
 * timeout as failed and does not retry it, which is the same fact Stall_Sweeper
 * exists for; but Stall_Sweeper recovers *runs*, and it finds them by scanning
 * the runs table. A request killed before `Run::start()` leaves no row there, so
 * there is nothing for it to find: no queued action, no open run, no trace. The
 * prompt simply stops, silently, and the editor goes on displaying the next
 * occurrence it would have had — because that readout is a calculation, not a
 * report.
 *
 * This closes that. It is deliberately conservative: it arms a prompt only when
 * the prompt is enabled, has a valid schedule, has nothing queued, and has no run
 * in flight. Anything else is somebody else's business.
 *
 * @since 1.10.0
 */
final class Schedule_Sweeper {

	/**
	 * How many prompts one pass looks at.
	 *
	 * A bound on the work rather than on the site. The first version of this
	 * selected the first two hundred enabled prompts every time and called that
	 * enough, on the reasoning that section 3.2 keeps the prompt count small —
	 * but nothing enforces that, and a site with more than two hundred would have
	 * had the prompts beyond them excluded from recovery for ever rather than
	 * merely delayed. The cursor below is what turns a bound into a queue.
	 *
	 * @since 1.10.0
	 * @var int
	 */
	public const BATCH = 50;

	/**
	 * Option holding where the last pass stopped reading.
	 *
	 * Zero means "start from the beginning", which is both the initial state and
	 * what a pass that reached the end writes back. It is an optimisation for
	 * finding unqueued prompts rather than a record of anything, so a lost value
	 * costs a repeated scan rather than correctness.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	public const CURSOR_OPTION = 'autoscribe_schedule_cursor';

	/**
	 * Transient holding down the rate of unqueueable-prompt alerts.
	 *
	 * @since 1.11.0
	 * @var string
	 */
	public const NOTICE_TRANSIENT = 'autoscribe_schedule_notice';

	/**
	 * Queue wrapper.
	 *
	 * @since 1.10.0
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Builds the sweeper.
	 *
	 * @since 1.10.0
	 *
	 * @param Scheduler $scheduler Queue wrapper.
	 */
	public function __construct( Scheduler $scheduler ) {
		$this->scheduler = $scheduler;
	}

	/**
	 * Arms every enabled prompt that has nothing queued to run it.
	 *
	 * @since 1.10.0
	 *
	 * @return int How many prompts were armed.
	 */
	public function handle(): int {
		$after   = max( 0, (int) get_option( self::CURSOR_OPTION, 0 ) );
		$prompts = $this->enabled_prompts( $after );
		$armed   = 0;

		if ( array() === $prompts ) {
			// Nothing above the cursor, so the next pass starts over.
			update_option( self::CURSOR_OPTION, 0, false );

			return 0;
		}

		/*
		 * Two questions asked once for the page rather than twice per prompt. A
		 * recovery pass that runs every five minutes and costs a query per prompt
		 * grows into a load on the very queue it is there to protect.
		 */
		$queued = $this->scheduler->active_prompt_actions();
		$open   = Run::prompts_with_open_runs( $prompts );

		foreach ( $prompts as $prompt_id ) {
			$skip = null === $queued
				? null !== $this->scheduler->next_scheduled( $prompt_id )
				: isset( $queued[ $prompt_id ] );

			if ( $skip ) {
				continue;
			}

			// Unknown counts as running, so a failed read cannot authorise a
			// second run: see rearm(), which repeats the check for one prompt.
			if ( null === $open || isset( $open[ $prompt_id ] ) ) {
				continue;
			}

			if ( $this->rearm( $prompt_id ) ) {
				++$armed;
			}
		}

		update_option( self::CURSOR_OPTION, (int) end( $prompts ), false );

		return $armed;
	}

	/**
	 * Arms one prompt, if it is one this sweep should touch.
	 *
	 * Public because it is the unit of recovery, and because the conditions
	 * inside it are the whole of the safety: a caller reaching it directly can
	 * put a prompt in a state the batch query would not have selected, which is
	 * the only way to exercise them.
	 *
	 * @since 1.10.0
	 *
	 * @param int $prompt_id Prompt to consider.
	 * @return bool Whether this call armed it.
	 */
	public function rearm( int $prompt_id ): bool {
		$prompt = Prompt::load( $prompt_id );

		if ( null === $prompt || ! $prompt->enabled() ) {
			return false;
		}

		if ( null !== $this->scheduler->next_scheduled( $prompt_id ) ) {
			// Queued already, or running now. Either way it is somebody's.
			return false;
		}

		if ( false !== Run::has_open_run( $prompt_id ) ) {
			/*
			 * A run is in flight, or the question could not be answered. Its next
			 * occurrence is armed when it concludes, and arming one now would put a
			 * second article beside the first — the stall sweep is what recovers a
			 * run that never concludes.
			 *
			 * Unknown counts as running for the same reason it counts that way in
			 * the accounting guard: a read that failed is not evidence of absence,
			 * and the cost of being wrong in that direction is a paid duplicate.
			 */
			return false;
		}

		$schedule = $prompt->schedule();

		if ( is_wp_error( $schedule ) ) {
			/*
			 * Nothing to arm, and nothing this can do about it: a schedule that
			 * does not validate needs a person. The editor says so on the prompt's
			 * own screen, which is where the person is.
			 */
			return false;
		}

		$timestamp = $this->scheduler->arm( $prompt_id, $schedule );

		if ( is_wp_error( $timestamp ) ) {
			/*
			 * The prompt is enabled, valid, and not queued, and the queue would not
			 * take it. Nothing else will notice: there is no run to fail, no row to
			 * write an error on, and the editor only says so to somebody who opens
			 * it. Rate-limited because the sweep meets the same refusal every five
			 * minutes for as long as it lasts.
			 */
			$this->report( $prompt_id, $timestamp );

			return false;
		}

		$prompt->set_next_run_ts( $timestamp );

		return true;
	}

	/**
	 * Returns the IDs of enabled prompts.
	 *
	 * @since 1.10.0
	 *
	 * @param int $after Only prompts with an ID above this one.
	 * @return int[]
	 */
	private function enabled_prompts( int $after ): array {
		$prompts = $this->page_of_prompts( $after );

		if ( array() === $prompts && $after > 0 ) {
			// Past the end. Start again rather than waiting five minutes to.
			return $this->page_of_prompts( 0 );
		}

		return $prompts;
	}

	/**
	 * Tells the notification address that a prompt cannot be queued, at most hourly.
	 *
	 * @since 1.11.0
	 *
	 * @param int      $prompt_id Prompt that could not be armed.
	 * @param WP_Error $error     Why the queue refused it.
	 * @return void
	 */
	private function report( int $prompt_id, WP_Error $error ): void {
		if ( false !== get_transient( self::NOTICE_TRANSIENT ) ) {
			return;
		}

		set_transient( self::NOTICE_TRANSIENT, time(), HOUR_IN_SECONDS );

		Generator::send_failure_notice( $prompt_id, $error );
	}

	/**
	 * Reads one page of enabled prompt IDs above a cursor.
	 *
	 * A direct statement rather than WP_Query, because the cursor is the point:
	 * WP_Query cannot express "IDs above this one" without a posts_where filter,
	 * and a global filter installed for one query in a background sweep is a
	 * worse trade than a prepared statement over two tables. Enabled is stored as
	 * WordPress stores a checkbox — '1' when on, an empty string when off.
	 *
	 * @since 1.11.0
	 *
	 * @param int $after Only prompts with an ID above this one.
	 * @return int[]
	 */
	private function page_of_prompts( int $after ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = %s
					AND p.post_status NOT IN ( 'trash', 'auto-draft' )
					AND p.ID > %d
					AND m.meta_value NOT IN ( '', '0' )
				ORDER BY p.ID ASC
				LIMIT %d",
				'_autoscribe_enabled',
				Prompt_Post_Type::POST_TYPE,
				max( 0, $after ),
				self::BATCH
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
