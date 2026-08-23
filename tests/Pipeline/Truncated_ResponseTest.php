<?php
/**
 * Tests for a provider that stops before it has finished answering.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Content\Article_Validator;
use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Generate_Body;
use AutoScribe\Pipeline\Step_Propose_Topic;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * What a run does with an answer that was cut off at the token ceiling.
 *
 * A live site failed every scheduled run for a day on this. Gemini returned
 * HTTP 200 with status "incomplete" and half a JSON object, the adapter read the
 * text and nothing else, and the validator — the first place that could tell
 * something was wrong — reported "the response was empty". It was not empty. It
 * was 10KB of article that stopped mid-word, because the ceiling the step asked
 * for covered the model's reasoning as well as its answer, and the reasoning had
 * taken half of it. The run then bought a second full-length answer as a repair,
 * which stopped in the same place for the same reason.
 *
 * These tests pin the three parts of that: the cut-off is recognised, it is paid
 * for once rather than twice, and the ceiling is large enough that an article of
 * the requested length fits underneath it with the thinking as well.
 *
 * @since 1.17.0
 */
final class Truncated_ResponseTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Gives the provider a key so the step reaches its call.
	 *
	 * @since 1.17.0
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
	 * @since 1.17.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * A body cut off at the ceiling says so, rather than "empty".
	 *
	 * @since 1.17.0
	 *
	 * @return void
	 */
	public function test_a_truncated_body_is_reported_as_truncated(): void {
		$this->mock_truncated_body();

		$prompt = Prompt::load( $this->create_prompt() );
		$result = $this->generate( $prompt );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_response_truncated', $result->get_error_code() );
		$this->assertStringContainsString( 'stopped before it finished', $result->get_error_message() );
		$this->assertStringContainsString(
			(string) Step_Generate_Body::output_ceiling( $prompt ),
			$result->get_error_message(),
			'The ceiling is the thing to change, so the error names it.'
		);
	}

	/**
	 * The repair attempt is not spent on an answer that was never finished.
	 *
	 * Section 5.1's repair exists for a model that answered with the wrong shape.
	 * A model that ran out of room will run out of room again at the same place,
	 * so the second full-length call buys nothing and costs as much as the first.
	 *
	 * @since 1.17.0
	 *
	 * @return void
	 */
	public function test_a_truncated_body_is_not_repaired(): void {
		$this->mock_truncated_body();

		$this->assertWPError( $this->generate( Prompt::load( $this->create_prompt() ) ) );
		$this->assertCount( 1, $this->captured_requests(), 'One call, not two.' );
	}

	/**
	 * The tokens the cut-off answer spent are still charged for.
	 *
	 * Truncated or not, the provider has billed for them, and a run log that
	 * dropped them would understate the month against the cap.
	 *
	 * @since 1.17.0
	 *
	 * @return void
	 */
	public function test_a_truncated_body_is_still_paid_for(): void {
		$this->mock_truncated_body();

		$prompt = Prompt::load( $this->create_prompt() );

		$this->assertWPError( $this->generate( $prompt ) );

		$row = Run::latest_for_prompt( $prompt->id() );

		$this->assertSame( 100, (int) $row['input_tokens'] );
		$this->assertSame( 4096, (int) $row['output_tokens'] );
	}

	/**
	 * Retrying sends the identical request, so a truncation is permanent.
	 *
	 * @since 1.17.0
	 *
	 * @return void
	 */
	public function test_a_truncation_is_not_retried(): void {
		$this->mock_truncated_body();

		$error = $this->generate( Prompt::load( $this->create_prompt() ) );

		$this->assertWPError( $error );
		$this->assertFalse( ( new Retry_Policy() )->should_retry( $error, 1 ) );
	}

	/**
	 * The ceiling leaves room for the article, its metadata, and the thinking.
	 *
	 * The old figure was three times the word count and nothing else, which for
	 * the 800-word default is 2,400 tokens — and the two failures that provoked
	 * this ended at 2,385 and 2,386 tokens of thinking plus answer.
	 *
	 * @since 1.17.0
	 *
	 * @return void
	 */
	public function test_the_ceiling_leaves_room_for_reasoning(): void {
		$prompt = Prompt::load( $this->create_prompt( array( 'target_word_count' => 800 ) ) );

		$ceiling = Step_Generate_Body::output_ceiling( $prompt );

		$this->assertGreaterThan(
			800 * 3,
			$ceiling,
			'A ceiling an article of the requested length cannot fit under is not a ceiling.'
		);
		$this->assertGreaterThan(
			Step_Propose_Topic::PROPOSAL_TOKENS,
			$ceiling,
			'The two are told apart by what they ask for, here and in the suite.'
		);
	}

	/**
	 * What the run reserves follows what the call asks for.
	 *
	 * The two were written out separately in three files and drifted apart once
	 * already. Raising the ceiling with the filter must raise the reservation
	 * with it, or the guard is checking a bound the call is free to exceed.
	 *
	 * @since 1.17.0
	 *
	 * @return void
	 */
	public function test_the_reservation_follows_the_ceiling(): void {
		$prompt = Prompt::load( $this->create_prompt() );
		$guard  = new Budget_Guard( new Pricing_Table() );
		$before = $guard->estimate_cents( $prompt );

		$raise = static function ( $ceiling ) {
			return $ceiling * 4;
		};

		add_filter( 'autoscribe_body_output_ceiling', $raise );

		$after = $guard->estimate_cents( $prompt );

		remove_filter( 'autoscribe_body_output_ceiling', $raise );

		$this->assertGreaterThan( $before, $after );
	}

	/**
	 * Runs the body step against a fresh run.
	 *
	 * @since 1.17.0
	 *
	 * @param Prompt $prompt Prompt to run.
	 * @return \AutoScribe\Content\Article|\WP_Error
	 */
	private function generate( Prompt $prompt ): \AutoScribe\Content\Article|\WP_Error {
		$run = Run::start( $prompt->id() );

		$this->assertNotWPError( $run );

		return ( new Step_Generate_Body( new Provider_Registry(), new Article_Validator() ) )->run(
			$prompt,
			$run,
			array(
				'title'     => 'Water Hardness And Extraction',
				'topic_key' => 'water-hardness-and-extraction',
			)
		);
	}

	/**
	 * Answers every call with an article that stops mid-word at the ceiling.
	 *
	 * @since 1.17.0
	 *
	 * @return void
	 */
	private function mock_truncated_body(): void {
		$this->install_responder(
			static function () {
				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'model'       => 'claude-opus-5',
							'stop_reason' => 'max_tokens',
							'content'     => array(
								array(
									'type' => 'text',
									'text' => '{"title":"Water Hardness And Extraction","content_html":"<h2>Minerals</h2><p>Magnesium pulls sweetn',
								),
							),
							'usage'       => array(
								'input_tokens'  => 100,
								'output_tokens' => 4096,
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
		);
	}
}
