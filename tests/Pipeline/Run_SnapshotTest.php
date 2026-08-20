<?php
/**
 * Tests for the configuration a run fixes when it opens.
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
 * Covers the model IDs and rates a run is checked and settled against.
 *
 * The configuration fingerprint catches an edit to the prompt or to a site
 * default. It cannot catch what changes underneath both. A blank model field
 * resolves through the adapter's own suggestion list, which is code, so an
 * upgrade can change the model a run in flight is using without changing
 * anything a fingerprint compares — the topic proposed by one model and the
 * article written by another. The pricing table is worse, because editing it is
 * an expected act, and an edit between the budget check and the settlement
 * changes what an open reservation gives back.
 *
 * @since 1.2.0
 */
final class Run_SnapshotTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

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
	 * Opening a run records the models it will use.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_opening_a_run_records_its_models(): void {
		$prompt_id = $this->create_prompt(
			array(
				'text_model'     => '',
				'image_mode'     => 'optional',
				'image_provider' => 'openai_image',
				'image_model'    => '',
			)
		);

		$run = ( new Generator( new Provider_Registry() ) )->open( $prompt_id );

		$this->assertNotWPError( $run );

		// Nothing is configured anywhere, so both resolve to the adapter's first
		// suggestion — which is the value that must be pinned, not re-derived.
		$this->assertNotSame( '', $run->resolved_model( 'text' ) );
		$this->assertNotSame( '', $run->resolved_model( 'image' ) );
		$this->assertSame(
			$run->resolved_model( 'text' ),
			$run->model_for( 'text', '', 'anthropic', array( 'something-else' ) ),
			'A recorded model wins over anything resolved later.'
		);
	}

	/**
	 * A run settles at the rates it was checked against, not the current ones.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_run_settles_at_the_rates_it_opened_with(): void {
		$this->mock_provider_success();

		$pricing = new Pricing_Table();

		$pricing->set( 'claude-opus-5', Pricing_Table::rate( 5.0, 25.0, 0.0, 0.01 ) );

		$prompt_id = $this->create_prompt();
		$handler   = new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		);

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		// One step in, an administrator edits the price list by a factor of a
		// hundred. The run has already been checked against the old one.
		$handler->handle_step( $run_id );

		$pricing->set( 'claude-opus-5', Pricing_Table::rate( 500.0, 2500.0, 0.0, 1.0 ) );

		for ( $i = 0; $i < 7; $i++ ) {
			$row = Run::latest_for_prompt( $prompt_id );

			if ( Run::STATUS_RUNNING !== $row['status'] ) {
				break;
			}

			$handler->handle_step( $run_id );
		}

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_SUCCESS, $row['status'] );

		$settled  = (int) $row['cost_cents'];
		$expected = ( new Pricing_Table( Run::load( $run_id )->payload()['rates'] ) )->cost_cents(
			(string) $row['text_model'],
			(int) $row['input_tokens'],
			(int) $row['output_tokens']
		);

		$this->assertSame(
			$expected,
			$settled,
			'Settlement uses the rates recorded on the run, not whatever the option now says.'
		);
		$this->assertLessThan(
			1000,
			$settled,
			'A hundredfold price edit mid-run must not land on a run already in flight.'
		);
	}

	/**
	 * A snapshot always carries a wildcard, so an unlisted model is not free.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_recorded_rate_table_still_prices_unknown_models(): void {
		$table = new Pricing_Table( array( 'claude-opus-5' => Pricing_Table::rate( 1.0, 1.0, 0.0, 0.0 ) ) );

		$this->assertGreaterThan(
			0,
			$table->cost_cents( 'some-model-nobody-listed', 1000000, 1000000 ),
			'An unlisted model must fall back to the wildcard rather than costing nothing.'
		);
	}
}
