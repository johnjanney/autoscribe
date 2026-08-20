<?php
/**
 * Tests for a worker that keeps going after losing its claim.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Content\Article_Validator;
use AutoScribe\Content\Taxonomy_Applier;
use AutoScribe\Pipeline\Close_Result;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Assemble_Post;
use AutoScribe\Pipeline\Step_Generate_Image;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\SEO\SEO_Adapter_Factory;
use AutoScribe\Security\Content_Sanitizer;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers what a superseded worker is still able to do.
 *
 * The stall sweeper judges a worker gone by whether anything is queued for its
 * run, which a slow worker inside a 120-second provider call looks exactly like.
 * So "the worker is gone" is a guess, and the guess is sometimes wrong: the
 * original can return after its replacement has taken the claim.
 *
 * Version 1.2.0 fenced two writes against that. Everything else a claimed step
 * does — the article identity, the post link, the settled cost, the terminal
 * transition, the post itself, its terms — went through unconditionally.
 *
 * @since 1.3.0
 */
final class Stale_WorkerTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

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
	 * A superseded worker cannot write the run's own columns.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_superseded_worker_cannot_write_the_run_row(): void {
		$run_id = $this->stale_and_replaced();
		$stale  = $this->stale;

		$this->assertFalse( $stale->record_article( 'Stale title', 'stale-topic' ) );
		$this->assertFalse( $stale->record_post( 999 ) );
		$this->assertFalse( $stale->record_cost( 4242 ) );

		$row = Run::latest_for_prompt( Run::load( $run_id )->prompt_id() );

		$this->assertNull( $row['title'] );
		$this->assertNull( $row['post_id'] );
		$this->assertSame( 0, (int) $row['cost_cents'] );
	}

	/**
	 * A superseded worker cannot close the run its replacement is running.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_superseded_worker_cannot_close_the_run(): void {
		$run_id = $this->stale_and_replaced();

		$this->assertSame(
			Close_Result::Already_Closed,
			$this->stale->skip( Run::STATUS_SKIPPED_DUPLICATE, 'Stale duplicate verdict.' ),
			'A verdict from a worker that no longer owns the step is not the run\'s verdict.'
		);
		$this->assertSame( Run::STATUS_RUNNING, Run::load( $run_id )->status() );
		$this->assertSame(
			Close_Result::Already_Closed,
			$this->stale->fail( 'Stale failure.' )
		);
		$this->assertSame( Run::STATUS_RUNNING, Run::load( $run_id )->status() );
	}

	/**
	 * A superseded worker does not assemble a second post.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_superseded_worker_does_not_assemble_a_post(): void {
		$prompt_id = $this->create_prompt();

		$this->stale_and_replaced( $prompt_id );

		$before = count(
			get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			)
		);

		$article = ( new Article_Validator() )->from_array( $this->article_payload() );

		$this->assertNotWPError( $article );

		$step = new Step_Assemble_Post(
			new Content_Sanitizer(),
			new SEO_Adapter_Factory(),
			new Taxonomy_Applier()
		);

		$result = $step->run( Prompt::load( $prompt_id ), $article, $this->stale );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_claim_lost', $result->get_error_code() );
		$this->assertSame(
			$before,
			count(
				get_posts(
					array(
						'post_type'   => 'post',
						'post_status' => 'any',
						'fields'      => 'ids',
					)
				)
			),
			'No post may be created by a worker whose step belongs to somebody else.'
		);
	}

	/**
	 * A superseded worker does not attach a second featured image.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_superseded_worker_does_not_attach_an_image(): void {
		Key_Store::set( 'openai', 'test-key' );
		$this->mock_provider_failure( 500 );

		$prompt_id = $this->create_prompt(
			array(
				'image_mode'     => 'optional',
				'image_provider' => 'openai_image',
				'image_model'    => 'gpt-image-2',
			)
		);

		$this->stale_and_replaced( $prompt_id );

		$post_id = self::factory()->post->create();
		$article = ( new Article_Validator() )->from_array( $this->article_payload() );

		$this->assertNotWPError( $article );

		$result = ( new Step_Generate_Image( new Provider_Registry() ) )
			->attach( Prompt::load( $prompt_id ), $article, $this->stale, $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_claim_lost', $result->get_error_code() );
		$this->assertSame( 0, get_post_thumbnail_id( $post_id ) );
	}

	/**
	 * A worker whose run was closed under it cannot change the closed row.
	 *
	 * This is the case the token alone did not cover. A terminal sweep closes a
	 * run *at* the claim it observed and leaves the marker where it is, so the
	 * worker it closed found its token unchanged and believed it still owned the
	 * step. Ownership is the row, the token, and the run still being open.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function test_a_worker_whose_run_was_closed_cannot_write_to_it(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id = $run->id();

		$this->assertTrue( $run->claim_step( '' ) );

		// A sweep gives up on the run at exactly the position it observed.
		$observed = Run::load( $run_id )->raw_step();

		$this->assertTrue(
			Run::load( $run_id )->fail( 'Given up on.', null, 0, $observed )->ended()
		);

		$this->assertTrue( $run->lost_claim(), 'A closed run is not this worker\'s to continue.' );
		$this->assertFalse( $run->holds_claim() );
		$this->assertFalse( $run->record_article( 'Stale title', 'stale-topic' ) );
		$this->assertFalse( $run->record_post( 999 ) );
		$this->assertFalse( $run->record_cost( 4242 ) );
		$this->assertFalse( $run->record_step( 'propose_topic' ) );
		$this->assertFalse( $run->merge_payload( array( 'topic' => array( 'title' => 'Stale' ) ) ) );

		$row = Run::latest_for_prompt( $run->prompt_id() );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'Given up on.', (string) $row['error'] );
		$this->assertNull( $row['title'] );
		$this->assertNull( $row['post_id'] );
		$this->assertSame( 0, (int) $row['cost_cents'] );
		$this->assertSame( $observed, (string) $row['step'] );
	}

	/**
	 * Finalisation refuses a run that was closed before it could claim it.
	 *
	 * The outer guard: the claim itself. Named for what it covers, because the
	 * inner guard — the ownership re-check immediately before the post's status
	 * transition — is a different line and has its own test below.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function test_finalisation_refuses_a_run_it_cannot_claim(): void {
		$prompt_id = $this->create_prompt( array( 'post_status_mode' => 'auto' ) );
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run_id  = $run->id();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertTrue( $run->record_post( $post_id ) );
		$this->assertTrue( $run->record_step( 'generate_image' ) );

		$article = ( new Article_Validator() )->from_array( $this->article_payload() );

		$this->assertNotWPError( $article );

		$generator = new Generator( new Provider_Registry() );
		$finalised = Run::load( $run_id );

		// The worker claims finalisation, and a sweep closes the run underneath it
		// before it reaches the status transition.
		$this->assertTrue( $finalised->claim_step( 'generate_image' ) );
		$this->assertTrue(
			Run::load( $run_id )->fail( 'Given up on.', null, 0, Run::load( $run_id )->raw_step() )->ended()
		);

		$result = $generator->finalise( Prompt::load( $prompt_id ), $finalised, $article, null, null, 0 );

		$this->assertWPError( $result );
		$this->assertSame( Generator::CLOSE_RACE_LOST, $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $post_id ), 'A failed run must not publish afterwards.' );
		$this->assertSame( Run::STATUS_FAILED, Run::latest_for_prompt( $prompt_id )['status'] );
	}

	/**
	 * A run closed between the finalisation claim and the publish does not publish.
	 *
	 * This is the line F130-01 added, and it needs an interleaving to reach: the
	 * claim at the top of finalisation succeeds, and the run is closed in the gap
	 * between that claim and the ownership check the transition depends on. The
	 * close is issued from inside the query filter, immediately before the
	 * ownership read it is meant to precede, which puts it exactly where a sweep
	 * would land.
	 *
	 * It runs on this connection rather than a second one deliberately. A second
	 * connection cannot touch a row created inside the test's own uncommitted
	 * transaction — it waits for a lock nobody will release — so what a second
	 * connection buys here is a fifty-second timeout rather than realism.
	 *
	 * Without the re-check the post would be published for a run the log had
	 * already reported as failed.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function test_a_run_closed_before_the_publish_does_not_publish(): void {
		$prompt_id = $this->create_prompt( array( 'post_status_mode' => 'auto' ) );
		$run       = Run::start( $prompt_id );

		$this->assertNotWPError( $run );

		$run_id  = $run->id();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertTrue( $run->record_post( $post_id ) );
		$this->assertTrue( $run->record_step( 'generate_image' ) );

		$article = ( new Article_Validator() )->from_array( $this->article_payload() );

		$this->assertNotWPError( $article );

		$claimed   = false;
		$closed    = false;
		$table     = Activation::table_name();
		$intercept = function ( $query ) use ( &$claimed, &$closed, $run_id, $table ) {
			$sql = (string) $query;

			// The claim finalisation takes first.
			if ( str_contains( $sql, 'UPDATE' ) && str_contains( $sql, "step = 'doing:" ) ) {
				$claimed = true;

				return $query;
			}

			// The ownership check that guards the transition. A sweep closes the
			// run on its own connection in the moment before it runs.
			if ( $claimed && ! $closed && str_contains( $sql, "AND status = 'running' AND step =" ) ) {
				$closed = true;

				global $wpdb;

				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET status = %s, error = %s, finished_at = %s WHERE id = %d',
						$table,
						Run::STATUS_FAILED,
						'Given up on by a sweep.',
						current_time( 'mysql', true ),
						$run_id
					)
				);
			}

			return $query;
		};

		add_filter( 'query', $intercept );

		$result = ( new Generator( new Provider_Registry() ) )->finalise(
			Prompt::load( $prompt_id ),
			Run::load( $run_id ),
			$article,
			null,
			null,
			0
		);

		remove_filter( 'query', $intercept );

		$this->assertTrue( $claimed, 'Finalisation must get past its own claim for this to test anything.' );
		$this->assertTrue( $closed, 'The interleaving must have happened for this to test anything.' );
		// The publication assertion comes first on purpose: it is the property
		// that matters, and asserting the error code before it would report a
		// removed guard as the wrong error rather than as a published post.
		$this->assertSame(
			'draft',
			get_post_status( $post_id ),
			'A run closed before the transition must not publish afterwards.'
		);
		$this->assertWPError( $result );
		$this->assertSame( Generator::CLOSE_RACE_LOST, $result->get_error_code() );
		$this->assertSame( Run::STATUS_FAILED, Run::latest_for_prompt( $prompt_id )['status'] );
	}

	/**
	 * A worker that closed the run itself is not treated as having lost it.
	 *
	 * A budget skip and a duplicate topic are decisions a step makes and then
	 * reports. Reading a self-close as a lost claim would turn every one of them
	 * into "somebody else owns this run", and the run's real outcome would never
	 * be reported.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function test_a_worker_that_closed_the_run_itself_still_reports_it(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->claim_step( '' ) );
		$this->assertTrue( $run->skip( Run::STATUS_SKIPPED_BUDGET, 'Over the cap.' )->ended() );

		$this->assertFalse( $run->lost_claim(), 'Ending a run is not losing it.' );
		$this->assertFalse(
			$run->record_cost( 99 ),
			'The row is still finished, so it still refuses the write.'
		);
	}

	/**
	 * The worker whose claim it is, is not stopped by any of this.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_the_worker_holding_the_claim_can_still_write(): void {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );
		$this->assertTrue( $run->claim_step( '' ) );
		$this->assertTrue( $run->record_article( 'Live title', 'live-topic' ) );
		$this->assertTrue( $run->record_cost( 11 ) );
		$this->assertTrue( $run->skip( Run::STATUS_SKIPPED_DUPLICATE, 'Its own verdict.' )->ended() );
	}

	/**
	 * The run object belonging to the worker that was superseded.
	 *
	 * @since 1.3.0
	 * @var Run
	 */
	private Run $stale;

	/**
	 * Opens a run, claims it, has a sweep replace the worker, and returns the ID.
	 *
	 * @since 1.3.0
	 *
	 * @param int $prompt_id Prompt to run, or 0 to create one.
	 * @return int
	 */
	private function stale_and_replaced( int $prompt_id = 0 ): int {
		$run = Run::start( $prompt_id > 0 ? $prompt_id : $this->create_prompt() );

		$this->assertNotWPError( $run );

		$run_id      = $run->id();
		$this->stale = $run;

		$this->assertTrue( $run->claim_step( '' ) );

		// A sweep decides the worker is gone, and a replacement takes the step.
		$observed = Run::load( $run_id )->raw_step();

		$this->assertTrue( Run::load( $run_id )->release_claim( $observed ) );
		$this->assertTrue( Run::load( $run_id )->claim_step( '' ) );

		return $run_id;
	}
}
