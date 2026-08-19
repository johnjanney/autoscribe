<?php
/**
 * Run payload document tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Content\Article_Validator;
use AutoScribe\Pipeline\Run;
use AutoScribe\Tests\Support\Creates_Prompts;
use AutoScribe\Tests\Support\Mocks_Provider;
use WP_UnitTestCase;

/**
 * Covers the payload document the split pipeline will pass state through.
 *
 * Section 3.2 reserves runs.payload for "intermediate state between steps", and
 * section 5 has each step read its input from there and write its output back.
 * Until 1.1.0 the column had exactly one writer, record_sources(), which encoded
 * a fresh single-key object over whatever was already there. Correct while it
 * was the only writer; silently destructive the moment it was not.
 *
 * These tests fix the behaviour a second writer depends on, before there is a
 * second writer. See docs/PIPELINE-SPLIT.md phase 1.
 *
 * @since 1.1.0
 */
final class Run_PayloadTest extends WP_UnitTestCase {

	use Creates_Prompts;
	use Mocks_Provider;

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
	 * A second writer does not destroy the first one's key.
	 *
	 * This is the defect the method exists to prevent, written as a test before
	 * the split makes it reachable in production.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_writing_one_key_leaves_the_others_alone(): void {
		$run = $this->start_run();

		/*
		 * record_sources() goes last on purpose. It is the writer that used to
		 * encode a fresh object over the column, so it is the one that has to be
		 * shown merging into existing keys rather than replacing them. Writing it
		 * first would leave this test passing against the very code it exists to
		 * rule out, because the column starts empty and clobbering an empty
		 * column looks exactly like merging into one.
		 */
		$run->merge_payload( array( 'topic' => array( 'title' => 'Water Hardness' ) ) );
		$run->merge_payload( array( 'image' => array( 'attachment_id' => 42 ) ) );
		$run->record_sources( array( 'https://example.com/one' ) );

		$payload = $this->stored_payload( $run->id() );

		$this->assertSame( array( 'https://example.com/one' ), $payload['sources'] );
		$this->assertSame( array( 'title' => 'Water Hardness' ), $payload['topic'] );
		$this->assertSame( array( 'attachment_id' => 42 ), $payload['image'] );
	}

