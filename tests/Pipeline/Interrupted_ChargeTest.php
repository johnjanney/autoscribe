<?php
/**
 * Tests for money spent by a worker that never came back.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Stall_Sweeper;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers the cost floor a released claim leaves behind.
 *
 * A worker killed inside a paid call has been charged for it and has recorded
 * nothing. Version 1.2.0 kept the reservation when a sweep gave up on such a
 * run, which covered the run nobody minds losing and not the one everybody wants
 * to succeed: the restart. Settlement after a successful restart measured only
 * what the replacement spent, so the first call left the month-to-date total
 * that the section 7.4 cap reads.
 *
 * @since 1.3.0
 */
final class Interrupted_ChargeTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Gives the providers keys so runs reach their paid calls.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * Releasing a claim records that this run may have spent more than it shows.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_releasing_a_claim_records_a_cost_floor(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$this->assertTrue( $run->reserve_cost( 250 ) );
		$this->assertTrue( $run->claim_step( '' ) );
		$this->assertSame( 0, Run::load( $run_id )->cost_floor(), 'Nothing is floored until a claim is lost.' );

		$observed = Run::load( $run_id )->raw_step();

		$this->assertTrue( Run::load( $run_id )->release_claim( $observed ) );
		$this->assertSame(
			250,
			Run::load( $run_id )->cost_floor(),
			'The reservation standing when the claim was released is the floor.'
		);
	}

	/**
	 * A run that succeeds after a restart still settles at its floor.
	 *
	 * This is the sequence 1.2.0 left open: the interrupted call is invisible to
	 * everything that runs afterwards, so the only way it can be counted is for
	 * the release to record that it happened.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_successful_restart_cannot_settle_below_the_floor(): void {
		global $wpdb;

		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();
		$handler   = new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		);

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// Budget check, then the topic step: the first paid call.
		$handler->handle_step( $run_id );

		$reserved = (int) Run::latest_for_prompt( $prompt_id )['cost_cents'];

		// A worker claims the paid step, is charged, and never comes back.
		$killed = Run::load( $run_id );

		$this->assertTrue( $killed->claim_step( $killed->step() ) );

		$wpdb->update(
			Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);

		// The queue lost the action along with the worker, which is how a stalled
		// run is told apart from a slow one.
		( new Scheduler() )->cancel_step_actions( $run_id );

		$sweeper = new Stall_Sweeper( new Scheduler(), $handler );

		$this->assertTrue( $sweeper->recover( $run_id, Run::load( $run_id )->raw_step() ) );
		$this->assertSame( $reserved, Run::load( $run_id )->cost_floor() );

		// The replacement finishes the run normally.
		for ( $i = 0; $i < 8; $i++ ) {
			$row = Run::latest_for_prompt( $prompt_id );

			if ( Run::STATUS_RUNNING !== $row['status'] ) {
				break;
			}

			$handler->handle_step( $run_id );
		}

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_SUCCESS, $row['status'] );
		$this->assertGreaterThanOrEqual(
			$reserved,
			(int) $row['cost_cents'],
			'A run interrupted inside a paid call must not settle below what it had reserved.'
		);
	}

	/**
	 * Two workers on one run add their spending rather than overwriting it.
	 *
	 * The counters used to be read, added to in PHP, and written back whole, so a
	 * stale worker and its replacement each wrote a total computed before the
	 * other's — and one call's tokens disappeared from the figure the cap reads.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_two_workers_both_have_their_spending_counted(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$first  = Run::load( $run_id );
		$second = Run::load( $run_id );

		// Both read the row before either has written to it.
		$this->assertFalse( $first->has_usage() );
		$this->assertFalse( $second->has_usage() );

		$this->assertTrue( $first->record_text_usage( 'claude-opus-5', 100, 400 ) );
		$this->assertTrue( $second->record_text_usage( 'claude-opus-5', 100, 400 ) );

		$row = Run::latest_for_prompt( $run->prompt_id() );

		$this->assertSame( 200, (int) $row['input_tokens'] );
		$this->assertSame( 800, (int) $row['output_tokens'] );
	}

	/**
	 * Two images bought for one run are counted as two.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_two_images_are_counted_twice(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$this->assertTrue( Run::load( $run->id() )->record_image( 'gpt-image-2' ) );
		$this->assertTrue( Run::load( $run->id() )->record_image( 'gpt-image-2' ) );

		$row = Run::latest_for_prompt( $run->prompt_id() );

		$this->assertSame( 2, (int) $row['image_count'], 'A picture billed twice is not one picture.' );
	}
}
