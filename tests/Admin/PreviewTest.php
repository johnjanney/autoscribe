<?php
/**
 * Preview control tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Admin;

use AutoScribe\Admin\Actions;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Assemble_Post;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers section 9.2's Preview control.
 *
 * The point of Preview is that it shows what a prompt would produce without
 * producing it. A preview that quietly created a draft would be worse than no
 * preview at all, so the central assertion is the absence of a post rather than
 * the presence of an article.
 *
 * @since 0.8.0
 */
final class PreviewTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

	/**
	 * Provides the API key the pipeline needs.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Key_Store::set( 'anthropic', 'test-key' );
	}

	/**
	 * Removes the mock so the tripwire is armed again.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_provider_mock();

		parent::tear_down();
	}

	/**
	 * Builds the handler under test.
	 *
	 * @since 0.8.0
	 *
	 * @return Actions
	 */
	private function actions(): Actions {
		return new Actions( new Provider_Registry(), new Scheduler() );
	}

	/**
	 * Counts posts carrying the generated-run marker.
	 *
	 * @since 0.8.0
	 *
	 * @return int
	 */
	private function generated_post_count(): int {
		$count = 0;

		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			if ( '' !== (string) get_post_meta( (int) $post_id, Step_Assemble_Post::RUN_ID_META, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Preview returns an article and creates no post.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_preview_produces_an_article_without_creating_a_post(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$article = $this->actions()->preview( $prompt_id );

		$this->assertNotWPError( $article );
		$this->assertSame( 'Water Hardness And Extraction', $article->title() );
		$this->assertStringContainsString( 'Magnesium', $article->raw_content_html() );

		$this->assertSame( 0, $this->generated_post_count(), 'preview created a post' );
	}

	/**
	 * Preview is logged as a run and charged, per section 9.2.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_preview_is_logged_and_charged(): void {
		$this->mock_provider_success();

		$prompt_id = $this->create_prompt();

		$this->actions()->preview( $prompt_id );

		$row = Run::latest_for_prompt( $prompt_id );

		$this->assertIsArray( $row );
		$this->assertSame( Run::STATUS_SUCCESS, $row['status'] );
		$this->assertSame( 'preview', $row['step'] );
		$this->assertNull( $row['post_id'] );
		$this->assertGreaterThan( 0, (int) $row['input_tokens'] );
		$this->assertGreaterThan( 0, (int) $row['cost_cents'] );
	}

	/**
	 * A provider failure during preview is returned, not thrown.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_provider_failure_returns_an_error(): void {
		$this->mock_provider_failure( 500 );

		$result = $this->actions()->preview( $this->create_prompt() );

		$this->assertWPError( $result );
		$this->assertSame( 0, $this->generated_post_count() );
	}

	/**
	 * Previewing an unknown prompt returns an error rather than failing.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_an_unknown_prompt_returns_an_error(): void {
		$result = $this->actions()->preview( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_unknown_prompt', $result->get_error_code() );
	}
}
