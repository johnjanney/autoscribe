<?php
/**
 * Sequencer tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Pipeline;
use AutoScribe\Pipeline\Run;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers the sequence itself, apart from either of its drivers.
 *
 * Section 5's split needs a run to be resumable from its row alone, because a
 * queued action arrives knowing only a run ID. That is a different property from
 * "the steps run in order", and the existing suite only tests the second one —
 * every other test drives the whole pipeline in one call, where the order is
 * held together by the call stack rather than by anything stored.
 *
 * These tests advance a run one step at a time and rebuild the sequencer between
 * each, which is as close as a single process gets to what the queue will do.
 *
 * See docs/PIPELINE-SPLIT.md phase 3.
 *
 * @since 1.1.0
 */
final class PipelineTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Gives the provider a key so the steps reach their calls.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * A run advanced one step at a time reaches the end in order.
	 *
	 * Each step gets a freshly built sequencer, so nothing carries over in
	 * memory between them. Everything the next step needs has to come off the
	 * run row, which is exactly the constraint a queued action works under.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_run_advances_one_step_at_a_time(): void {
		$this->mock_provider_success();

		$prompt = Prompt::load( $this->create_prompt() );
		$run    = Run::start( $prompt->id() );

		$this->assertNotWPError( $run );

		$performed = array();
		$attempts  = count( Pipeline::STEPS ) + 1;

		for ( $i = 0; $i < $attempts; $i++ ) {
			$step = ( new Pipeline( new Provider_Registry() ) )->advance( $prompt, $run );

			$this->assertNotWPError( $step );

			if ( null === $step ) {
				break;
			}

			$performed[] = $step;
		}

		$this->assertSame( Pipeline::STEPS, $performed );
	}

	/**
	 * The next step is decided by the run row, not by a counter in memory.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_next_step_is_read_from_the_run(): void {
		$prompt   = Prompt::load( $this->create_prompt() );
		$run      = Run::start( $prompt->id() );
		$pipeline = new Pipeline( new Provider_Registry() );

		$this->assertNotWPError( $run );
		$this->assertSame( 'budget_check', $pipeline->next_step( $run ) );

		$run->record_step( 'generate_body' );

		$this->assertSame( 'assemble_post', $pipeline->next_step( $run ) );

		$run->record_step( 'generate_image' );

		$this->assertNull( $pipeline->next_step( $run ), 'The last step ends the sequence.' );
	}

	/**
	 * A run whose step is not part of this sequence is left alone.
	 *
	 * Preview opens a real run and records "preview" on it. Reading that as
	 * "somewhere before budget_check" would have the sequencer start a paid
	 * pipeline over a run that was never meant to have one.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_foreign_step_ends_the_sequence(): void {
		$prompt = Prompt::load( $this->create_prompt() );
		$run    = Run::start( $prompt->id() );

		$this->assertNotWPError( $run );

		$run->record_step( 'preview' );

		$this->assertNull( ( new Pipeline( new Provider_Registry() ) )->next_step( $run ) );
	}

	/**
	 * A step that cannot be recorded as completed stops the run.
	 *
	 * Everything downstream reads runs.step to know where the run has got to. If
	 * the write that advances it is refused and the driver is told the step
	 * succeeded, the run does not move: the synchronous loop re-reads the same
	 * position and executes the same step again, for as long as PHP lets it,
	 * holding the budget reservation open the whole time. Under the queue driver
	 * it would be an endless chain of actions instead.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_step_that_cannot_be_recorded_fails_the_run(): void {
		global $wpdb;

		$prompt   = Prompt::load( $this->create_prompt() );
		$run      = Run::start( $prompt->id() );
		$pipeline = new Pipeline( new Provider_Registry() );

		$this->assertNotWPError( $run );

		$break = static function ( $query ) {
			return str_contains( (string) $query, 'SET `step`' )
				? 'UPDATE autoscribe_no_such_table SET step = 1 WHERE id = 1'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$advanced = $pipeline->advance( $prompt, $run );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$this->assertWPError( $advanced );
		$this->assertSame( 'autoscribe_step_not_recorded', $advanced->get_error_code() );
	}

	/**
	 * The synchronous driver cannot loop for ever, whatever goes wrong.
	 *
	 * The test above fixes the one way a run could fail to advance while
	 * reporting success. This one covers the ways nobody has thought of yet: the
	 * driver runs a bounded number of times, so a sequence that stops advancing
	 * ends the request instead of spinning in it.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_synchronous_driver_is_bounded(): void {
		global $wpdb;

		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$break = static function ( $query ) {
			return str_contains( (string) $query, 'SET `step`' )
				? 'UPDATE autoscribe_no_such_table SET step = 1 WHERE id = 1'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$result = ( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$this->assertWPError( $result, 'The run must end rather than spin.' );
	}

	/**
	 * A post that cannot be linked to its run stops the run.
	 *
	 * Later steps read the post back off the run rather than receiving it as an
	 * argument, because they run in separate requests. A refused link therefore
	 * leaves a post nothing points at: the image step would attach its picture to
	 * post 0, and publishing would look for a post that was never recorded.
	 *
	 * Found by auditing the writes whose results were still being discarded,
	 * rather than by a review — this is the same fault as six others, at a site
	 * nobody had reported.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_post_that_cannot_be_linked_stops_the_run(): void {
		global $wpdb;

		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$break = static function ( $query ) {
			return str_contains( (string) $query, 'SET `post_id`' )
				? 'UPDATE autoscribe_no_such_table SET post_id = 1 WHERE id = 1'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$result = ( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_state_not_recorded', $result->get_error_code() );
	}

	/**
	 * A failing step stops the sequence where it failed.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_failed_step_does_not_advance_the_run(): void {
		$this->mock_provider_failure( 503 );

		$prompt   = Prompt::load( $this->create_prompt() );
		$run      = Run::start( $prompt->id() );
		$pipeline = new Pipeline( new Provider_Registry() );

		$this->assertNotWPError( $run );
		$this->assertSame( 'budget_check', $pipeline->advance( $prompt, $run ) );

		$failed = $pipeline->advance( $prompt, $run );

		$this->assertWPError( $failed );
		$this->assertSame( 'budget_check', $run->step(), 'A failed step must not be recorded as completed.' );
		$this->assertSame( 'propose_topic', $pipeline->next_step( $run ), 'The run should still be waiting on the step that failed.' );
	}
}
