<?php
/**
 * Queue wrapper tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Scheduling;

use AutoScribe\Activation;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Scheduling\Next_Run_Calculator;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers what the queue wrapper does when Action Scheduler refuses a job.
 *
 * `as_schedule_single_action()` returns the new action's ID, and 0 when it could
 * not create one. Every arming call in Scheduler discarded that, and each
 * discarded it into a different silence: a failed step left a run in `running`
 * with no next action and its budget reservation held indefinitely, a failed
 * retry dropped the attempt, and a failed re-arm stopped the prompt for ever —
 * which is the one outcome section 4.3 exists to prevent.
 *
 * Only the step case was reported. The other two are the same fault in the same
 * class, and are covered here because a caller that cannot tell success from
 * failure is the defect, not the particular call site.
 *
 * @since 1.1.0
 */
final class SchedulerTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * The filter refusing queue writes, so it can be removed cleanly.
	 *
	 * @since 1.1.0
	 * @var callable|null
	 */
	private $refusal = null;

	/**
	 * Removes the refusal filter.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->stop_refusing();

		parent::tear_down();
	}

	/**
	 * A refused step action is reported rather than assumed to have worked.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_refused_step_action_is_reported(): void {
		$this->refuse_queue_writes();

		$result = ( new Scheduler() )->schedule_step( 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_action_not_scheduled', $result->get_error_code() );
	}

	/**
	 * A refused retry is reported.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_refused_retry_is_reported(): void {
		$this->refuse_queue_writes();

		$this->assertWPError( ( new Scheduler() )->schedule_retry( $this->create_prompt(), 60 ) );
	}

	/**
	 * A refused re-arm is reported.
	 *
	 * The quietest of the three: without this, a prompt whose next occurrence
	 * could not be armed simply never runs again, and nothing anywhere says so.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_refused_rearm_is_reported(): void {
		$prompt = Prompt::load(
			$this->create_prompt(
				array(
					'schedule_type'   => 'daily',
					'schedule_params' => array( 'time' => '06:00' ),
				)
			)
		);

		$this->refuse_queue_writes();

		$this->assertWPError( ( new Scheduler() )->rearm( $prompt->id(), $prompt->schedule() ) );
	}

	/**
	 * An accepted action still reports success.
	 *
	 * The counterpart the other three need: a check that turned every arming
	 * call into an error would also pass them.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_an_accepted_action_reports_success(): void {
		$this->assertTrue( ( new Scheduler() )->schedule_step( 1 ) );
	}

	/**
	 * Deactivation clears both kinds of action.
	 *
	 * Writing this turned up that it never cleared either: the call passed a hook
	 * and a group with empty args, which makes Action Scheduler match only
	 * actions whose args are exactly empty, and every action this plugin arms
	 * carries a prompt or run ID. Deactivating left the whole queue armed.
	 *
	 * Step actions are keyed by run rather than by prompt, so nothing else
	 * clears them. Left behind they either strand their runs — Action Scheduler
	 * can be supplied by another active plugin and go on consuming actions whose
	 * callback this plugin no longer registers — or resume a half-finished run
	 * when the plugin is switched back on.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_deactivation_clears_step_actions(): void {
		$scheduler = new Scheduler();

		$scheduler->schedule_step( 4242 );
		$scheduler->schedule_retry( $this->create_prompt(), 3600 );

		$this->assertNotEmpty(
			as_get_scheduled_actions( array( 'hook' => Scheduler::HOOK_RUN_STEP ), 'ids' ),
			'The step action should exist before deactivation.'
		);

		Activation::deactivate();

		foreach ( array( Scheduler::HOOK_RUN_STEP, Scheduler::HOOK_RUN_PROMPT ) as $hook ) {
			$this->assertSame(
				array(),
				as_get_scheduled_actions(
					array(
						'hook'   => $hook,
						'status' => \ActionScheduler_Store::STATUS_PENDING,
					),
					'ids'
				),
				$hook . ' should have no pending actions after deactivation.'
			);
		}
	}

	/**
	 * Makes Action Scheduler refuse to write new actions.
	 *
	 * Action Scheduler documents this filter as the way to short-circuit
	 * enqueuing, with zero meaning the action was not created. That is exactly
	 * the value the wrapper used to discard, so refusing here reproduces a
	 * queue-table write failure without breaking the table the rest of the suite
	 * shares.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function refuse_queue_writes(): void {
		$this->refusal = array( $this, 'refuse' );

		add_filter( 'pre_as_schedule_single_action', $this->refusal, 10, 1 );
	}

	/**
	 * Answers the pre-schedule filter with a refusal.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $action The action about to be stored.
	 * @return mixed
	 */
	public function refuse( $action ) {
		unset( $action );

		return 0;
	}

	/**
	 * Stops refusing queue writes.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function stop_refusing(): void {
		if ( null !== $this->refusal ) {
			remove_filter( 'pre_as_schedule_single_action', $this->refusal, 10 );

			$this->refusal = null;
		}
	}
	/**
	 * A re-arm uses the schedule the prompt holds, not the caller's copy of it.
	 *
	 * Both callers resolve a schedule and then call: a prompt save, and a run
	 * finishing. A run that finishes seconds after a save carries the schedule
	 * that save replaced, and until 1.13.4 whichever of the two got the lock
	 * second found an occurrence already queued and left it alone — so the queue
	 * could hold a time computed from a schedule nobody had asked for since.
	 *
	 * @since 1.13.4
	 *
	 * @return void
	 */
	public function test_a_rearm_uses_the_persisted_schedule_rather_than_the_callers(): void {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$stale = Prompt::load( $prompt_id )->schedule();

		$this->assertNotWPError( $stale );

		// The prompt is saved with a different time.
		update_post_meta( $prompt_id, '_autoscribe_schedule_params', array( 'time' => '18:00' ) );

		$scheduler = new Scheduler();
		$armed     = $scheduler->rearm( $prompt_id, $stale );

		$this->assertIsInt( $armed );

		$expected = ( new Next_Run_Calculator() )->next( Prompt::load( $prompt_id )->schedule() );

		$this->assertNotWPError( $expected );
		$this->assertSame(
			$expected->getTimestamp(),
			$armed,
			'The occurrence armed is the one the prompt now asks for.'
		);
		$this->assertSame( $armed, $scheduler->next_scheduled( $prompt_id ) );
	}
}
