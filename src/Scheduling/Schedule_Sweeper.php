<?php
/**
 * Recovery for prompts that have fallen out of the queue.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Scheduling;

use AutoScribe\Pipeline\Run;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Prompts\Prompt_Post_Type;
use WP_Query;

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
	 * Most prompts one pass will look at.
	 *
	 * The row count here is small by design — section 3.2 chose a post type for
	 * prompts precisely because there are never many — so this is a bound against
	 * pathology rather than a paging scheme.
	 *
	 * @since 1.10.0
	 * @var int
	 */
	public const BATCH = 200;

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
		$armed = 0;

		foreach ( $this->enabled_prompts() as $prompt_id ) {
			if ( $this->rearm( $prompt_id ) ) {
				++$armed;
			}
		}

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

		if ( Run::has_open_run( $prompt_id ) ) {
			/*
			 * A run is in flight. Its next occurrence is armed when it concludes,
			 * and arming one now would put a second article beside the first — the
			 * stall sweep is what recovers a run that never concludes.
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
	 * @return int[]
	 */
	private function enabled_prompts(): array {
		$query = new WP_Query(
			array(
				'post_type'              => Prompt_Post_Type::POST_TYPE,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => self::BATCH,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One indexed meta_key over a table section 3.2 keeps small.
					array(
						'key'     => '_autoscribe_enabled',
						'value'   => '1',
						'compare' => '=',
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}
}