	/**
	 * Sources survive a later write, read back through the accessor.
	 *
	 * The previous test reads the column directly. This one goes through
	 * sources(), because that is what Step_Assemble_Post uses to append the
	 * section 7.1 sources block, and a cache that disagreed with the column
	 * would pass the first test and still publish the wrong thing.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_sources_survive_a_later_write(): void {
		$run = $this->start_run();

		$run->merge_payload( array( 'topic' => array( 'title' => 'Anything' ) ) );
		$run->record_sources( array( 'https://example.com/one', 'https://example.com/two' ) );
		$run->merge_payload( array( 'article' => array( 'title' => 'Anything' ) ) );

		$this->assertSame(
			array( 'https://example.com/one', 'https://example.com/two' ),
			$run->sources()
		);

		// And from a fresh read of the row, not the in-memory cache.
		$this->assertSame(
			array( 'https://example.com/one', 'https://example.com/two' ),
			$this->stored_payload( $run->id() )['sources']
		);
	}

	/**
	 * A key written twice is replaced, not merged into.
	 *
	 * A step owns its key outright. Merging recursively would let a retry leave
	 * half of the previous attempt's data underneath the new attempt's.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_key_is_replaced_rather_than_deep_merged(): void {
		$run = $this->start_run();

		$run->merge_payload(
			array(
				'article' => array(
					'title'   => 'First',
					'excerpt' => 'Gone',
				),
			)
		);
		$run->merge_payload( array( 'article' => array( 'title' => 'Second' ) ) );

		$this->assertSame(
			array( 'title' => 'Second' ),
			$this->stored_payload( $run->id() )['article']
		);
	}

	/**
	 * An empty payload reads as an empty array rather than failing.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_an_unwritten_payload_reads_as_empty(): void {
		$this->assertSame( array(), $this->start_run()->payload() );
	}

	/**
	 * An article survives a round trip through the payload.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_an_article_round_trips_through_the_payload(): void {
		$fields = $this->article_payload();
		$run    = $this->start_run();

		$run->merge_payload( array( 'article' => $fields ) );

		$rebuilt = ( new Article_Validator() )->from_array( $run->payload()['article'] );

		$this->assertNotWPError( $rebuilt );
		$this->assertSame( $fields['title'], $rebuilt->title() );
		$this->assertSame( $fields['content_html'], $rebuilt->raw_content_html() );
		$this->assertSame( $fields['suggested_tags'], $rebuilt->suggested_tags() );
		$this->assertSame( $fields, $rebuilt->to_array() );
	}

	/**
	 * Rebuilding re-validates rather than trusting what was stored.
	 *
	 * An Article exists only where the schema was satisfied. A payload row that
	 * was truncated, hand-edited, or written by an older version of the plugin
	 * is exactly where that stops being true on its own, and the split pipeline
	 * makes rebuilding from storage an ordinary event rather than a rare one.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_rebuilding_a_damaged_article_is_refused(): void {
		$fields = $this->article_payload();

		unset( $fields['content_html'] );

		$rebuilt = ( new Article_Validator() )->from_array( $fields );

		$this->assertWPError( $rebuilt );
		$this->assertSame( 'autoscribe_missing_fields', $rebuilt->get_error_code() );
	}

	/**
	 * A refused write leaves nothing cached that the database does not hold.
	 *
	 * The cache was assigned before the write was attempted, so a refused write
	 * left the object reporting keys the row did not contain. Phase 2 is what
	 * makes that expensive: the idempotency guards read this document to decide
	 * whether a step has already run, so a key that exists only in memory means
	 * a step skips work that was never persisted.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_refused_write_caches_nothing(): void {
		$run = $this->start_run();

		$run->merge_payload( array( 'topic' => array( 'title' => 'Kept' ) ) );

		$written = $this->without_payload_writes(
			static function () use ( $run ) {
				return $run->merge_payload( array( 'article' => array( 'title' => 'Lost' ) ) );
			}
		);

		$this->assertFalse( $written, 'merge_payload() should report the refused write.' );
		$this->assertArrayNotHasKey( 'article', $run->payload(), 'A refused write must not be cached.' );
		$this->assertSame( array( 'title' => 'Kept' ), $run->payload()['topic'] );
	}

	/**
	 * A later write does not resurrect a patch the database refused.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_a_refused_patch_is_not_persisted_by_a_later_write(): void {
		$run = $this->start_run();

		$run->merge_payload( array( 'topic' => array( 'title' => 'Kept' ) ) );

		$this->without_payload_writes(
			static function () use ( $run ) {
				return $run->merge_payload( array( 'article' => array( 'title' => 'Lost' ) ) );
			}
		);

		$run->merge_payload( array( 'image' => array( 'attachment_id' => 42 ) ) );

		$payload = $this->stored_payload( $run->id() );

		$this->assertArrayNotHasKey( 'article', $payload, 'The refused patch must not ride along on a later write.' );
		$this->assertSame( array( 'title' => 'Kept' ), $payload['topic'] );
		$this->assertSame( array( 'attachment_id' => 42 ), $payload['image'] );
	}

	/**
	 * Sources are not reported when the write that stored them was refused.
	 *
	 * The same defect one level up, and the one that reaches published content:
	 * Step_Assemble_Post reads sources() to append the section 7.1 sources block,
	 * so a cache the row does not back puts citations under an article that has
	 * no record of them.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_refused_sources_are_not_reported(): void {
		$run = $this->start_run();

		$stored = $this->without_payload_writes(
			static function () use ( $run ) {
				return $run->record_sources( array( 'https://example.com/one' ) );
			}
		);

		$this->assertFalse( $stored, 'record_sources() should report the refused write.' );
		$this->assertSame( array(), $run->sources(), 'A refused write must not be cached.' );
	}

	/**
	 * Runs a callback with every payload UPDATE redirected at a missing table.
	 *
	 * The same shape of failure a corrupt or missing runs table would produce.
	 *
	 * @since 1.1.0
	 *
	 * @param callable $callback Work to run while writes are refused.
	 * @return mixed Whatever the callback returned.
	 */
	private function without_payload_writes( callable $callback ) {
		global $wpdb;

		$break = static function ( $query ) {
			return str_contains( (string) $query, 'payload' ) && str_starts_with( ltrim( (string) $query ), 'UPDATE' )
				? 'UPDATE autoscribe_no_such_table SET payload = 1 WHERE id = 1'
				: $query;
		};

		add_filter( 'query', $break );
		$wpdb->suppress_errors( true );

		$result = $callback();

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $break );

		return $result;
	}

	/**
	 * Opens a run to write against.
	 *
	 * @since 1.1.0
	 *
	 * @return Run
	 */
	private function start_run(): Run {
		$run = Run::start( $this->create_prompt() );

		$this->assertNotWPError( $run );

		return $run;
	}

	/**
	 * Reads the payload column straight from the database.
	 *
	 * @since 1.1.0
	 *
	 * @param int $run_id Run to read.
	 * @return array<string, mixed>
	 */
	private function stored_payload( int $run_id ): array {
		global $wpdb;

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT payload FROM %i WHERE id = %d',
				\AutoScribe\Activation::table_name(),
				$run_id
			)
		);

		$decoded = json_decode( (string) $stored, true );

		$this->assertIsArray( $decoded, 'The payload column should hold a JSON object.' );

		return $decoded;
	}
}
