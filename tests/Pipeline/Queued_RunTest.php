<?php
/**
 * Queued run and retry tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Pipeline\Generator;
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
	 * Executing the queued action runs the pipeline and records a run row.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_executing_the_action_runs_the_pipeline(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->handler()->handle( $prompt_id );

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

		$this->handler()->handle( $prompt_id );

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

		$this->handler()->handle( $prompt_id );

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

		$this->handler()->handle( $prompt_id );

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

		$this->handler()->handle( $prompt_id );

		$this->assertSame( '', get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true ) );
	}
}
