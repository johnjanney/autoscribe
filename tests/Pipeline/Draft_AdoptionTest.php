<?php
/**
 * Draft adoption tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Assemble_Post;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers which draft a retry is allowed to overwrite, and which it is not.
 *
 * Adoption exists so that a run retried after an image failure updates the draft
 * its previous attempt left behind instead of adding a second one. Version 1.0.1
 * implemented it as "the newest failed run of this prompt that still has a
 * post", which is a far wider net than a retry: once retries were exhausted the
 * draft stayed adoptable indefinitely, so the next ordinary scheduled
 * occurrence — a different article, days later — silently overwrote it, and a
 * reviewer part-way through editing it lost the work.
 *
 * These tests fix the boundary in both directions. The first proves adoption
 * still happens where it should; the rest prove it stops at every edge where
 * overwriting would destroy something.
 *
 * @since 1.0.2
 */
final class Draft_AdoptionTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Gives both providers a key so the pipeline reaches the image step.
	 *
	 * @since 1.0.2
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
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * A retry updates the draft its own previous attempt left behind.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_a_retry_adopts_the_previous_attempts_draft(): void {
		$prompt_id = $this->create_failing_image_prompt();

		$this->mock_text_success_and_image_failure();

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 1 );
		$first = $this->failed_post_id( $prompt_id );

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 2 );
		$second = $this->failed_post_id( $prompt_id );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( $first, $second, 'The retry should update the first attempt\'s draft, not create a second.' );
	}

	/**
	 * A first attempt never adopts anything.
	 *
	 * This is the case that produced the regression: a scheduled occurrence is
	 * always attempt 1, so the next run of a prompt whose retries were exhausted
	 * used to overwrite the abandoned draft with an unrelated article.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_a_later_scheduled_run_does_not_overwrite_an_abandoned_draft(): void {
		$prompt_id = $this->create_failing_image_prompt();

		$this->mock_text_success_and_image_failure();

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 1 );
		$abandoned = $this->failed_post_id( $prompt_id );

		// The next scheduled occurrence, days later, on a different topic. It is
		// attempt 1 again, so nothing about it belongs to the earlier retry series.
		$this->mock_text_success_and_image_failure(
			$this->article_payload(
				array(
					'title'     => 'Grinder Burr Alignment',
					'topic_key' => 'grinder-burr-alignment',
				)
			)
		);

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 1 );
		$fresh = $this->failed_post_id( $prompt_id );

		$this->assertGreaterThan( 0, $abandoned );
		$this->assertNotSame( $abandoned, $fresh, 'A new scheduled run must leave the earlier draft alone.' );
		$this->assertSame( 'Water Hardness And Extraction', get_post_field( 'post_title', $abandoned ) );
	}

	/**
	 * A draft someone has edited is left alone.
	 *
	 * The post is still a draft and still carries its run link, so every 1.0.1
	 * condition holds. Only the modification time says a person has been here.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_an_edited_draft_is_not_adopted(): void {
		$prompt_id = $this->create_failing_image_prompt();

		$this->mock_text_success_and_image_failure();

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 1 );
		$edited = $this->failed_post_id( $prompt_id );

		/*
		 * wp_insert_post() always stamps post_modified itself, so the later time
		 * has to be forced in at the last filter before the write. The point is
		 * simply that the draft was touched after the run that created it closed.
		 */
		$later = static function ( $data ) {
			$data['post_modified']     = gmdate( 'Y-m-d H:i:s', time() + 60 );
			$data['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', time() + 60 );

			return $data;
		};

		add_filter( 'wp_insert_post_data', $later );

		wp_update_post(
			array(
				'ID'           => $edited,
				'post_content' => '<p>A human rewrote the opening.</p>',
			)
		);

		remove_filter( 'wp_insert_post_data', $later );

		$this->assertNull( Run::adoptable_draft( $prompt_id, PHP_INT_MAX, 2 ) );
	}

	/**
	 * A draft the plugin has since published is left alone.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_a_published_post_is_not_adopted(): void {
		$prompt_id = $this->create_failing_image_prompt();

		$this->mock_text_success_and_image_failure();

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 1 );
		$post_id = $this->failed_post_id( $prompt_id );

		wp_publish_post( $post_id );

		$this->assertNull( Run::adoptable_draft( $prompt_id, PHP_INT_MAX, 2 ) );
	}

	/**
	 * An unrelated run between the two attempts ends the series.
	 *
	 * Two overlapping runs of the same prompt could otherwise both adopt the
	 * same draft and write different articles over each other.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_an_intervening_run_ends_the_series(): void {
		$prompt_id = $this->create_failing_image_prompt();

		$this->mock_text_success_and_image_failure();

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 1 );

		// Something else started a run for the same prompt in between.
		Run::start( $prompt_id, 1 );

		$this->assertNull( Run::adoptable_draft( $prompt_id, PHP_INT_MAX, 2 ) );
	}

	/**
	 * A post whose run link points elsewhere is left alone.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_a_relinked_post_is_not_adopted(): void {
		$prompt_id = $this->create_failing_image_prompt();

		$this->mock_text_success_and_image_failure();

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 1 );
		$post_id = $this->failed_post_id( $prompt_id );

		update_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, 999999 );

		$this->assertNull( Run::adoptable_draft( $prompt_id, PHP_INT_MAX, 2 ) );
	}

	/**
	 * Adoption survives a retry that fails before it reaches assembly.
	 *
	 * The ownership check asks whether the post's run meta names the row being
	 * adopted from. Only Step_Assemble_Post writes that meta, so a retry that
	 * adopts a draft and then falls over on the topic or body call left the run
	 * row pointing at a draft whose meta still named the attempt before it. The
	 * next attempt saw a mismatch, refused the draft, and eventually created a
	 * second one — which is the duplicate the whole mechanism exists to prevent.
	 *
	 * Attempt 1 leaves a draft. Attempt 2 adopts it and then fails on the body
	 * call. Attempt 3 must still adopt that same draft.
	 *
	 * @since 1.0.3
	 *
	 * @return void
	 */
	public function test_adoption_survives_a_retry_that_fails_before_assembly(): void {
		$prompt_id = $this->create_failing_image_prompt();

		$this->mock_text_success_and_image_failure();

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 1 );
		$draft = $this->failed_post_id( $prompt_id );

		// Attempt 2: the provider drops the connection during generation, so the
		// run never reaches assembly.
		$this->mock_provider_failure( 503 );

		( new Generator( new Provider_Registry() ) )->run( $prompt_id, null, 2 );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertSame( $draft, (int) $row['post_id'], 'The failed retry should still own the draft it adopted.' );

		// Attempt 3 must adopt the same draft rather than starting a second one.
		$this->assertSame( $draft, Run::adoptable_draft( $prompt_id, PHP_INT_MAX, 3 ) );
	}

	/**
	 * Builds a prompt whose image call will fail the run.
	 *
	 * Section 6's "required" mode fails the run and leaves the draft behind,
	 * which is exactly the state adoption exists for.
	 *
	 * @since 1.0.2
	 *
	 * @return int
	 */
	private function create_failing_image_prompt(): int {
		return $this->create_prompt(
			array(
				'image_mode'       => 'required',
				'image_provider'   => 'openai_image',
				'image_model'      => 'gpt-image-2',
				'post_status_mode' => 'auto',
			)
		);
	}

	/**
	 * Answers text calls successfully and image calls with a server error.
	 *
	 * The two are told apart by the request body: a text call carries messages,
	 * an image call carries a bare prompt.
	 *
	 * @since 1.0.2
	 *
	 * @param array<string, mixed> $article Article payload, or empty for the default.
	 * @return void
	 */
	private function mock_text_success_and_image_failure( array $article = array() ): void {
		$article  = array() === $article ? $this->article_payload() : $article;
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
						'body'     => '{"error":{"message":"image service down"}}',
						'response' => array(
							'code'    => 500,
							'message' => 'Server Error',
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

	/**
	 * Returns the post ID recorded by the prompt's most recent run.
	 *
	 * @since 1.0.2
	 *
	 * @param int $prompt_id Prompt to look up.
	 * @return int
	 */
	private function failed_post_id( int $prompt_id ): int {
		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_FAILED, $row['status'] );

		return (int) $row['post_id'];
	}
}
