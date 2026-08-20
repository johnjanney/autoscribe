<?php
/**
 * Tests for previews as runs.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Admin\Actions;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Stall_Sweeper;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers the preview's place in the run contract.
 *
 * A preview is a run in every accounting sense and in no other: it makes paid
 * calls, holds a reservation against the monthly cap, and creates no post. Until
 * 1.3.0 it opened its row by hand, so it had neither the models and rates
 * snapshot every other run records nor any statement of what kind of run it was
 * — and a stall sweep therefore treated an abandoned preview as an unfinished
 * article, put it through post finalisation, and concluded the prompt: a failure
 * notice, a retry decision, and a re-armed schedule, for a button somebody
 * pressed once.
 *
 * @since 1.3.0
 */
final class Preview_RunTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Gives the providers keys so previews reach their paid calls.
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
	 * A preview records what kind of run it is, and its models and rates.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_preview_records_its_kind_and_snapshot(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();
		$article   = ( new Actions( new Provider_Registry(), new Scheduler() ) )->preview( $prompt_id );

		$this->assertNotWPError( $article );

		$run = Run::load( (int) Run::latest_for_prompt( $prompt_id )['id'] );

		$this->assertTrue( $run->is_preview() );
		$this->assertSame( Run::KIND_PREVIEW, $run->kind() );
		$this->assertSame( 'claude-opus-5', $run->resolved_model( 'text' ) );
		$this->assertNotEmpty( $run->payload()['rates'] ?? array() );
		$this->assertSame( Run::STATUS_SUCCESS, Run::latest_for_prompt( $prompt_id )['status'] );
		$this->assertNull( Run::latest_for_prompt( $prompt_id )['post_id'], 'A preview creates no post.' );
	}

	/**
	 * A preview settles at the rates it recorded, not at whatever is current.
	 *
	 * The edit is applied after the preview's row is opened, which is the same
	 * hazard a queued run has and the one previews had no protection from: a
	 * preview checked against one price list and settled against another.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_preview_settles_at_the_rates_it_opened_with(): void {
		$pricing = new Pricing_Table();

		$pricing->set( 'claude-opus-5', Pricing_Table::rate( 5.0, 25.0, 0.0, 0.01 ) );

		$run = ( new Generator( new Provider_Registry() ) )->open_preview( Prompt::load( $this->create_prompt() ) );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->record_text_usage( 'claude-opus-5', 200, 800 ) );

		$pricing->set( 'claude-opus-5', Pricing_Table::rate( 500.0, 2500.0, 0.0, 1.0 ) );

		$settled = $run->settle_cost( null, 0 );

		$this->assertIsInt( $settled );
		$this->assertLessThan(
			1000,
			$settled,
			'A hundredfold price edit must not land on a preview already in flight.'
		);
	}

	/**
	 * An abandoned preview is closed, and nothing else happens.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_an_abandoned_preview_is_closed_without_touching_the_schedule(): void {
		global $wpdb;

		$prompt_id = $this->create_prompt(
			array(
				'schedule_type'   => 'daily',
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$run = ( new Generator( new Provider_Registry() ) )->open_preview( Prompt::load( $prompt_id ) );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$this->assertTrue( $run->reserve_cost( 250 ) );
		$this->assertTrue( $run->record_step( Run::KIND_PREVIEW ) );

		$wpdb->update(
			Activation::table_name(),
			array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - ( Stall_Sweeper::threshold() * 2 ) ) ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);

		$mailed = 0;
		$count  = static function ( $args ) use ( &$mailed ) {
			++$mailed;

			return $args;
		};

		add_filter( 'wp_mail', $count );

		$handler = new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		);

		$acted = ( new Stall_Sweeper( new Scheduler(), $handler ) )->recover( $run_id, Run::KIND_PREVIEW );

		remove_filter( 'wp_mail', $count );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertTrue( $acted );
		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertSame( 0, (int) $row['cost_cents'], 'Closing the preview releases what it reserved.' );
		$this->assertStringContainsString( 'preview', (string) $row['error'] );
		$this->assertSame( 0, $mailed, 'A preview is not a prompt failure and is not mailed about.' );
		$this->assertSame(
			0,
			Prompt::load( $prompt_id )->next_run_ts(),
			'An abandoned preview must not arm the prompt\'s schedule.'
		);
	}

	/**
	 * The queued step handler refuses to finish a preview.
	 *
	 * Nothing should queue a step for a preview. If something does — an action
	 * armed by an earlier version, say — the sequence would find no step left to
	 * take and treat the run as ready to publish, finalising a post that was
	 * never created.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_the_queue_will_not_finish_a_preview(): void {
		$run = ( new Generator( new Provider_Registry() ) )->open_preview( Prompt::load( $this->create_prompt() ) );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->record_step( Run::KIND_PREVIEW ) );

		( new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		) )->handle_step( $run->id() );

		$this->assertSame(
			Run::STATUS_RUNNING,
			Run::load( $run->id() )->status(),
			'The queue leaves a preview alone rather than publishing it.'
		);
	}

	/**
	 * A preview row written by 1.2.x is still recognised as a preview.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_preview_from_an_earlier_version_is_recognised(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		// What 1.2.x left behind: the step column and no recorded kind.
		$this->assertTrue( $run->record_step( 'preview' ) );
		$this->assertTrue( Run::load( $run->id() )->is_preview() );
	}
}
