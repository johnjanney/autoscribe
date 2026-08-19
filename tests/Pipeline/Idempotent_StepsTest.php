<?php
/**
 * Step idempotency tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Content\Article_Validator;
use AutoScribe\Content\Topic_Deduplicator;
use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Budget_Check;
use AutoScribe\Pipeline\Step_Generate_Body;
use AutoScribe\Pipeline\Step_Generate_Image;
use AutoScribe\Pipeline\Step_Propose_Topic;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers section 5's requirement that a retried step is idempotent by run ID.
 *
 * The brief states the requirement and gives one example — a retried step must
 * not create a second post. The expensive half is the one it does not spell out:
 * every step before assembly is a paid provider call, so a step re-entered
 * without a guard buys the same work again. Phase 3 makes re-entry ordinary
 * rather than exceptional, because each step becomes its own queued action and
 * anything the queue re-dispatches lands back at the top of a step that has
 * already run.
 *
 * Each test therefore asserts two things: the second call returns the same
 * answer, and it did not go to the provider to get it. The request count is the
 * assertion that matters — an equal return value proves nothing if it was bought
 * twice.
 *
 * See docs/PIPELINE-SPLIT.md phase 2.
 *
 * @since 1.1.0
 */
final class Idempotent_StepsTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * A grounded response carrying one source URL.
	 *
	 * @since 1.1.0
	 * @var array<int, array<string, mixed>>
	 */
	private const SEARCH_BLOCKS_FOR_REPAIR = array(
		array(
			'type'    => 'web_search_tool_result',
			'content' => array(
				array(
					'type' => 'web_search_result',
					'url'  => 'https://example.com/informed-the-article',
				),
			),
		),
	);

	/**
	 * Gives the providers a key so the steps reach their calls.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Key_Store::set( 'anthropic', 'test-key' );
		Key_Store::set( 'openai_image', 'test-key' );
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
	 * A second proposal call is not made, and not paid for.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_topic_is_proposed_once(): void {
		$this->mock_provider_success();

		$prompt = Prompt::load( $this->create_prompt() );
		$run    = $this->start_run( $prompt );
		$step   = new Step_Propose_Topic( new Provider_Registry(), new Topic_Deduplicator() );

		$first = $step->run( $prompt, $run );
		$calls = count( $this->captured_requests() );

		$second = $step->run( $prompt, $run );

		$this->assertSame( $first, $second );
		$this->assertCount( $calls, $this->captured_requests(), 'The second call must not reach the provider.' );
	}

	/**
	 * The article is generated once, however many times the step is entered.
	 *
	 * This is the guard that matters most. The body call is the largest paid call
	 * in the pipeline, so a re-entry without it buys the whole article again.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_article_is_generated_once(): void {
		$this->mock_provider_success();

		$prompt = Prompt::load( $this->create_prompt() );
		$run    = $this->start_run( $prompt );
		$step   = new Step_Generate_Body( new Provider_Registry(), new Article_Validator() );
		$topic  = array(
			'title'     => 'Water Hardness And Extraction',
			'topic_key' => 'water-hardness-and-extraction',
		);

		$first = $step->run( $prompt, $run, $topic );

		$this->assertNotWPError( $first );

		$calls  = count( $this->captured_requests() );
		$second = $step->run( $prompt, $run, $topic );

		$this->assertNotWPError( $second );
		$this->assertSame( $first->to_array(), $second->to_array() );
		$this->assertCount( $calls, $this->captured_requests(), 'The second call must not reach the provider.' );
	}

	/**
	 * A stored article that no longer satisfies the schema is regenerated.
	 *
	 * Paying twice is bad; publishing from a half-read row is worse. The guard
	 * re-validates on the way back in, so a truncated payload falls through to
	 * generation rather than becoming an Article that never passed the contract.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_damaged_stored_article_is_regenerated(): void {
		$this->mock_provider_success();

		$prompt = Prompt::load( $this->create_prompt() );
		$run    = $this->start_run( $prompt );
		$step   = new Step_Generate_Body( new Provider_Registry(), new Article_Validator() );

		$step->run( $prompt, $run, null );

		$damaged = $run->payload()['article'];
		unset( $damaged['content_html'] );
		$run->merge_payload( array( 'article' => $damaged ) );

		$calls   = count( $this->captured_requests() );
		$article = $step->run( $prompt, $run, null );

		$this->assertNotWPError( $article );
		$this->assertNotSame( '', $article->raw_content_html() );
		$this->assertGreaterThan( $calls, count( $this->captured_requests() ), 'A damaged row should be regenerated, not trusted.' );
	}

	/**
	 * A discarded article does not leave its sources behind.
	 *
	 * The regeneration path drops a stored article that no longer satisfies the
	 * schema. Anything else in the payload that described that article has to go
	 * with it: its source URLs belong to text the replacement never read, and
	 * Step_Assemble_Post would otherwise append them to the new article as its
	 * citations — publishing a provenance record for an article that was thrown
	 * away.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_discarded_article_does_not_leave_its_sources_behind(): void {
		$this->mock_provider_success();

		$prompt = Prompt::load( $this->create_prompt() );
		$run    = $this->start_run( $prompt );
		$step   = new Step_Generate_Body( new Provider_Registry(), new Article_Validator() );

		$damaged = $this->article_payload();
		unset( $damaged['content_html'] );

		$run->merge_payload( array( 'article' => $damaged ) );
		$run->record_sources( array( 'https://example.com/read-by-the-discarded-article' ) );

		$article = $step->run( $prompt, $run, null );

		$this->assertNotWPError( $article );
		$this->assertSame( array(), $run->sources(), 'The discarded article\'s sources must not survive it.' );
	}

	/**
	 * A repair call does not wipe the sources the first call reported.
	 *
	 * The counterpart to the test above, and the reason the clearing happens
	 * where it does. A repair is part of generating the same article: section 5.1
	 * sends it with grounding off, so it reports no sources of its own, and
	 * clearing on every empty result would throw away the reading that genuinely
	 * informed the article.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_repair_call_keeps_the_first_calls_sources(): void {
		$article = $this->article_payload();
		$first   = true;

		$this->install_responder(
			function ( $args ) use ( $article, &$first ) {
				unset( $args );

				if ( $first ) {
					$first = false;

					// Grounded, with sources, but the payload will not validate.
					return $this->anthropic_response(
						array( 'title' => 'Only a title' ),
						array( 'content' => self::SEARCH_BLOCKS_FOR_REPAIR )
					);
				}

				return $this->anthropic_response( $article );
			}
		);

		$prompt = Prompt::load( $this->create_prompt( array( 'grounding_enabled' => 1 ) ) );
		$run    = $this->start_run( $prompt );
		$step   = new Step_Generate_Body( new Provider_Registry(), new Article_Validator() );

		$repaired = $step->run( $prompt, $run, null );

		$this->assertNotWPError( $repaired );
		$this->assertSame(
			array( 'https://example.com/informed-the-article' ),
			$run->sources(),
			'A repair call must not discard what the first call read.'
		);
	}

	/**
	 * The budget check re-runs but does not reserve twice.
	 *
	 * This step is the exception to the pattern: skipping it on re-entry would
	 * let a run past a cap that had been breached in the meantime. It is safe to
	 * repeat because the reservation is an absolute write rather than an
	 * increment — a fact worth pinning, since changing it to `+=` would silently
	 * double every re-entered run's reservation.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_budget_check_does_not_reserve_twice(): void {
		$prompt = Prompt::load( $this->create_prompt() );
		$run    = $this->start_run( $prompt );
		$step   = new Step_Budget_Check();

		$this->assertTrue( $step->run( $prompt, $run ) );

		$once = ( new Budget_Guard() )->month_to_date_cents( $prompt->id() );

		$this->assertTrue( $step->run( $prompt, $run ) );

		$this->assertSame( $once, ( new Budget_Guard() )->month_to_date_cents( $prompt->id() ) );
		$this->assertGreaterThan( 0, $once, 'The run should have reserved something to begin with.' );
	}

	/**
	 * A second image is not generated for a post that already has one.
	 *
	 * The guard, the paid call, the sideload, and the thumbnail all live in the
	 * step now. They used to be split between the step and the orchestrator,
	 * which meant the guard sat somewhere that ran exactly once per pipeline and
	 * so could not be re-entered by a test at all.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_image_is_generated_once(): void {
		$this->mock_text_and_image_success();

		$prompt  = Prompt::load(
			$this->create_prompt(
				array(
					'image_mode'     => 'optional',
					'image_provider' => 'openai_image',
					'image_model'    => 'gpt-image-2',
				)
			)
		);
		$run     = $this->start_run( $prompt );
		$article = ( new Article_Validator() )->from_array( $this->article_payload() );
		$post_id = self::factory()->post->create();
		$step    = new Step_Generate_Image( new Provider_Registry() );

		$first = $step->attach( $prompt, $article, $run, $post_id );

		$this->assertIsInt( $first );
		$this->assertGreaterThan( 0, $first );

		$calls  = count( $this->captured_requests() );
		$second = $step->attach( $prompt, $article, $run, $post_id );

		$this->assertSame( $first, $second );
		$this->assertCount( $calls, $this->captured_requests(), 'The second call must not reach the provider.' );
		$this->assertSame( $first, get_post_thumbnail_id( $post_id ) );
	}

	/**
	 * A run that settled on no image does not try again on re-entry.
	 *
	 * "This run gave up" is a decision worth recording. Without it, a prompt in
	 * optional mode whose provider is having a bad hour buys an image on every
	 * re-entry until one happens to succeed.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_settled_decision_to_skip_the_image_is_not_revisited(): void {
		$this->mock_provider_failure( 503 );

		$prompt  = Prompt::load(
			$this->create_prompt(
				array(
					'image_mode'     => 'optional',
					'image_provider' => 'openai_image',
					'image_model'    => 'gpt-image-2',
				)
			)
		);
		$run     = $this->start_run( $prompt );
		$article = ( new Article_Validator() )->from_array( $this->article_payload() );
		$post_id = self::factory()->post->create();
		$step    = new Step_Generate_Image( new Provider_Registry() );

		$this->assertSame( 0, $step->attach( $prompt, $article, $run, $post_id ) );

		$calls = count( $this->captured_requests() );

		$this->assertSame( 0, $step->attach( $prompt, $article, $run, $post_id ) );
		$this->assertCount( $calls, $this->captured_requests(), 'A settled skip must not be retried at the provider.' );
	}

	/**
	 * Opens a run for a prompt.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt $prompt Prompt to run.
	 * @return Run
	 */
	private function start_run( Prompt $prompt ): Run {
		$run = Run::start( $prompt->id() );

		$this->assertNotWPError( $run );

		return $run;
	}

	/**
	 * Answers text calls and image calls with valid payloads.
	 *
	 * @since 1.1.0
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
}
