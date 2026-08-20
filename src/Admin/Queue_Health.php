<?php
/**
 * Warning for a run that is waiting on a queue nobody is running.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Pipeline\Run;
use AutoScribe\Scheduling\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Says when a run has stopped because the queue stopped.
 *
 * Section 5 splits a run into separate queued actions, so a run is not one long
 * request but five short ones, and every one of them needs the queue to fire
 * again. On a site with a system cron that is a few minutes end to end. On a
 * site relying on WP-Cron, the queue only fires when somebody loads a page — so
 * a run advances while an administrator is clicking around the admin and stops
 * the moment they stop, which looks exactly like a plugin that hangs.
 *
 * The Settings screen has said all this since 0.7.0 under System health. Nobody
 * goes to Settings to find out why the Run Log says "running": they look at the
 * Run Log, which said nothing at all. This puts the answer on the screen where
 * the question is asked, and only when both halves are true — something is
 * waiting, and nothing has run it.
 *
 * @since 1.13.2
 */
final class Queue_Health {

	/**
	 * How quiet the queue must be before a waiting run is worth a warning.
	 *
	 * A run's own steps are short, and the recommended cron fires every minute,
	 * so five minutes of nothing is well outside the normal shape of a healthy
	 * run and short enough to be seen while the person is still looking.
	 *
	 * @since 1.13.2
	 * @var int
	 */
	public const QUIET_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Queue wrapper.
	 *
	 * @since 1.13.2
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Builds the check.
	 *
	 * @since 1.13.2
	 *
	 * @param Scheduler $scheduler Queue wrapper.
	 */
	public function __construct( Scheduler $scheduler ) {
		$this->scheduler = $scheduler;
	}

	/**
	 * Returns the warning to show, or null when there is nothing to say.
	 *
	 * Both halves are required. An old open run on a working queue is the stall
	 * sweeper's business, and it will restart or close it without anybody being
	 * told to go and edit wp-config.php. A quiet queue with nothing waiting on it
	 * is a quiet site, which is not a fault.
	 *
	 * @since 1.13.2
	 *
	 * @return string|null
	 */
	public function stall_warning(): ?string {
		if ( ! $this->scheduler->is_available() ) {
			// Action Scheduler is missing entirely, which System health reports
			// in the terms that failure actually deserves.
			return null;
		}

		$waiting = Run::open_before( gmdate( 'Y-m-d H:i:s', time() - self::QUIET_SECONDS ), 1 );

		if ( array() === $waiting ) {
			return null;
		}

		$last = $this->scheduler->last_processed();

		if ( null !== $last && ( time() - $last ) < self::QUIET_SECONDS ) {
			return null;
		}

		$advice = __( 'Each step of a run needs the queue to fire again, and WP-Cron only fires when somebody loads a page. Set DISABLE_WP_CRON in wp-config.php and add a system cron entry that requests wp-cron.php every minute. AutoScribe → Settings shows the current state under System health.', 'autoscribe' );

		if ( null === $last ) {
			return __( 'A run has been in progress for several minutes and the queue has never finished anything.', 'autoscribe' ) . ' ' . $advice;
		}

		return sprintf(
			/* translators: %s: human-readable interval, such as "20 mins". */
			__( 'A run has been in progress for several minutes and the queue has not finished anything for %s.', 'autoscribe' ),
			human_time_diff( $last )
		) . ' ' . $advice;
	}
}
