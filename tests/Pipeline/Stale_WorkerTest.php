<?php
/**
 * Tests for a worker that keeps going after losing its claim.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Content\Article_Validator;
use AutoScribe\Content\Taxonomy_Applier;
use AutoScribe\Pipeline\Close_Result;
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
