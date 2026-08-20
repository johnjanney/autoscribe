<?php
/**
 * Tests for the Run Log's stalled-queue warning.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Admin;

use ActionScheduler_QueueRunner;
use AutoScribe\Activation;
use AutoScribe\Admin\Queue_Health;
use AutoScribe\Pipeline\Run;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * A waiting run and a queue nobody is running.
 *
 * The warning has to be quiet almost all the time or it teaches people to
 * ignore it, so both halves are tested in both directions: something waiting
 * with a queue that is working says nothing, and a quiet queue with nothing
 * waiting says nothing either.
 *
 * @since 1.13.2
 */
final class Queue_HealthTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Nothing is said when no run is waiting.
	 *
	 * @since 1.13.2
	 *
	 * @return void
	 */
	public function test_a_quiet_queue_with_nothing_waiting_says_nothing(): void {
		$this->assertNull( $this->check()->stall_warning() );
	}

	/**
	 * Nothing is said about a run that has only just started.
	 *
	 * @since 1.13.2
	 *
	 * @return void
	 */
	public function test_a_run_that_just_started_says_nothing(): void {
		$this->assertNotWPError( Run::start( $this->create_prompt() ) );

		$this->assertNull(
			$this->check()->stall_warning(),
			'A run in its first minutes is a run, not a stall.'
		);
	}

	/**
	 * A run left waiting on a queue that has never run is reported.
	 *
	 * @since 1.13.2
	 *
	 * @return void
	 */
	public function test_a_waiting_run_on_a_dead_queue_is_reported(): void {
		$this->waiting_run();

		$warning = $this->check()->stall_warning();

		$this->assertIsString( $warning );
		$this->assertStringContainsString( 'never finished anything', $warning );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $warning, 'And says what to do about it.' );
	}

	/**
	 * Nothing is said while the queue is still finishing actions.
	 *
	 * A run that is old on a working queue is the stall sweeper's business, and
	 * telling somebody to go and edit wp-config.php about it would be wrong.
	 *
	 * @since 1.13.2
	 *
	 * @return void
	 */
	public function test_a_working_queue_says_nothing(): void {
		$this->waiting_run();

		as_schedule_single_action( time() - 1, 'autoscribe_tests_noop', array(), Scheduler::GROUP );
		add_action( 'autoscribe_tests_noop', '__return_true' );

		ActionScheduler_QueueRunner::instance()->run( 'AutoScribe tests' );

		remove_action( 'autoscribe_tests_noop', '__return_true' );

		$this->assertNotNull( ( new Scheduler() )->last_processed(), 'The queue really did finish something.' );
		$this->assertNull( $this->check()->stall_warning() );
	}

	/**
	 * Opens a run and backdates it past the quiet threshold.
	 *
	 * @since 1.13.2
	 *
	 * @return int Run ID.
	 */
	private function waiting_run(): int {
		global $wpdb;

		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$wpdb->update(
			Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * Queue_Health::QUIET_SECONDS ) ) ),
			array( 'id' => $run->id() ),
			array( '%s' ),
			array( '%d' )
		);

		return $run->id();
	}

	/**
	 * Builds the check.
	 *
	 * @since 1.13.2
	 *
	 * @return Queue_Health
	 */
	private function check(): Queue_Health {
		return new Queue_Health( new Scheduler() );
	}
}
