<?php
/**
 * Tests for what a run does when it cannot record that it ended.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Pipeline\Close_Result;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use AutoScribe\Tests\Support\Refuses_Writes;
use WP_UnitTestCase;

/**
 * Covers the three ways a terminal transition can turn out.
 *
 * Closing a run answered a Boolean until 1.2.0, and false meant two unrelated
 * things: somebody else closed it, or the write was refused. The queue treated
 * both as "the run ended" — so a database fault mailed a failure, armed the next
 * occurrence, and left an open row whose in-memory usage vanished at the end of
 * the request. A later sweep then settled that row from the counters that had
 * reached it, and a paid call disappeared from the month's total.
 *
 * These tests drive each ending with the write refused and assert the opposite:
 * nothing announced, nothing armed, the run left recoverable, and the money
 * still counted.
 *
 * @since 1.2.0
 */
final class Terminal_StateTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;
	use Refuses_Writes;

	/**
	 * Gives the providers keys so runs reach their paid calls.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * A paid call survives both its usage write and the terminal write failing.
	 *
	 * This is the sequence the 1.1.x fix did not cover. The provider answers and
	 * charges, the usage write is refused, the step reports it, and the close that
	 * follows is refused as well. The run is then still open with counters that do
	 * not include the charge — so it must not be settled from them, and the
	 * reservation has to stand until the accounting is known to be complete.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_charge_survives_both_writes_failing(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();
		$mailed    = 0;
		$count     = static function ( $args ) use ( &$mailed ) {
			// The operational alert is a separate message with its own subject;
			// what must not happen is the run being announced as finished.
			if ( str_contains( (string) $args['subject'], 'run failed' ) ) {
				++$mailed;
			}

			return $args;
		};

		add_filter( 'wp_mail', $count );

		$handler = $this->handler();

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// The budget check reserves; the topic call is the first paid one.
		$handler->handle_step( $run_id );

		$reserved = (int) Run::latest_for_prompt( $prompt_id )['cost_cents'];

		$this->assertGreaterThan( 0, $reserved, 'The run must hold a reservation before the paid step.' );

		$this->with_all_refused(
			array( 'input_tokens = input_tokens +', "SET `status` = 'failed'", "SET status = 'failed'" ),
			static function () use ( $handler, $run_id ) {
				$handler->handle_step( $run_id );
			}
		);

		remove_filter( 'wp_mail', $count );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame(
			Run::STATUS_RUNNING,
			$row['status'],
			'A refused close leaves the run open for the sweep, rather than pretending it ended.'
		);
		$this->assertSame(
			$reserved,
			(int) $row['cost_cents'],
			'The reservation must stand while a charge is known to be unrecorded.'
		);
		$this->assertSame(
			0,
			(int) get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true ),
			'Nothing may be retried off a transition that did not happen.'
		);
		$this->assertSame( 0, $mailed, 'A run that did not close announces nothing.' );
	}

	/**
	 * A refused close does not arm the next occurrence.
	 *
	 * Section 4.3 arms the next occurrence at the end of a run. The end of a run
	 * is the transition, not the intention to make one: arming here and again when
	 * the sweep finally closes the row is how one prompt acquires two schedules.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_refused_close_does_not_arm_the_next_occurrence(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$handler = $this->handler();

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$handler->handle_step( $run_id );

		// Disabling the prompt mid-chain is one of the queued endings.
		update_post_meta( $prompt_id, '_autoscribe_enabled', 0 );

		$this->with_all_refused(
			array( "SET `status` = 'failed'", "SET status = 'failed'" ),
			static function () use ( $handler, $run_id ) {
				$handler->handle_step( $run_id );
			}
		);

		$this->assertSame(
			Run::STATUS_RUNNING,
			Run::latest_for_prompt( $prompt_id )['status'],
			'The run stays open when its terminal write is refused.'
		);
		$this->assertSame(
			0,
			Prompt::load( $prompt_id )->next_run_ts(),
			'Nothing should be armed off a close that did not happen.'
		);
	}

	/**
	 * A refused close is reported, because nothing else would report it.
	 *
	 * The run is deliberately left open, so there is no failure notice and no
	 * closed row saying anything is wrong. Without this the only symptom of a
	 * runs table that will not take writes is that scheduled posts quietly stop
	 * appearing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_refused_close_is_reported_once(): void {
		$this->mock_provider_success();

		delete_transient( Queued_Run_Handler::WRITE_FAILURE_NOTICE );
		\AutoScribe\Admin\Settings::save( array( 'notification_email' => 'editor@example.com' ) );

		$subjects = array();
		$collect  = static function ( $args ) use ( &$subjects ) {
			$subjects[] = (string) $args['subject'];

			return $args;
		};

		add_filter( 'wp_mail', $collect );

		$prompt_id = $this->create_prompt();
		$handler   = $this->handler();

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$handler->handle_step( $run_id );

		$this->with_all_refused(
			array( 'input_tokens = input_tokens +', "SET `status` = 'failed'", "SET status = 'failed'" ),
			static function () use ( $handler, $run_id ) {
				$handler->handle_step( $run_id );
				// A second pass meets the same fault and must not mail again.
				$handler->handle_step( $run_id );
			}
		);

		remove_filter( 'wp_mail', $collect );
		delete_transient( Queued_Run_Handler::WRITE_FAILURE_NOTICE );

		$this->assertCount( 1, $subjects, 'One alert, however often the fault repeats.' );
		$this->assertStringContainsString( 'could not record', $subjects[0] );
	}

	/**
	 * Two finalisers publish the post once between them.
	 *
	 * Finalisation was the one part of a run without a claim. Two actions could
	 * both transition the post and both settle the cost, and only then would one
	 * lose the close race — so nothing was charged twice, but every plugin
	 * listening for a publish ran twice and the losing cost write could land last.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_only_one_finaliser_publishes_the_post(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();
		$handler   = $this->handler();

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// Every step, stopping short of the action that finalises.
		for ( $i = 0; $i < 5; $i++ ) {
			$handler->handle_step( $run_id );
		}

		$this->assertSame(
			'generate_image',
			Run::load( $run_id )->step(),
			'The run should be sitting at the end of its steps.'
		);

		$published = 0;
		$watch     = static function ( $to, $from ) use ( &$published ) {
			if ( 'publish' === $to && 'publish' !== $from ) {
				++$published;
			}
		};

		add_action( 'transition_post_status', $watch, 10, 2 );

		$handler->handle_step( $run_id );
		$handler->handle_step( $run_id );

		remove_action( 'transition_post_status', $watch, 10 );

		$this->assertSame( Run::STATUS_SUCCESS, Run::latest_for_prompt( $prompt_id )['status'] );
		$this->assertSame( 1, $published, 'The post transitions to published exactly once.' );
	}

	/**
	 * A second finaliser arriving mid-flight is told it lost, not that it failed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_second_finaliser_stands_down(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		// Somebody else is already finalising: the position is claimed.
		$this->assertTrue( Run::load( $run->id() )->claim_step( '' ) );

		$generator = new Generator( new Provider_Registry() );
		$prompt    = Prompt::load( $run->prompt_id() );
		$article   = ( new \AutoScribe\Content\Article_Validator() )->from_array( $this->article_payload() );

		$this->assertNotWPError( $article );

		$result = $generator->finalise( $prompt, Run::load( $run->id() ), $article, null, null, 0 );

		$this->assertWPError( $result );
		$this->assertSame( Generator::CLOSE_RACE_LOST, $result->get_error_code() );
		$this->assertSame( Close_Result::Already_Closed, Close_Result::from_error( $result ) );
	}

	/**
	 * Builds the queued handler under test.
	 *
	 * @since 1.2.0
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
}
