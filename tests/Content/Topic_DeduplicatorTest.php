<?php
/**
 * Duplicate topic detection tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Content;

use AutoScribe\Content\Topic_Deduplicator;
use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers section 7.2 duplicate avoidance.
 *
 * The central test proves the rejection happens before the body call by
 * inspecting every outgoing request. A proposal call asks for 512 tokens and a
 * two-field schema; a body call asks for the full article schema containing
 * content_html. Asserting that no captured request carries content_html is
 * direct evidence that the expensive call never happened, rather than an
 * inference from a status string.
 *
 * @since 0.5.0
 */
final class Topic_DeduplicatorTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Every outgoing request captured during a test.
	 *
	 * @since 0.5.0
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = array();

	/**
	 * The registered mock, so it can be removed cleanly.
	 *
	 * @since 0.5.0
	 * @var callable|null
	 */
	private $mock;

	/**
	 * Resets capture state.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->requests = array();
		$this->mock     = null;

		/*
		 * Provide the key the suite needs rather than relying on the wp-config
		 * constants that .wp-env.json happens to define. Without this the test
		 * passes locally and fails in CI, where no wp-config exists, for an
		 * environmental reason rather than a real one. Key_Store still prefers a
		 * constant when one is present, so this is a floor, not an override.
		 */
		Key_Store::set( 'anthropic', 'test-key' );
	}

	/**
	 * Removes the mock so the tripwire is armed again.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->mock ) {
			remove_filter( 'pre_http_request', $this->mock, 10 );
			$this->mock = null;
		}

		parent::tear_down();
	}

	/**
	 * Answers every provider call with the same topic proposal.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, string> $proposal Title and topic key to return.
	 * @return void
	 */
	private function mock_proposal( array $proposal ): void {
		$this->mock = function ( $preempt, $args, $url ) use ( $proposal ) {
			unset( $preempt );

			$this->requests[] = array(
				'url'  => $url,
				'body' => json_decode( (string) $args['body'], true ),
			);

			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => (string) wp_json_encode( $proposal ),
							),
						),
						'usage'   => array(
							'input_tokens'  => 40,
							'output_tokens' => 12,
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
		};

		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	/**
	 * A repeated topic is rejected, and the body call never happens.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_duplicate_topic_is_rejected_before_the_body_call(): void {
		$this->create_covered_post( 'Why Espresso Pressure Matters', 'espresso-pressure-basics' );

		$prompt_id = $this->create_prompt();

		$this->mock_proposal(
			array(
				'title'     => 'Why Espresso Pressure Matters',
				'topic_key' => 'espresso-pressure-basics',
			)
		);

		$result = ( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_duplicate_topic', $result->get_error_code() );

		// One proposal, one re-ask naming the collision, then stop.
		$this->assertCount( 2, $this->requests );

		foreach ( $this->requests as $index => $request ) {
			$encoded = (string) wp_json_encode( $request['body'] );

			$this->assertStringNotContainsString( 'content_html', $encoded, 'request ' . $index );
			$this->assertSame( 512, $request['body']['max_tokens'], 'request ' . $index );
		}

		// No generated post was written. Every post the pipeline creates carries
		// the run-id meta from section 10, so its absence is conclusive.
		$generated = array();

		foreach ( get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 50,
			)
		) as $post ) {
			if ( '' !== (string) get_post_meta( $post->ID, \AutoScribe\Pipeline\Step_Assemble_Post::RUN_ID_META, true ) ) {
				$generated[] = $post->ID;
			}
		}

		$this->assertSame( array(), $generated );
	}

	/**
	 * The re-ask names the collision so the model can avoid it.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_the_reask_explains_the_collision(): void {
		$this->create_covered_post( 'Why Espresso Pressure Matters', 'espresso-pressure-basics' );

		$this->mock_proposal(
			array(
				'title'     => 'Why Espresso Pressure Matters',
				'topic_key' => 'espresso-pressure-basics',
			)
		);

		( new Generator( new Provider_Registry() ) )->run( $this->create_prompt() );

		$second = (string) wp_json_encode( $this->requests[1]['body'] );

		$this->assertStringContainsString( 'previous proposal was rejected', $second );
	}

	/**
	 * A genuinely new topic is allowed straight through.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_a_new_topic_is_not_rejected(): void {
		$this->create_covered_post( 'Grinder Burr Alignment', 'grinder-burr-alignment' );

		$deduplicator = new Topic_Deduplicator();
		$existing     = $deduplicator->recent_topics( 'post', array(), 50 );

		$this->assertNull(
			$deduplicator->collision_reason( 'water-hardness-and-extraction', 'Water Hardness And Extraction', $existing )
		);
	}

	/**
	 * Drafts count as covering a topic.
	 *
	 * Section 7.2 says to query published posts, but section 10's review mode
	 * saves generated posts as drafts. On that configuration, which the brief
	 * itself recommends, a published-only query would never see anything and the
	 * same article would be regenerated at full price on every run.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_drafts_count_as_covered(): void {
		$this->create_covered_post( 'A Drafted Article', 'a-drafted-article', 'draft' );

		$existing = ( new Topic_Deduplicator() )->recent_topics( 'post', array(), 50 );

		$this->assertArrayHasKey( 'a-drafted-article', $existing );
	}

	/**
	 * Pending and scheduled posts count too.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_pending_and_future_posts_count_as_covered(): void {
		$this->create_covered_post( 'Pending One', 'pending-one', 'pending' );
		$this->create_covered_post( 'Future One', 'future-one', 'future' );

		$existing = ( new Topic_Deduplicator() )->recent_topics( 'post', array(), 50 );

		$this->assertArrayHasKey( 'pending-one', $existing );
		$this->assertArrayHasKey( 'future-one', $existing );
	}

	/**
	 * An exact topic key match collides.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_exact_key_match_collides(): void {
		$reason = ( new Topic_Deduplicator() )->collision_reason(
			'espresso-pressure',
			'Anything',
			array( 'espresso-pressure' => 'Existing Title' )
		);

		$this->assertIsString( $reason );
		$this->assertStringContainsString( 'espresso-pressure', $reason );
	}

	/**
	 * A near-identical key collides on similarity.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_similar_key_collides(): void {
		$reason = ( new Topic_Deduplicator() )->collision_reason(
			'espresso-pressure-basic',
			'Anything',
			array( 'espresso-pressure-basics' => 'Existing Title' )
		);

		$this->assertIsString( $reason );
	}

	/**
	 * An existing title collides even when the key differs.
	 *
	 * The brief calls post_exists() here, which is unavailable outside the
	 * admin and would be a fatal inside an Action Scheduler run.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_existing_title_collides(): void {
		$this->create_covered_post( 'An Existing Headline', 'some-other-key' );

		$reason = ( new Topic_Deduplicator() )->collision_reason(
			'a-totally-different-key',
			'An Existing Headline',
			array()
		);

		$this->assertIsString( $reason );
		$this->assertStringContainsString( 'An Existing Headline', $reason );
	}

	/**
	 * The threshold is filterable, as section 7.2 requires.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_threshold_is_filterable(): void {
		$deduplicator = new Topic_Deduplicator();

		$this->assertSame( Topic_Deduplicator::DEFAULT_THRESHOLD, $deduplicator->threshold() );

		add_filter( 'autoscribe_topic_similarity_threshold', static fn() => 95 );

		$this->assertSame( 95, $deduplicator->threshold() );

		remove_all_filters( 'autoscribe_topic_similarity_threshold' );
	}

	/**
	 * A rejected run is recorded as skipped_duplicate.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_rejected_run_is_recorded_as_skipped_duplicate(): void {
		$this->create_covered_post( 'Why Espresso Pressure Matters', 'espresso-pressure-basics' );
		$prompt_id = $this->create_prompt();

		$this->mock_proposal(
			array(
				'title'     => 'Why Espresso Pressure Matters',
				'topic_key' => 'espresso-pressure-basics',
			)
		);

		( new Generator( new Provider_Registry() ) )->run( $prompt_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_SKIPPED_DUPLICATE, $row['status'] );
	}
}
