<?php
/**
 * Tests for the Prompts screen columns.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Admin;

use AutoScribe\Admin\Prompt_Columns;
use AutoScribe\Scheduling\Schedule;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * What the Repeat, Time of day, and Category columns say.
 *
 * The two schedule types with no clock time get most of the attention here.
 * They are the reason the column exists in the shape it does: an empty cell
 * would read as an unconfigured prompt rather than as a schedule that has no
 * time of day, and a cron expression that does name one should not be treated
 * as though it does not.
 *
 * @since 1.18.0
 */
final class Prompt_ColumnsTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Registers the hooks, which is_admin() gates in production.
	 *
	 * The post-state tests go through apply_filters() rather than calling the
	 * method, so that a hook wired to the wrong name fails here rather than in
	 * the one place nobody looks: a list table that renders without complaint
	 * and is simply missing the marker.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Prompt_Columns() )->register();
	}

	/**
	 * The three columns land immediately after the title.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_the_columns_follow_the_title(): void {
		$columns = ( new Prompt_Columns() )->columns(
			array(
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame(
			array( 'cb', 'title', Prompt_Columns::COLUMN_REPEAT, Prompt_Columns::COLUMN_TIME, Prompt_Columns::COLUMN_CATEGORIES, 'date' ),
			array_keys( $columns )
		);
	}

	/**
	 * A weekly schedule names its weekday and its time.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_weekly_schedule_names_the_day_and_the_time(): void {
		$prompt_id = $this->schedule(
			Schedule::TYPE_WEEKLY,
			array(
				'time'    => '06:30',
				'weekday' => 'tuesday',
			)
		);

		$this->assertSame( 'Every Tuesday', $this->cell( Prompt_Columns::COLUMN_REPEAT, $prompt_id ) );
		$this->assertStringContainsString( '6:30 am', $this->cell( Prompt_Columns::COLUMN_TIME, $prompt_id ) );
	}

	/**
	 * An ordinal schedule reads as a sentence.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_an_ordinal_schedule_reads_as_a_sentence(): void {
		$prompt_id = $this->schedule(
			Schedule::TYPE_MONTHLY_ORDINAL,
			array(
				'time'    => '06:00',
				'ordinal' => 'second',
				'weekday' => 'tuesday',
			)
		);

		$this->assertSame( 'Second Tuesday each month', $this->cell( Prompt_Columns::COLUMN_REPEAT, $prompt_id ) );
	}

	/**
	 * An interval says the time moves, rather than leaving the cell blank.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_an_interval_says_the_time_moves(): void {
		$prompt_id = $this->schedule( Schedule::TYPE_INTERVAL, array( 'hours' => 72 ) );

		$this->assertSame( 'Every 72 hours', $this->cell( Prompt_Columns::COLUMN_REPEAT, $prompt_id ) );

		$time = $this->cell( Prompt_Columns::COLUMN_TIME, $prompt_id );

		$this->assertStringContainsString( 'Varies', $time );
		$this->assertStringContainsString( 'counts from the last run', $time );
	}

	/**
	 * A cron expression with one fixed hour is answered with that hour.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_fixed_cron_hour_is_shown_as_a_time(): void {
		$prompt_id = $this->schedule( Schedule::TYPE_CRON, array( 'expression' => '30 6 * * 1' ) );

		$this->assertStringContainsString( '30 6 * * 1', $this->cell( Prompt_Columns::COLUMN_REPEAT, $prompt_id ) );
		$this->assertStringContainsString( '6:30 am', $this->cell( Prompt_Columns::COLUMN_TIME, $prompt_id ) );
	}

	/**
	 * A cron expression naming two hours lists both.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_cron_expression_with_two_hours_lists_both(): void {
		$prompt_id = $this->schedule( Schedule::TYPE_CRON, array( 'expression' => '0 6,18 * * *' ) );

		$time = $this->cell( Prompt_Columns::COLUMN_TIME, $prompt_id );

		$this->assertStringContainsString( '6:00 am', $time );
		$this->assertStringContainsString( '6:00 pm', $time );
	}

	/**
	 * A cron expression with a step has no time of day to show.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_stepped_cron_expression_varies(): void {
		$prompt_id = $this->schedule( Schedule::TYPE_CRON, array( 'expression' => '*/15 * * * *' ) );

		$time = $this->cell( Prompt_Columns::COLUMN_TIME, $prompt_id );

		$this->assertStringContainsString( 'Varies', $time );
		$this->assertStringContainsString( 'no single fixed hour', $time );
	}

	/**
	 * A chosen category is linked to the posts filed under it.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_chosen_category_is_linked(): void {
		$category_id = self::factory()->category->create( array( 'name' => 'Peptides' ) );
		$prompt_id   = $this->create_prompt( array( 'category_ids' => array( $category_id ) ) );

		$cell = $this->cell( Prompt_Columns::COLUMN_CATEGORIES, $prompt_id );

		$this->assertStringContainsString( 'Peptides', $cell );
		$this->assertStringContainsString( 'cat=' . $category_id, $cell );
	}

	/**
	 * A prompt with no category shows the default WordPress will actually use.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_no_category_shows_the_site_default(): void {
		$cell = $this->cell( Prompt_Columns::COLUMN_CATEGORIES, $this->create_prompt() );

		$this->assertStringContainsString(
			get_cat_name( (int) get_option( 'default_category' ) ),
			$cell
		);
		$this->assertStringContainsString( 'Site default', $cell );
	}

	/**
	 * A category deleted after the prompt was saved falls back to the default.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_deleted_category_falls_back_to_the_default(): void {
		$category_id = self::factory()->category->create();
		$prompt_id   = $this->create_prompt( array( 'category_ids' => array( $category_id ) ) );

		wp_delete_term( $category_id, 'category' );

		$this->assertStringContainsString( 'Site default', $this->cell( Prompt_Columns::COLUMN_CATEGORIES, $prompt_id ) );
	}

	/**
	 * A prompt that writes pages says categories do not apply.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_page_prompt_says_categories_do_not_apply(): void {
		$prompt_id = $this->create_prompt( array( 'post_type' => 'page' ) );

		$this->assertStringContainsString(
			'Pages have no categories',
			$this->cell( Prompt_Columns::COLUMN_CATEGORIES, $prompt_id )
		);
	}

	/**
	 * A schedule that will not validate says so instead of inventing a time.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_an_invalid_schedule_says_nothing_is_queued(): void {
		$prompt_id = $this->schedule( Schedule::TYPE_WEEKLY, array( 'time' => 'not a time' ) );

		$this->assertStringContainsString( 'Not valid', $this->cell( Prompt_Columns::COLUMN_REPEAT, $prompt_id ) );
		$this->assertStringNotContainsString( 'Varies', $this->cell( Prompt_Columns::COLUMN_TIME, $prompt_id ) );
	}

	/**
	 * A switched-off prompt is marked beside its title.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_disabled_prompt_is_marked_beside_its_title(): void {
		$prompt_id = $this->create_prompt( array( 'enabled' => 0 ) );

		$this->assertContains(
			'Disabled',
			apply_filters( 'display_post_states', array(), get_post( $prompt_id ) )
		);
	}

	/**
	 * An enabled prompt is not marked.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_an_enabled_prompt_carries_no_state(): void {
		$this->assertSame(
			array(),
			apply_filters( 'display_post_states', array(), get_post( $this->create_prompt() ) )
		);
	}

	/**
	 * An ordinary post is left alone.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_post_that_is_not_a_prompt_is_left_alone(): void {
		$post_id = self::factory()->post->create();

		$this->assertSame(
			array(),
			apply_filters( 'display_post_states', array(), get_post( $post_id ) )
		);
	}

	/**
	 * A disabled prompt says the queue holds nothing for it.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_a_disabled_prompt_says_nothing_is_queued(): void {
		$prompt_id = $this->create_prompt(
			array(
				'enabled'         => 0,
				'schedule_type'   => Schedule::TYPE_DAILY,
				'schedule_params' => array( 'time' => '06:00' ),
			)
		);

		$cell = $this->cell( Prompt_Columns::COLUMN_REPEAT, $prompt_id );

		$this->assertStringContainsString( 'Every day', $cell );
		$this->assertStringContainsString( 'Disabled — nothing is queued.', $cell );
	}

	/**
	 * Being switched off is reported ahead of the schedule not parsing.
	 *
	 * Fixing the expression on a disabled prompt queues nothing, so the note
	 * that would send somebody to the Schedule tab would send them to the wrong
	 * control.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function test_disabled_outranks_an_invalid_schedule(): void {
		$prompt_id = $this->create_prompt(
			array(
				'enabled'         => 0,
				'schedule_type'   => Schedule::TYPE_WEEKLY,
				'schedule_params' => array( 'time' => 'not a time' ),
			)
		);

		$cell = $this->cell( Prompt_Columns::COLUMN_REPEAT, $prompt_id );

		$this->assertStringContainsString( 'Disabled', $cell );
		$this->assertStringNotContainsString( 'Not valid', $cell );
	}

	/**
	 * Creates a prompt with a given schedule.
	 *
	 * @since 1.18.0
	 *
	 * @param string               $type   Schedule type.
	 * @param array<string, mixed> $params Schedule parameters.
	 * @return int Prompt ID.
	 */
	private function schedule( string $type, array $params ): int {
		return $this->create_prompt(
			array(
				'schedule_type'   => $type,
				'schedule_params' => $params,
			)
		);
	}

	/**
	 * Renders one cell and returns its markup.
	 *
	 * @since 1.18.0
	 *
	 * @param string $column    Column key.
	 * @param int    $prompt_id Prompt to render.
	 * @return string
	 */
	private function cell( string $column, int $prompt_id ): string {
		ob_start();
		( new Prompt_Columns() )->render( $column, $prompt_id );

		return trim( (string) ob_get_clean() );
	}
}
