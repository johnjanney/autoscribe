<?php
/**
 * Write-failure tests for paid usage and terminal state.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Cost\Pricing_Table;
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
 * Covers what happens when a database write fails after money has been spent.
 *
 * A provider that answers has charged for the answer. Whether the run log
 * accepts the counters afterwards is a separate question, and the two were not
 * connected: usage writes reported nothing, so a step whose usage write was
 * refused went on to finish, and the next queued action loaded a fresh run and
 * read the row. The charge simply vanished from the month-to-date total that
 * section 7.4's cap reads.
 *
 * The same was true of the terminal writes. A run could publish, fail to close,
 * and still send its review email and arm its next occurrence — then be swept
 * later and do both again.
 *
 * @since 1.1.1
 */
final class Write_FailureTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Gives the providers keys so runs reach their paid calls.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Key_Store::set( 'anthropic', 'test-key' );
	}

	/**
	 * Re-arms the tripwire between tests.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * A charge whose usage cannot be stored is still settled against the cap.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_a_charge_whose_usage_cannot_be_stored_is_still_settled(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$result = $this->with_refused( 'SET `text_model`', fn() => ( new Generator( new Provider_Registry() ) )->run( $prompt_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_usage_not_recorded', $result->get_error_code() );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertGreaterThan(
			0,
			(int) $row['cost_cents'],
			'The provider was paid, so the run must not settle to nothing.'
		);
	}

	/**
	 * A run that cannot be closed does not announce itself.
	 *
	 * Publishing is not the risky part — sending the review mail and arming the
	 * next occurrence off a transition that did not happen is, because the stall
	 * sweeper will later find the still-open row and do both again.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_a_run_that_cannot_be_closed_is_not_announced(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt( array( 'post_status_mode' => 'review' ) );
		$mailed    = 0;
		$count     = static function ( $args ) use ( &$mailed ) {
			++$mailed;

			return $args;
		};

		add_filter( 'wp_mail', $count );

		$result = $this->with_refused( "SET `status` = 'success'", fn() => ( new Generator( new Provider_Registry() ) )->run( $prompt_id ) );

		remove_filter( 'wp_mail', $count );

		$this->assertWPError( $result );
		$this->assertSame( 0, $mailed, 'Nothing should be announced for a run that did not close.' );
	}

	/**
	 * Only one worker performs a step, however many arrive for the same run.
	 *
	 * The per-step guards are reads: two workers can both find no stored article
	 * and both buy one. The claim is what excludes the second, and it has to do
	 * so before anything is spent.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_only_one_worker_performs_a_step(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();
		$handler   = new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		);

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// Past the budget check, so the contested step is a paid one.
		$handler->handle_step( $run_id );

		$this->assertSame( 'budget_check', (string) Run::latest_for_prompt( $prompt_id )['step'] );

		$run = Run::load( $run_id );

		// Another worker takes the claim on the topic step first.
		$this->assertTrue( $run->claim_step( 'budget_check' ), 'The first claim should succeed.' );
		$this->assertFalse( $run->claim_step( 'budget_check' ), 'The second claim on the same position must not.' );

		$calls = count( $this->captured_requests() );

		// The action that lost the claim finds the position taken and stands down.
		$handler->handle_step( $run_id );

		$this->assertCount(
			$calls,
			$this->captured_requests(),
			'A worker that did not win the claim must not reach a provider.'
		);
	}

	/**
	 * A run closed by something else is not closed a second time.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_a_closed_run_cannot_be_closed_again(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->fail( 'first' ) );
		$this->assertFalse( $run->succeed(), 'A finished run must not accept a second ending.' );
		$this->assertFalse( $run->fail( 'second' ) );

		$row = Run::latest_for_prompt( $run->prompt_id() );

		$this->assertSame( 'first', (string) $row['error'] );
	}

	/**
	 * Settlement reports a refused cost write rather than returning a figure.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_settlement_reports_a_refused_write(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run->record_text_usage( 'claude-opus-5', 1000, 2000 );

		$settled = $this->with_refused( 'SET `cost_cents`', fn() => $run->settle_cost( new Pricing_Table() ) );

		$this->assertWPError( $settled );
	}

	/**
	 * Runs a callback with matching UPDATE statements refused.
	 *
	 * @since 1.1.1
	 *
	 * @param string   $needle   Fragment identifying the write to refuse.
	 * @param callable $callback Work to run while it is refused.
	 * @return mixed
	 */
	private function with_refused( string $needle, callable $callback ) {
		global $wpdb;

		$break = static function ( $query ) use ( $needle ) {
			$sql = (string) $query;

			return str_contains( $sql, $needle ) && str_starts_with( ltrim( $sql ), 'UPDATE' )
				? 'UPDATE autoscribe_no_such_table SET id = 1 WHERE id = 1'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$result = $callback();

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		return $result;
	}
}
