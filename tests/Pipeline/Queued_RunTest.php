<?php
/**
 * Queued run and retry tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Pipeline;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Prompts\Prompt_Post_Type;
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
	 * A scheduled run contributes what it spent to the monthly total.
	 *
	 * The end-to-end form of the accounting defect. A run advanced across actions
	 * settled to zero, so the month-to-date total never moved however many
	 * articles were generated — and a cap that never sees spending never fires.
	 * Section 7.4's whole mechanism rests on this number.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_scheduled_run_is_counted_against_the_monthly_cap(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->run_to_completion( $prompt_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_SUCCESS, $row['status'] );
		$this->assertGreaterThan( 0, (int) $row['cost_cents'], 'A run that made paid calls must not settle to zero.' );
		$this->assertGreaterThan(
			0,
			( new Budget_Guard() )->month_to_date_cents( $prompt_id ),
			'A completed run has to show up in the month-to-date total, or the cap cannot fire.'
		);
	}

	/**
	 * A prompt edited mid-chain stops the run rather than finishing under it.
	 *
	 * The window is real: a chain spans several queue passes, and every step
	 * reloads the prompt. Publishing mode is the case that matters most — a run
	 * that began under review would otherwise finish by publishing, turning
	 * section 10's safety model off retrospectively for work already in progress.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_editing_a_prompt_mid_chain_stops_the_run(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt( array( 'post_status_mode' => 'review' ) );

		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$this->handler()->handle_step( $run_id );

		// An editor switches the prompt to publish automatically, mid-run.
		update_post_meta( $prompt_id, '_autoscribe_post_status_mode', 'auto' );

		$this->handler()->handle_step( $run_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertStringContainsString( 'edited while the run was in progress', (string) $row['error'] );
		$this->assertSame( 'budget_check', (string) $row['step'], 'The chain should stop where it was.' );
	}

	/**
	 * Re-arming the next occurrence does not look like an edit.
	 *
	 * The plugin writes to the prompt while a run is in flight — the cached
	 * next-run timestamp and the attempt counter — and a fingerprint built from
	 * every meta key would call each of those an edit and stop every run.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_plugins_own_writes_are_not_edits(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$this->handler()->handle_step( $run_id );

		update_post_meta( $prompt_id, '_autoscribe_next_run_ts', time() + 3600 );
		update_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, 2 );

		/*
		 * Pump this run rather than starting a fresh one. run_to_completion()
		 * would open a second run whose fingerprint is taken after the writes
		 * above, which passes whether or not the guard ignores them — the first
		 * version of this test did exactly that and proved nothing.
		 */
		$passes = count( Pipeline::STEPS ) + 2;

		for ( $i = 0; $i < $passes; $i++ ) {
			$this->handler()->handle_step( $run_id );
		}

		$this->assertSame( Run::STATUS_SUCCESS, Run::latest_for_prompt( $prompt_id )['status'] );
	}

	/**
	 * A run whose fingerprint cannot be stored does not start.
	 *
	 * The guard treats a missing fingerprint as a run opened by an earlier
	 * version, which is right for an upgrade and wrong for a write that failed:
	 * the run would go on to accept any edit silently, which is the guard turned
	 * off by the one failure it most needs to survive.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_run_whose_fingerprint_cannot_be_stored_does_not_start(): void {
		global $wpdb;

		$prompt_id = $this->create_prompt();

		$break = static function ( $query ) {
			return str_contains( (string) $query, 'SET `payload`' )
				? 'UPDATE autoscribe_no_such_table SET payload = 1 WHERE id = 1'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		// No provider mock: reaching one would throw.
		$this->handler()->handle( $prompt_id );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertSame( '', (string) $row['step'], 'Nothing should have run.' );
	}

	/**
	 * Aborting an edited run still charges for the grounded call it made.
	 *
	 * Run::fail() settles from measured usage, and the grounded-request charge is
	 * not part of that measurement — it is passed in. Every abort path in this
	 * handler left it at zero, so a run that had already paid for a grounded body
	 * call settled for less than it spent, and the month-to-date total that
	 * section 7.4's cap reads was short by the difference.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_an_aborted_grounded_run_is_charged_for_its_grounding(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt( array( 'grounding_enabled' => 1 ) );

		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// Through the budget check, the proposal, and the paid body call.
		foreach ( array( 1, 2, 3 ) as $ignored ) {
			$this->handler()->handle_step( $run_id );
		}

		$this->assertSame( 'generate_body', (string) Run::latest_for_prompt( $prompt_id )['step'] );

		update_post_meta( $prompt_id, '_autoscribe_target_word_count', 1234 );

		$this->handler()->handle_step( $run_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );

		$pricing    = new Pricing_Table();
		$ungrounded = $pricing->cost_cents(
			(string) $row['text_model'],
			(int) $row['input_tokens'],
			(int) $row['output_tokens']
		);

		$this->assertGreaterThan(
			$ungrounded,
			(int) $row['cost_cents'],
			'The grounded request this run paid for must be settled with it.'
		);
	}

	/**
	 * A refused retry does not leave the prompt stranded.
	 *
	 * The retry branch deliberately does not arm the regular next occurrence,
	 * because a retry is outstanding. When #16 made a refused retry reportable,
	 * this caller still discarded the report — so the prompt was left with a
	 * raised attempt counter, no queued action, and nothing said. The refusal was
	 * detectable and the prompt stopped silently anyway.
	 *
	 * Only the first arming is refused, so the fall-through can be observed
	 * restoring a future occurrence.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_refused_retry_does_not_strand_the_prompt(): void {
		$this->mock_provider_failure( 503 );

		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		/*
		 * Refuse the first prompt-hook arming, which is the retry, and let the
		 * one after it — the restored occurrence — through. Refusing everything
		 * would stop the chain at its first step action instead and never reach
		 * the branch under test, which is how the first version of this test
		 * passed against the defect.
		 */
		$refusals = 0;

		$refuse_retry = static function ( $pre, $timestamp, $hook ) use ( &$refusals ) {
			unset( $timestamp );

			if ( Scheduler::HOOK_RUN_PROMPT !== $hook ) {
				return $pre;
			}

			++$refusals;

			return 1 === $refusals ? 0 : $pre;
		};

		add_filter( 'pre_as_schedule_single_action', $refuse_retry, 10, 3 );

		$this->run_to_completion( $prompt_id );

		remove_filter( 'pre_as_schedule_single_action', $refuse_retry, 10 );

		$this->assertSame(
			'',
			get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true ),
			'A retry that was never queued must not leave the counter raised.'
		);

		$this->assertNotEmpty(
			as_get_scheduled_actions(
				array(
					'hook'   => Scheduler::HOOK_RUN_PROMPT,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			),
			'The prompt must be left with a future occurrence rather than stopped.'
		);
	}

	/**
	 * A prompt deleted mid-chain closes the run instead of fatalling.
	 *
	 * The branch handling a removed prompt went on to ask that prompt whether
	 * grounding was enabled, which is a TypeError when there is no prompt left.
	 * The action died before the run could be failed or its chain cancelled, so
	 * the run stayed open with its budget reservation held — the opposite of what
	 * the branch exists to do.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_deleted_prompt_closes_the_run_rather_than_fatalling(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$this->handler()->handle_step( $run_id );

		wp_delete_post( $prompt_id, true );

		$this->handler()->handle_step( $run_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertSame( 0, (int) $row['cost_cents'], 'A run stopped before any paid call settles to nothing.' );
	}

	/**
	 * Turning grounding off mid-run does not erase the charge already incurred.
	 *
	 * The direction my first fix got wrong. Settlement used to read the prompt's
	 * current setting, so an editor disabling grounding after the grounded body
	 * call had the surcharge dropped from a request the run had already paid for.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_disabling_grounding_mid_run_still_charges_for_the_call_made(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt( array( 'grounding_enabled' => 1 ) );

		$run_id = $this->advance_to_body( $prompt_id );

		update_post_meta( $prompt_id, '_autoscribe_grounding_enabled', 0 );

		$this->handler()->handle_step( $run_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertGreaterThan(
			$this->ungrounded_cost( $row ),
			(int) $row['cost_cents'],
			'A grounded request that was made must be charged for, whatever the prompt says now.'
		);
	}

	/**
	 * Turning grounding on mid-run does not invent a charge.
	 *
	 * The opposite direction, and the one my "over-stating a cap is the safe
	 * direction" reasoning missed entirely. Enabling grounding after the topic
	 * proposal — before any grounded call — used to add a surcharge for a request
	 * that never happened, because the proposal's own token usage is enough to
	 * make settlement apply the count it is given.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_enabling_grounding_mid_run_does_not_invent_a_charge(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// Budget check and the topic proposal: paid, but not grounded.
		$this->handler()->handle_step( $run_id );
		$this->handler()->handle_step( $run_id );

		update_post_meta( $prompt_id, '_autoscribe_grounding_enabled', 1 );

		$this->handler()->handle_step( $run_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame(
			$this->ungrounded_cost( $row ),
			(int) $row['cost_cents'],
			'No grounded request was made, so none should be charged for.'
		);
	}

	/**
	 * Advances a fresh run as far as a completed body call.
	 *
	 * @since 1.1.0
	 *
	 * @param int $prompt_id Prompt to run.
	 * @return int The run's ID.
	 */
	private function advance_to_body( int $prompt_id ): int {
		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		foreach ( array( 'budget_check', 'propose_topic', 'generate_body' ) as $expected ) {
			$this->handler()->handle_step( $run_id );

			$this->assertSame( $expected, (string) Run::latest_for_prompt( $prompt_id )['step'] );
		}

		return $run_id;
	}

	/**
	 * Returns what a run's recorded tokens would cost without a grounded call.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $row Run row.
	 * @return int
	 */
	private function ungrounded_cost( array $row ): int {
		return ( new Pricing_Table() )->cost_cents(
			(string) $row['text_model'],
			(int) $row['input_tokens'],
			(int) $row['output_tokens']
		);
	}

	/**
	 * Abandoning a run for a disabled prompt clears its attempt counter.
	 *
	 * The counter lives on the prompt, because a retry opens a new run and it has
	 * to survive across rows. Every other terminal path clears it; the two that
	 * abandon a run because the prompt is gone or off did not. A prompt disabled
	 * part-way through a retry series and later switched back on therefore
	 * resumed with the counter still raised, and quietly got fewer attempts than
	 * it should — one of those failures that only shows up as "it gave up sooner
	 * than it used to".
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_abandoning_a_run_clears_the_attempt_counter(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		update_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, 2 );

		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$this->handler()->handle_step( $run_id );

		update_post_meta( $prompt_id, '_autoscribe_enabled', 0 );

		$this->handler()->handle_step( $run_id );

		$this->assertSame( Run::STATUS_FAILED, Run::latest_for_prompt( $prompt_id )['status'] );
		$this->assertSame(
			'',
			get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true ),
			'A prompt switched back on should start its next run with a fresh count.'
		);
	}

	/**
	 * However a run ends, the prompt is left able to run again.
	 *
	 * Section 4.3 asks for the next occurrence to be armed whether a run
	 * succeeded or failed, so that one bad night does not stop a prompt for good.
	 * A chain spread across queued actions has many more ways to end than a run
	 * inside a single request did, and each was written separately — this asserts
	 * the property they are all supposed to share, rather than trusting that each
	 * new exit remembered it.
	 *
	 * The two paths that abandon a run because the prompt is gone or switched off
	 * are deliberately not here: cancelling is the point of them.
	 *
	 * @dataProvider terminal_outcomes
	 *
	 * @since 1.1.0
	 *
	 * @param string $outcome How to end the run.
	 * @return void
	 */
	public function test_every_terminal_path_leaves_a_future_occurrence( string $outcome ): void {
		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		if ( 'provider_failure' === $outcome ) {
			$this->mock_provider_failure( 401 );
		} else {
			$this->mock_provider_success();
		}

		if ( 'prompt_edited' === $outcome ) {
			$this->handler()->handle( $prompt_id );

			$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

			$this->handler()->handle_step( $run_id );

			update_post_meta( $prompt_id, '_autoscribe_target_word_count', 4321 );

			$this->handler()->handle_step( $run_id );
		} else {
			$this->run_to_completion( $prompt_id );
		}

		$this->assertNotEmpty(
			as_get_scheduled_actions(
				array(
					'hook'   => Scheduler::HOOK_RUN_PROMPT,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			),
			$outcome . ' left the prompt with no future occurrence.'
		);
	}

	/**
	 * The ways a run can end that should still leave the prompt scheduled.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array<int, string>>
	 */
	public function terminal_outcomes(): array {
		return array(
			'success'          => array( 'success' ),
			'provider failure' => array( 'provider_failure' ),
			'prompt edited'    => array( 'prompt_edited' ),
		);
	}

	/**
	 * Disabling a prompt clears its attempt counter even with no run in flight.
	 *
	 * The usual way a prompt is switched off is while nothing is executing: the
	 * only queued action is a pending retry, and saving the prompt cancels it. No
	 * queue callback runs, so neither of the handler's abandon paths is reached
	 * and the counter survives — re-enabling the prompt then resumes midway
	 * through the retry series, which is the leak the previous change set out to
	 * close, through a door it did not cover.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_disabling_a_prompt_clears_the_counter_with_no_run_in_flight(): void {
		$prompt_id = $this->create_prompt();

		update_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, 2 );
		update_post_meta( $prompt_id, '_autoscribe_enabled', 0 );

		do_action( 'save_post_' . Prompt_Post_Type::POST_TYPE, $prompt_id );

		$this->assertSame(
			'',
			get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true )
		);
	}

	/**
	 * Trashing a prompt clears its attempt counter too.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_trashing_a_prompt_clears_the_counter(): void {
		$prompt_id = $this->create_prompt();

		update_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, 3 );

		wp_trash_post( $prompt_id );

		$this->assertSame(
			'',
			get_post_meta( $prompt_id, Queued_Run_Handler::ATTEMPT_META, true )
		);
	}

	/**
	 * A run that cannot record its grounded call is still charged for it.
	 *
	 * The failure path of the marker, and it undoes the marker's own purpose: the
	 * grounded response has arrived and been paid for, the write that remembers
	 * it is refused, and settlement then reads back a zero and drops the
	 * surcharge — understating exactly what the marker exists to capture.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_run_is_charged_for_grounding_it_could_not_record(): void {
		global $wpdb;

		$this->mock_provider_success();

		$prompt_id = $this->create_prompt( array( 'grounding_enabled' => 1 ) );

		$this->handler()->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		$this->handler()->handle_step( $run_id );
		$this->handler()->handle_step( $run_id );

		// Refuse only the write that records the grounded call.
		$break = static function ( $query ) {
			return str_contains( (string) $query, 'grounded_calls' )
				? 'UPDATE autoscribe_no_such_table SET payload = 1 WHERE id = 1'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$this->handler()->handle_step( $run_id );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertGreaterThan(
			$this->ungrounded_cost( $row ),
			(int) $row['cost_cents'],
			'The grounded request was made and paid for, so it must be settled even though recording it failed.'
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
