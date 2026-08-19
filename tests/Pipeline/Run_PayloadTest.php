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
