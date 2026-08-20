<?php
/**
 * Tests for the repair attempt on an unreadable topic proposal.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Content\Topic_Deduplicator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Propose_Topic;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * What happens when the model does not answer with the object it was asked for.
 *
 * Section 5.1 allows one repair request per run on a validation failure. The
 * body step made it and the proposal step did not, so a proposal that came back
 * as a preamble, a refusal, or a fragment ended the run — and an unusable
 * response is permanent as far as Retry_Policy is concerned, correctly, since a
 * scheduled retry would send the identical request. A live site lost its article
 * for 20 August 2026 that way.
 *
 * These tests pin the repair and its limit: exactly one per run, whichever
 * proposal provoked it, and the second call is paid for and counted.
 *
 * @since 1.13.1
 */
final class Proposal_RepairTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Text the provider returns, one entry per call.
	 *
	 * @since 1.13.1
	 * @var string[]
	 */
	private array $replies = array();

	/**
	 * Gives the provider a key so the step reaches its call.
	 *
	 * @since 1.13.1
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
	 * @since 1.13.1
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * A proposal that is not JSON is asked for again, and the run continues.
	 *
	 * @since 1.13.1
	 *
	 * @return void
	 */
	public function test_a_proposal_that_is_not_json_is_repaired(): void {
		$this->mock_replies(
			array(
				'Sure! Here is a great topic for you to write about today.',
				(string) wp_json_encode(
					array(
						'title'     => 'Grinding for Filter Coffee',
						'topic_key' => 'grinding-for-filter',
					)
				),
			)
		);

		$proposal = $this->propose();

		$this->assertSame(
			array(
				'title'     => 'Grinding for Filter Coffee',
				'topic_key' => 'grinding-for-filter',
			),
			$proposal,
			'One malformed answer must not end the run.'
		);
		$this->assertCount( 2, $this->captured_requests() );
	}

	/**
	 * The repair names what was wrong and quotes what was rejected.
	 *
	 * @since 1.13.1
	 *
	 * @return void
	 */
	public function test_the_repair_quotes_the_rejected_response(): void {
		$this->mock_replies(
			array(
				'Sure! Here is a great topic for you to write about today.',
				(string) wp_json_encode(
					array(
						'title'     => 'Grinding for Filter Coffee',
						'topic_key' => 'grinding-for-filter',
					)
				),
			)
		);

		$this->propose();

		$requests = $this->captured_requests();
		$second   = (string) wp_json_encode( $requests[1]['body'] );

		$this->assertStringContainsString( 'Here is a great topic', $second, 'The model is shown what it sent.' );
		$this->assertStringContainsString( 'was not JSON', $second, 'And told what was wrong with it.' );
		$this->assertStringContainsString(
			'rejected_response',
			$second,
			'Its own rejected output is fenced as data, like every other untrusted string.'
		);
	}

	/**
	 * Exactly one repair is bought, whatever the second answer looks like.
	 *
	 * @since 1.13.1
	 *
	 * @return void
	 */
	public function test_only_one_repair_is_paid_for(): void {
		$this->mock_replies(
			array(
				'Sure! Here is a great topic.',
				'Of course — I would suggest writing about coffee.',
			)
		);

		$result = $this->propose();

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_invalid_json', $result->get_error_code() );
		$this->assertCount( 2, $this->captured_requests(), 'A repair that fails is not repaired again.' );
	}

	/**
	 * The one repair is spent for the run, not for each proposal attempt.
	 *
	 * A collision re-ask is a second proposal call, and the guard has to survive
	 * it: a run that repaired its first answer must not buy another repair when
	 * the re-ask comes back malformed too.
	 *
	 * @since 1.13.1
	 *
	 * @return void
	 */
	public function test_the_repair_allowance_belongs_to_the_run(): void {
		$this->create_covered_post( 'Grinding for Filter Coffee', 'grinding-for-filter' );

		$this->mock_replies(
			array(
				// Proposal, unreadable.
				'Sure! Here is a great topic.',
				// Repair, readable but already covered.
				(string) wp_json_encode(
					array(
						'title'     => 'Grinding for Filter Coffee',
						'topic_key' => 'grinding-for-filter',
					)
				),
				// The collision re-ask, unreadable again.
				'Let me think about that differently.',
			)
		);

		$result = $this->propose();

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_invalid_json', $result->get_error_code() );
		$this->assertCount( 3, $this->captured_requests(), 'One repair for the run, not one per attempt.' );
	}

	/**
	 * The repair call's tokens are counted against the budget.
	 *
	 * @since 1.13.1
	 *
	 * @return void
	 */
	public function test_the_repair_call_is_counted(): void {
		$this->mock_replies(
			array(
				'Sure! Here is a great topic.',
				(string) wp_json_encode(
					array(
						'title'     => 'Grinding for Filter Coffee',
						'topic_key' => 'grinding-for-filter',
					)
				),
			)
		);

		$prompt = Prompt::load( $this->create_prompt() );
		$run    = Run::start( $prompt->id() );

		$this->assertNotWPError( $run );
		$this->assertIsArray( ( new Step_Propose_Topic( new Provider_Registry(), new Topic_Deduplicator() ) )->run( $prompt, $run ) );

		$row = Run::latest_for_prompt( $prompt->id() );

		$this->assertSame( 200, (int) $row['input_tokens'], 'Both calls were charged for.' );
		$this->assertSame( 800, (int) $row['output_tokens'] );
	}

	/**
	 * A proposal cut off mid-object says so, rather than "not JSON".
	 *
	 * The failure this replaces reported four words to the Run Log, which was not
	 * enough to tell a refusal from a truncation — and the two want opposite
	 * responses from whoever reads it.
	 *
	 * @since 1.13.1
	 *
	 * @return void
	 */
	public function test_a_proposal_cut_off_mid_object_says_so(): void {
		$this->mock_replies(
			array(
				'{"title": "Grinding for Filter Coffee", "topic_key": "grinding',
				'{"title": "Grinding for Filter Coffee", "topic_key": "grinding',
			)
		);

		$result = $this->propose();

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'stopped before the JSON was closed', $result->get_error_message() );
		$this->assertStringContainsString( 'Grinding for Filter Coffee', $result->get_error_message() );
	}

	/**
	 * An empty response is reported as empty.
	 *
	 * @since 1.13.1
	 *
	 * @return void
	 */
	public function test_an_empty_proposal_says_so(): void {
		$this->mock_replies( array( '   ', '   ' ) );

		$result = $this->propose();

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'empty topic proposal', $result->get_error_message() );
	}

	/**
	 * Runs the proposal step against a fresh prompt and run.
	 *
	 * @since 1.13.1
	 *
	 * @return array{title: string, topic_key: string}|\WP_Error
	 */
	private function propose(): array|\WP_Error {
		$prompt = Prompt::load( $this->create_prompt() );
		$run    = Run::start( $prompt->id() );

		$this->assertNotWPError( $run );

		return ( new Step_Propose_Topic( new Provider_Registry(), new Topic_Deduplicator() ) )->run( $prompt, $run );
	}

	/**
	 * Answers each call with the next reply, as raw model text.
	 *
	 * The trait's helpers encode a payload as JSON, which is the one thing these
	 * tests need not to happen: what is under test is what the step does with
	 * text that is not the object it asked for.
	 *
	 * @since 1.13.1
	 *
	 * @param string[] $replies One per call, in order.
	 * @return void
	 */
	private function mock_replies( array $replies ): void {
		$this->replies = $replies;

		$this->install_responder(
			function () {
				$text = array_shift( $this->replies );

				if ( null === $text ) {
					$this->fail( 'The step made more provider calls than the test allows for.' );
				}

				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'content' => array(
								array(
									'type' => 'text',
									'text' => $text,
								),
							),
							'usage'   => array(
								'input_tokens'  => 100,
								'output_tokens' => 400,
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

	/**
	 * Publishes a post the deduplicator will see as already covered.
	 *
	 * @since 1.13.1
	 *
	 * @param string $title     Post title.
	 * @param string $topic_key Topic key stored on it.
	 * @return void
	 */
	private function create_covered_post( string $title, string $topic_key ): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $post_id, '_autoscribe_topic_key', $topic_key );
	}
}
