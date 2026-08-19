<?php
/**
 * Queued run and retry tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Pipeline;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers what happens when the queue executes a prompt.
 *
 * This is the path everything scheduled takes, and until now nothing asserted
 * it ran at all. The Run now control added in section 9.2 enqueues exactly this
 * action, so these tests cover both.
 *
 * @since 0.8.0
 */
final class Queued_RunTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Provides the API key the pipeline needs.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Key_Store::set( 'anthropic', 'test-key' );
	}

	/**
	 * Removes the mock so the tripwire is armed again.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * Builds the handler under test.
	 *
	 * @since 0.8.0
	 *
	 * @return Queued_Run_Handler
	 */
	private function handler(): Queued_Run_Handler {
		return new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		);
	}

	/**
	 * Runs a prompt the way the queue does: one action per step.
	 *
	 * The handler no longer completes a run: it opens one and arms the first
	 * step, and each step arms the next. Calling the handler once and asserting on the
	 * outcome would have tested the first half of an action chain and called it
	 * the whole thing.
	 *
	 * Driving it here rather than through Action Scheduler keeps the tests off
	 * the queue's own scheduling, which is still the coverage gap the README
	 * records. What it does test, which nothing did before, is that a run really
	 * can be picked up from its row by an action that shares no memory with the
	 * one before it.
	 *
	 * @since 1.1.0
	 *
	 * @param int $prompt_id Prompt to run.
	 * @return void
	 */
	private function run_to_completion( int $prompt_id ): void {
		$this->handler()->handle( $prompt_id );

		$row = Run::latest_for_prompt( $prompt_id );

		if ( ! is_array( $row ) ) {
			return;
		}

		$run_id = (int) $row['id'];

		/*
		 * One pass per step, one for the action that finds none left and
		 * publishes, and one more to observe the terminal state. A chain that has
		 * not finished by then is a defect, and the assertion below says so.
		 */
		$passes = count( Pipeline::STEPS ) + 2;

		for ( $i = 0; $i < $passes; $i++ ) {
			$current = Run::latest_for_prompt( $prompt_id );

			if ( ! is_array( $current ) || Run::STATUS_RUNNING !== $current['status'] ) {
				return;
			}

			// A retry opens a new run, so always advance the newest one.
			$this->handler()->handle_step( (int) $current['id'] );
		}

		$this->fail( 'The action chain for run ' . $run_id . ' did not reach a terminal state.' );
	}

	/**
	 * Executing the queued action runs the pipeline and records a run row.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_executing_the_action_runs_the_pipeline(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->run_to_completion( $prompt_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_SUCCESS, $row['status'] );
		$this->assertSame( 'Water Hardness And Extraction', $row['title'] );
		$this->assertGreaterThan( 0, (int) $row['post_id'] );

		// The post really exists and carries the run link from section 10.
		$this->assertSame(
			(string) $row['id'],
			(string) get_post_meta( (int) $row['post_id'], \AutoScribe\Pipeline\Step_Assemble_Post::RUN_ID_META, true )
		);
	}

	/**
	 * The opening action opens a run and stops, without spending anything.
	 *
	 * This is the behaviour change section 5 asks for. No mock is installed, so
	 * the bootstrap tripwire throws on any provider call — reaching the assertion
	 * is itself the proof that opening a run costs nothing.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_first_action_only_opens_the_run(): void {
		$prompt_id = $this->create_prompt();

		$this->handler()->handle( $prompt_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_RUNNING, $row['status'] );
		$this->assertSame( '', (string) $row['step'], 'No step should have run yet.' );
	}

	/**
	 * Each action advances the run by exactly one step.
	 *
	 * The point of the split: a host that kills a request now loses one provider
	 * call rather than a whole article. Each action gets a fresh handler, so
	 * nothing carries over in memory between them — everything a step needs has
	 * to come off the run row.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_each_action_advances_exactly_one_step(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->handler()->handle( $prompt_id );

		$run_id   = (int) Run::latest_for_prompt( $prompt_id )['id'];
		$observed = array();

		foreach ( Pipeline::STEPS as $expected ) {
			$this->handler()->handle_step( $run_id );

			$observed[] = (string) Run::latest_for_prompt( $prompt_id )['step'];
		}

		$this->assertSame( Pipeline::STEPS, $observed );

		// The run is still open: publishing is the next action's work.
		$this->assertSame( Run::STATUS_RUNNING, Run::latest_for_prompt( $prompt_id )['status'] );

		$this->handler()->handle_step( $run_id );

		$this->assertSame( Run::STATUS_SUCCESS, Run::latest_for_prompt( $prompt_id )['status'] );
	}

	/**
	 * A prompt disabled part-way through its chain stops the run.
	 *
	 * Scheduler::cancel() cannot reach these actions, because they are keyed by
	 * run rather than by prompt. Without the check in the step handler, turning a
	 * prompt off would stop the next occurrence and let the run in flight carry
	 * on spending.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_disabling_a_prompt_stops_a_run_already_in_flight(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$this->handler()->handle_step( $run_id );

		update_post_meta( $prompt_id, '_autoscribe_enabled', 0 );

		$this->handler()->handle_step( $run_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'budget_check', (string) $row['step'], 'The chain should stop where it was.' );
	}

	/**
	 * An action for a run that no longer exists does nothing.
	 *
	 * The retention job prunes old rows, and an action can outlive the run it
	 * was armed for.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_an_action_for_a_missing_run_is_ignored(): void {
		// No mock: a provider call would throw.
		$this->handler()->handle_step( 999999 );

		$this->assertTrue( true );
	}

	/**
	 * A disabled prompt is not run at all.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_disabled_prompt_is_not_run(): void {
		$prompt_id = $this->create_prompt( array( 'enabled' => 0 ) );

		// No mock is installed. If the handler reached a provider the tripwire
		// would throw, so reaching the assertion is itself part of the proof.
		$this->handler()->handle( $prompt_id );

		$this->assertNull( Run::latest_for_prompt( $prompt_id ) );
	}

	/**
	 * A transient failure schedules a retry and counts the attempt.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_transient_failure_schedules_a_retry(): void {
		$this->mock_provider_failure( 503 );

		$prompt_id = $this->create_prompt();

		$this->run_to_completion( $prompt_id );

		$this->assertSame(
			2,
			(int) get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true ),
			'the failed attempt was not counted'
		);

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
	}

	/**
	 * The retry delay follows the policy's backoff.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_the_retry_delay_follows_the_policy(): void {
		$policy = new Retry_Policy();

		$this->assertSame( 5 * MINUTE_IN_SECONDS, $policy->delay_seconds( 1 ) );
		$this->assertSame( 30 * MINUTE_IN_SECONDS, $policy->delay_seconds( 2 ) );
		$this->assertSame( HOUR_IN_SECONDS, $policy->delay_seconds( 3 ) );
	}

	/**
	 * Retrying stops after three attempts.
	 *
	 * Section 5 caps attempts at three. Without the cap a provider outage would
	 * requeue the same prompt indefinitely, and every attempt costs money.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_retrying_stops_after_three_attempts(): void {
		$this->mock_provider_failure( 503 );

		$prompt_id = $this->create_prompt();

		update_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, 3 );

		$this->run_to_completion( $prompt_id );

		$this->assertSame(
			'',
			get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true ),
			'the attempt counter should be cleared once retrying stops'
		);
	}

	/**
	 * A permanent failure is not retried at all.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_permanent_failure_is_not_retried(): void {
		$this->mock_provider_failure( 401 );

		$prompt_id = $this->create_prompt();

		$this->run_to_completion( $prompt_id );

		$this->assertSame(
			'',
			get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true ),
			'an authentication failure will not succeed on a retry'
		);
	}

	/**
	 * A successful run clears any outstanding attempt counter.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_successful_run_clears_the_attempt_counter(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		update_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, 2 );

		$this->run_to_completion( $prompt_id );

		$this->assertSame( '', get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true ) );
	}
}
