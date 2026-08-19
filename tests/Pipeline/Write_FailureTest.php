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
use AutoScribe\Pipeline\Stall_Sweeper;
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
		$handler   = $this->handler();

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
	 * A worker that loses the claim stands down; it does not finish the run.
	 *
	 * "No steps remain" and "somebody else is doing this step" are different
	 * things, and returning the same value for both made the loser finish the
	 * run: early on it closed a run with no article, and at the image step it
	 * could publish before the winner had attached the picture.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_a_worker_that_loses_the_claim_does_not_finish_the_run(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();
		$handler   = $this->handler();

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// Somebody else takes the first step's claim.
		$this->assertTrue( Run::load( $run_id )->claim_step( '' ) );

		$handler->handle_step( $run_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame(
			Run::STATUS_RUNNING,
			$row['status'],
			'The loser must leave the run to the worker holding the claim.'
		);
		$this->assertSame( 0, (int) $row['post_id'] );
	}

	/**
	 * A step abandoned mid-claim can be recovered.
	 *
	 * A worker killed after claiming leaves the claim marker behind. The sweeper
	 * arms another action, and that worker reads the position with the marker
	 * stripped — so it asked to claim a value the column no longer held, and
	 * failed every time. Every recovery lost its claim, which meant a run
	 * interrupted at any point after claiming could never resume and was given up
	 * on instead: the sweeper defeated by the guard.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_a_step_abandoned_mid_claim_can_be_recovered(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();
		$handler   = $this->handler();

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$handler->handle_step( $run_id );

		$this->assertSame( 'budget_check', (string) Run::latest_for_prompt( $prompt_id )['step'] );

		// A worker claims the next step and is then killed.
		$this->assertTrue( Run::load( $run_id )->claim_step( 'budget_check' ) );

		/*
		 * Drive the sweep rather than calling the release directly: the property
		 * under test is that a stalled run recovers, not that a method exists.
		 * Calling it directly passes whether or not the sweeper uses it, which is
		 * what the first version of this test did.
		 */
		global $wpdb;

		$wpdb->update(
			\AutoScribe\Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);

		( new Scheduler() )->cancel_step_actions( $run_id );

		$this->assertSame( 1, ( new Stall_Sweeper( new Scheduler(), $handler ) )->handle() );

		$handler->handle_step( $run_id );

		$this->assertSame(
			'propose_topic',
			(string) Run::latest_for_prompt( $prompt_id )['step'],
			'A recovered run has to be able to take its own claim again.'
		);
	}

	/**
	 * A refused featured-image write is handled, not fatal.
	 *
	 * The error was built correctly and then overwritten by the attachment ID a
	 * line later, so the mode handling below it called get_error_code() on an
	 * integer. Required mode could not fail, fallback mode could not fall back,
	 * and optional mode could not shrug: all three fatalled instead.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_a_refused_featured_image_write_is_handled(): void {
		$this->mock_text_and_image_success();

		$prompt_id = $this->create_prompt(
			array(
				'image_mode'     => 'required',
				'image_provider' => 'openai_image',
				'image_model'    => 'gpt-image-2',
			)
		);

		Key_Store::set( 'openai_image', 'test-key' );

		// Refuse the thumbnail write, so the attachment exists but never attaches.
		$refuse = static function ( $check, $object_id, $meta_key ) {
			unset( $object_id );

			return '_thumbnail_id' === $meta_key ? false : $check;
		};

		add_filter( 'update_post_metadata', $refuse, 10, 3 );
		add_filter( 'add_post_metadata', $refuse, 10, 3 );

		$result = ( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		remove_filter( 'update_post_metadata', $refuse, 10 );
		remove_filter( 'add_post_metadata', $refuse, 10 );

		$this->assertWPError( $result, 'Required mode must fail rather than fatal.' );
		$this->assertSame( 'autoscribe_thumbnail_not_set', $result->get_error_code() );
	}

	/**
	 * Toggling force review mid-run does not stop the run.
	 *
	 * Two guards were fighting: putting force review in the abort fingerprint
	 * failed the run whenever the switch moved in either direction, so the rule
	 * that keeps the stricter of the opening and closing settings could never be
	 * reached — and tightening a safety catch would have killed the run it was
	 * meant to protect.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_toggling_force_review_mid_run_holds_the_post_as_a_draft(): void {
		$this->mock_provider_success();

		update_option( 'autoscribe_settings', array( 'force_review' => true ) );

		$prompt_id = $this->create_prompt( array( 'post_status_mode' => 'auto' ) );
		$handler   = $this->handler();

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$handler->handle_step( $run_id );

		// Switched off part-way through, after the run began under review.
		update_option( 'autoscribe_settings', array( 'force_review' => false ) );

		for ( $i = 0; $i < 6; $i++ ) {
			$handler->handle_step( $run_id );
		}

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_SUCCESS, $row['status'], 'The run should finish, not be aborted.' );
		$this->assertSame(
			'draft',
			get_post_status( (int) $row['post_id'] ),
			'A run that began under review must not publish because the switch moved.'
		);
	}

	/**
	 * Answers text and image calls with valid payloads.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	private function mock_text_and_image_success(): void {
		$article  = $this->article_payload();
		$proposal = array(
			'title'     => (string) $article['title'],
			'topic_key' => (string) $article['topic_key'],
		);

		$this->install_responder(
			function ( $args ) use ( $article, $proposal ) {
				$body = json_decode( (string) $args['body'], true );

				if ( ! isset( $body['messages'] ) ) {
					return array(
						'headers'  => array(),
						'body'     => (string) wp_json_encode(
							array(
								'data' => array(
									array( 'b64_json' => base64_encode( (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
								),
							)
						),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}

				$payload = ( isset( $body['max_tokens'] ) && 512 === (int) $body['max_tokens'] )
					? $proposal
					: $article;

				return $this->anthropic_response( $payload );
			}
		);
	}

	/**
	 * A second sweeper cannot release a claim taken since it looked.
	 *
	 * Two sweeps can overlap. The first releases an abandoned claim and arms a
	 * restart; that restart claims the step; and the second sweeper — still
	 * acting on what it saw — releases the *live* claim, letting a third worker
	 * perform the same paid step beside the one already doing it. It matched
	 * because a released and retaken claim produced an identical marker.
	 *
	 * Two changes address it: each claim now carries a token, so no two claims
	 * are the same string, and the sweeper re-asks whether anything is queued
	 * immediately before releasing rather than trusting the page scan.
	 *
	 * This test asserts the outcome — a live claim survives a concurrent sweep —
	 * and does not isolate the second guard. Reaching it needs an action armed
	 * between one sweep's page scan and its release, which two sweeps in one
	 * process cannot interleave. Removing the re-check leaves this test passing;
	 * it is defence against an ordering the suite cannot stage, and is documented
	 * as such rather than claimed as covered.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_a_stale_sweeper_cannot_release_a_fresh_claim(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->claim_step( '' ) );

		$run_id = $run->id();

		global $wpdb;

		$wpdb->update(
			\AutoScribe\Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);

		$scheduler = new Scheduler();
		$sweeper   = new Stall_Sweeper( $scheduler, $this->handler() );

		// The first sweep releases the abandoned claim and arms a restart.
		$this->assertSame( 1, $sweeper->handle() );

		$held = Run::load( $run_id )->raw_step();

		// That restart takes the step.
		$this->assertTrue( Run::load( $run_id )->claim_step( '' ) );

		$live = Run::load( $run_id )->raw_step();

		$this->assertNotSame( $held, $live, 'Each claim should be distinguishable from the last.' );

		// A second sweep, still acting on what it saw, must leave it alone.
		$sweeper->handle();

		$this->assertSame(
			$live,
			Run::load( $run_id )->raw_step(),
			'A concurrent sweep must not free the claim a live worker is holding.'
		);
	}

	/**
	 * A release the database refuses does not spend one of the run's restarts.
	 *
	 * Arming a restart that is guaranteed to lose an unchanged claim achieves
	 * nothing, and doing it twice gives up on a run that was recoverable.
	 *
	 * @since 1.1.1
	 *
	 * @return void
	 */
	public function test_a_refused_release_does_not_spend_a_restart(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->claim_step( '' ) );

		$run_id = $run->id();

		global $wpdb;

		$wpdb->update(
			\AutoScribe\Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);

		$sweeper = new Stall_Sweeper( new Scheduler(), $this->handler() );

		$acted = $this->with_refused( 'SET `step`', fn() => $sweeper->handle() );

		$this->assertSame( 0, $acted, 'A run whose claim will not release is left for the next sweep.' );
		$this->assertSame( 0, Run::load( $run_id )->sweeps(), 'No restart should have been spent.' );
	}

	/**
	 * Builds the queued handler under test.
	 *
	 * @since 1.1.1
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
