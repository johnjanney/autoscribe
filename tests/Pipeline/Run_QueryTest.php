<?php
/**
 * Run log query tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Pipeline;

use AutoScribe\Activation;
use AutoScribe\Pipeline\Run;
use AutoScribe\Pipeline\Run_Retention;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers the section 9.3 run log query and the section 3.2 retention job.
 *
 * @since 0.7.0
 */
final class Run_QueryTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Inserts a run row directly, with full control over its columns.
	 *
	 * @since 0.7.0
	 *
	 * @param int    $prompt_id  Owning prompt.
	 * @param string $status     Run status.
	 * @param string $started_at UTC datetime.
	 * @return int Row ID.
	 */
	private function insert_run( int $prompt_id, string $status, string $started_at ): int {
		global $wpdb;

		$wpdb->insert(
			Activation::table_name(),
			array(
				'prompt_id'  => $prompt_id,
				'status'     => $status,
				'attempt'    => 1,
				'started_at' => $started_at,
			),
			array( '%d', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * With no filters, every row comes back newest first.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_unfiltered_query_returns_all_rows_newest_first(): void {
		$prompt_id = $this->create_prompt();

		$first  = $this->insert_run( $prompt_id, Run::STATUS_SUCCESS, '2026-05-01 10:00:00' );
		$second = $this->insert_run( $prompt_id, Run::STATUS_FAILED, '2026-05-02 10:00:00' );

		$rows = Run::query();

		$this->assertCount( 2, $rows );
		$this->assertSame( $second, (int) $rows[0]['id'] );
		$this->assertSame( $first, (int) $rows[1]['id'] );
		$this->assertSame( 2, Run::count() );
	}

	/**
	 * The prompt filter excludes other prompts.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_filters_by_prompt(): void {
		$wanted = $this->create_prompt();
		$other  = $this->create_prompt();

		$this->insert_run( $wanted, Run::STATUS_SUCCESS, '2026-05-01 10:00:00' );
		$this->insert_run( $other, Run::STATUS_SUCCESS, '2026-05-01 11:00:00' );

		$rows = Run::query( array( 'prompt_id' => $wanted ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( $wanted, (int) $rows[0]['prompt_id'] );
		$this->assertSame( 1, Run::count( array( 'prompt_id' => $wanted ) ) );
	}

	/**
	 * The status filter excludes other statuses.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_filters_by_status(): void {
		$prompt_id = $this->create_prompt();

		$this->insert_run( $prompt_id, Run::STATUS_SUCCESS, '2026-05-01 10:00:00' );
		$this->insert_run( $prompt_id, Run::STATUS_FAILED, '2026-05-01 11:00:00' );
		$this->insert_run( $prompt_id, Run::STATUS_SKIPPED_BUDGET, '2026-05-01 12:00:00' );

		$rows = Run::query( array( 'status' => Run::STATUS_FAILED ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( Run::STATUS_FAILED, $rows[0]['status'] );
		$this->assertSame( 1, Run::count( array( 'status' => Run::STATUS_FAILED ) ) );
	}

	/**
	 * The month filter respects the site timezone.
	 *
	 * The boundary row is the point of this test. Stored timestamps are UTC, so
	 * in a timezone behind UTC the last hours of a month are stored under the
	 * following month's date. Comparing the stored string against the month
	 * would put that run in the wrong month, and the spend total with it.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_filters_by_month_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'America/Chicago' );

		$prompt_id = $this->create_prompt();

		// 31 May 2026 20:00 in Chicago is 1 June 01:00 UTC. It belongs to May.
		$boundary = $this->insert_run( $prompt_id, Run::STATUS_SUCCESS, '2026-06-01 01:00:00' );

		// Mid-May, unambiguous.
		$middle = $this->insert_run( $prompt_id, Run::STATUS_SUCCESS, '2026-05-15 12:00:00' );

		// Mid-June, unambiguous.
		$june = $this->insert_run( $prompt_id, Run::STATUS_SUCCESS, '2026-06-15 12:00:00' );

		$may_ids = array_map( 'intval', wp_list_pluck( Run::query( array( 'month' => '2026-05' ) ), 'id' ) );

		$expected_may = array( $boundary, $middle );

		sort( $may_ids );
		sort( $expected_may );

		$this->assertSame( $expected_may, $may_ids );
		$this->assertSame( 2, Run::count( array( 'month' => '2026-05' ) ) );

		$june_ids = array_map( 'intval', wp_list_pluck( Run::query( array( 'month' => '2026-06' ) ), 'id' ) );

		$this->assertSame( array( $june ), $june_ids );
	}

	/**
	 * A malformed month is ignored rather than matching nothing.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_a_malformed_month_is_ignored(): void {
		$prompt_id = $this->create_prompt();

		$this->insert_run( $prompt_id, Run::STATUS_SUCCESS, '2026-05-15 12:00:00' );

		$this->assertCount( 1, Run::query( array( 'month' => 'not-a-month' ) ) );
		$this->assertCount( 1, Run::query( array( 'month' => '2026-13' ) ) );
	}

	/**
	 * Filters combine rather than replacing one another.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_filters_combine(): void {
		$wanted = $this->create_prompt();
		$other  = $this->create_prompt();

		$match = $this->insert_run( $wanted, Run::STATUS_FAILED, '2026-05-10 12:00:00' );

		$this->insert_run( $wanted, Run::STATUS_SUCCESS, '2026-05-10 12:00:00' );
		$this->insert_run( $other, Run::STATUS_FAILED, '2026-05-10 12:00:00' );
		$this->insert_run( $wanted, Run::STATUS_FAILED, '2026-04-10 12:00:00' );

		$rows = Run::query(
			array(
				'prompt_id' => $wanted,
				'status'    => Run::STATUS_FAILED,
				'month'     => '2026-05',
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( $match, (int) $rows[0]['id'] );
	}

	/**
	 * Pagination splits the result set and never overlaps.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_pagination_splits_the_result_set(): void {
		$prompt_id = $this->create_prompt();
		$ids       = array();

		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = $this->insert_run( $prompt_id, Run::STATUS_SUCCESS, sprintf( '2026-05-%02d 12:00:00', $i ) );
		}

		$page_one = Run::query(
			array(
				'per_page' => 2,
				'paged'    => 1,
			)
		);

		$page_two = Run::query(
			array(
				'per_page' => 2,
				'paged'    => 2,
			)
		);

		$page_three = Run::query(
			array(
				'per_page' => 2,
				'paged'    => 3,
			)
		);

		$this->assertCount( 2, $page_one );
		$this->assertCount( 2, $page_two );
		$this->assertCount( 1, $page_three );

		$seen = array_merge(
			wp_list_pluck( $page_one, 'id' ),
			wp_list_pluck( $page_two, 'id' ),
			wp_list_pluck( $page_three, 'id' )
		);

		$this->assertCount( 5, array_unique( $seen ) );

		// Newest first, so the last row inserted heads the first page.
		$this->assertSame( end( $ids ), (int) $page_one[0]['id'] );

		// The count is of matching rows, not of the page.
		$this->assertSame( 5, Run::count() );
	}

	/**
	 * The retention job deletes old rows and keeps recent ones.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_retention_deletes_only_rows_past_the_cutoff(): void {
		$prompt_id = $this->create_prompt();

		$old    = $this->insert_run( $prompt_id, Run::STATUS_SUCCESS, gmdate( 'Y-m-d H:i:s', time() - ( 120 * DAY_IN_SECONDS ) ) );
		$recent = $this->insert_run( $prompt_id, Run::STATUS_SUCCESS, gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) ) );

		update_option( 'autoscribe_settings', array( 'retention_days' => 90 ) );

		$deleted = Run_Retention::handle();

		$this->assertSame( 1, $deleted );

		$remaining = array_map( 'intval', wp_list_pluck( Run::query(), 'id' ) );

		$this->assertSame( array( $recent ), $remaining );
		$this->assertNotContains( $old, $remaining );
	}

	/**
	 * A retention of zero days keeps everything.
	 *
	 * Zero has to mean "keep everything" rather than "delete everything older
	 * than now", which would silently wipe the log the first time it ran.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_zero_retention_deletes_nothing(): void {
		$prompt_id = $this->create_prompt();

		$this->insert_run( $prompt_id, Run::STATUS_SUCCESS, gmdate( 'Y-m-d H:i:s', time() - ( 900 * DAY_IN_SECONDS ) ) );

		update_option( 'autoscribe_settings', array( 'retention_days' => 0 ) );

		$this->assertSame( 0, Run_Retention::handle() );
		$this->assertCount( 1, Run::query() );
	}
}
