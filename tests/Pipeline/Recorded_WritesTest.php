<?php
/**
 * Tests for the writes a finished post depends on.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Pipeline\Generator;
use AutoScribe\Pipeline\Queued_Run_Handler;
use AutoScribe\Pipeline\Retry_Policy;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Step_Assemble_Post;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers assembly writes that used to succeed silently when they failed.
 *
 * Section 10 requires the run link on every generated post, section 7.2 reads
 * the topic key back when deciding whether a later run is repeating itself, and
 * section 7.3 asks for categories, tags, and SEO metadata. Every one of those
 * writes was made and none was inspected, so a post could be published with no
 * link to what produced it, no deduplication memory, no categories, and no SEO
 * metadata, while the run reported success.
 *
 * @since 1.2.0
 */
final class Recorded_WritesTest extends WP_UnitTestCase {

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
	 * A post that cannot be linked to its run is not published.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_post_without_its_run_link_is_left_as_a_draft(): void {
		$refuse = static function ( $value, $object_id, $meta_key ) {
			return Step_Assemble_Post::RUN_ID_META === $meta_key ? false : $value;
		};

		add_filter( 'update_post_metadata', $refuse, 10, 3 );

		$row = $this->run_prompt( $this->create_prompt() );

		remove_filter( 'update_post_metadata', $refuse, 10 );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertStringContainsString( 'linked back to this run', (string) $row['error'] );
		$this->assertDraftOrNothing( $row );
	}

	/**
	 * A post whose SEO metadata will not write is not published.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_post_without_its_seo_metadata_is_left_as_a_draft(): void {
		$refuse = static function ( $value, $object_id, $meta_key ) {
			return str_starts_with( (string) $meta_key, '_autoscribe_seo' ) ? false : $value;
		};

		add_filter( 'update_post_metadata', $refuse, 10, 3 );

		$row = $this->run_prompt( $this->create_prompt() );

		remove_filter( 'update_post_metadata', $refuse, 10 );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertStringContainsString( 'SEO metadata', (string) $row['error'] );
		$this->assertDraftOrNothing( $row );
	}

	/**
	 * A post whose taxonomy will not apply is not published.
	 *
	 * Categories come from the prompt and the model is never allowed to invent
	 * one, so a post that silently loses its taxonomy is filed where nobody
	 * configured it to go. The reachable version of that failure is a term
	 * WordPress cannot create: `wp_set_post_terms()` reports it, and until 1.2.0
	 * nothing read the answer.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_post_without_its_taxonomy_is_left_as_a_draft(): void {
		global $wpdb;

		$prompt_id = $this->create_prompt( array( 'tag_mode' => 'ai' ) );

		// A term the database will not accept, which is what a full disk or a
		// corrupt terms table looks like from here.
		$break = static function ( $query ) use ( $wpdb ) {
			$sql = (string) $query;

			return str_contains( $sql, 'INSERT INTO `' . $wpdb->terms . '`' )
				? 'INSERT INTO autoscribe_no_such_table (id) VALUES (1)'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$row = $this->run_prompt( $prompt_id );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertStringContainsString( 'tags', (string) $row['error'] );
		$this->assertDraftOrNothing( $row );
	}

	/**
	 * A post whose term relationship is refused is not published.
	 *
	 * `wp_set_post_terms()` returns the term-taxonomy IDs it meant to write and
	 * does not inspect the insert that writes them, so a refused relationship
	 * produces the same array a successful one does. The only answer that tells
	 * the two apart is to ask the post what it has afterwards.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_post_whose_terms_do_not_stick_is_left_as_a_draft(): void {
		global $wpdb;

		$category  = self::factory()->category->create( array( 'name' => 'Brewing' ) );
		$prompt_id = $this->create_prompt( array( 'category_ids' => array( $category ) ) );

		$break = static function ( $query ) use ( $wpdb ) {
			$sql = (string) $query;

			return str_starts_with( ltrim( $sql ), 'INSERT INTO `' ) && str_contains( $sql, $wpdb->term_relationships )
				? 'INSERT INTO autoscribe_no_such_table (id) VALUES (1)'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$row = $this->run_prompt( $prompt_id );

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertStringContainsString( 'categories', (string) $row['error'] );
		$this->assertStringContainsString( 'Missing after the write', (string) $row['error'] );
		$this->assertDraftOrNothing( $row );
	}

	/**
	 * A category deleted after the prompt was saved is not silently dropped.
	 *
	 * WordPress skips a term ID that no longer exists and reports nothing, so the
	 * post is filed nowhere while the run reports success.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_a_deleted_category_is_not_silently_dropped(): void {
		$category = self::factory()->category->create( array( 'name' => 'Brewing' ) );

		$prompt_id = $this->create_prompt( array( 'category_ids' => array( $category ) ) );

		wp_delete_category( $category );

		$row = $this->run_prompt( $prompt_id );

		$this->assertSame( Run::STATUS_FAILED, $row['status'] );
		$this->assertStringContainsString( 'categories', (string) $row['error'] );
		$this->assertDraftOrNothing( $row );
	}

	/**
	 * The ordinary path still writes everything the failures above describe.
	 *
	 * A guard that fails closed is only useful if the thing it guards normally
	 * succeeds, so this is the other half of the tests above.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_finished_post_carries_its_run_link_and_metadata(): void {
		$category = self::factory()->category->create( array( 'name' => 'Brewing' ) );

		$row = $this->run_prompt(
			$this->create_prompt(
				array(
					'category_ids' => array( $category ),
					'tag_mode'     => 'ai',
				)
			)
		);

		$this->assertSame( Run::STATUS_SUCCESS, $row['status'] );

		$post_id = (int) $row['post_id'];

		$this->assertSame(
			(string) $row['id'],
			(string) get_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, true )
		);
		$this->assertSame(
			'water-hardness-and-extraction',
			(string) get_post_meta( $post_id, Step_Assemble_Post::TOPIC_KEY_META, true )
		);
		$this->assertSame(
			'Water Hardness And Extraction',
			(string) get_post_meta( $post_id, '_autoscribe_seo_title', true )
		);
		$this->assertContains( $category, wp_get_post_categories( $post_id ) );
		$this->assertSame( array( 'water' ), wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ) );
		$this->assertSame( 'Water Hardness And Extraction', (string) $row['title'] );
	}

	/**
	 * Asserts the post the run produced was not published.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $row Run row.
	 * @return void
	 */
	private function assertDraftOrNothing( array $row ): void {
		$post_id = (int) $row['post_id'];

		if ( $post_id > 0 ) {
			$this->assertSame( 'draft', get_post_status( $post_id ) );
		}
	}

	/**
	 * Runs a prompt the way the queue does and returns its run row.
	 *
	 * @since 1.2.0
	 *
	 * @param int $prompt_id Prompt to run.
	 * @return array<string, mixed>
	 */
	private function run_prompt( int $prompt_id ): array {
		$this->mock_provider_success();

		$handler = new Queued_Run_Handler(
			new Generator( new Provider_Registry() ),
			new Scheduler(),
			new Retry_Policy()
		);

		$handler->handle( $prompt_id );

		$run_id = (int) Run::latest_for_prompt( $prompt_id )['id'];

		for ( $i = 0; $i < 8; $i++ ) {
			$row = Run::latest_for_prompt( $prompt_id );

			if ( Run::STATUS_RUNNING !== $row['status'] ) {
				return $row;
			}

			$handler->handle_step( $run_id );
		}

		return Run::latest_for_prompt( $prompt_id );
	}
}
