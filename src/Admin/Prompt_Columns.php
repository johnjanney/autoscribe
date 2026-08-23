<?php
/**
 * Prompts list table columns.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Prompts\Prompt;
use AutoScribe\Prompts\Prompt_Fields;
use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Scheduling\Schedule;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Repeat, Time of day, and Category columns to the Prompts screen.
 *
 * The list table shipped with the title and the date, which answers neither of
 * the two questions somebody opens it to ask: when does this run, and where do
 * its posts land. Both answers were one click away in the editor, per prompt,
 * which is the wrong place for a comparison across prompts.
 *
 * A switched-off prompt is marked beside its title rather than in a column of
 * its own. See post_states().
 *
 * @since 1.18.0
 */
final class Prompt_Columns {

	/**
	 * Column key for the schedule description.
	 *
	 * @since 1.18.0
	 * @var string
	 */
	public const COLUMN_REPEAT = 'autoscribe_repeat';

	/**
	 * Column key for the local time of day.
	 *
	 * @since 1.18.0
	 * @var string
	 */
	public const COLUMN_TIME = 'autoscribe_time';

	/**
	 * Column key for the target categories.
	 *
	 * @since 1.18.0
	 * @var string
	 */
	public const COLUMN_CATEGORIES = 'autoscribe_categories';

	/**
	 * How many clock times a cron expression may name before the column gives up.
	 *
	 * Beyond a handful the list stops being a time of day and starts being a
	 * second copy of the expression, which the Repeat column already shows.
	 *
	 * @since 1.18.0
	 * @var int
	 */
	private const MAX_CRON_TIMES = 4;

	/**
	 * Registers the column hooks.
	 *
	 * @since 1.18.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_' . Prompt_Post_Type::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Prompt_Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'render' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
	}

	/**
	 * Marks a switched-off prompt beside its title.
	 *
	 * A post state rather than a column of its own. WordPress already marks a
	 * draft, a sticky post, and a password-protected one this way, in the place
	 * a reader's eye reaches first, and a column repeating "Enabled" down every
	 * row would spend a column's width on the case that needs no comment.
	 *
	 * @since 1.18.0
	 *
	 * @param array<string, string> $states Existing post states.
	 * @param WP_Post               $post   Post being listed.
	 * @return array<string, string>
	 */
	public function post_states( array $states, WP_Post $post ): array {
		if ( Prompt_Post_Type::POST_TYPE !== $post->post_type ) {
			return $states;
		}

		$prompt = Prompt::load( $post->ID );

		if ( null === $prompt || $prompt->enabled() ) {
			return $states;
		}

		$states['autoscribe_disabled'] = __( 'Disabled', 'autoscribe' );

		return $states;
	}

	/**
	 * Inserts the three columns immediately after the title.
	 *
	 * @since 1.18.0
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$added = array(
			self::COLUMN_REPEAT     => __( 'Repeat', 'autoscribe' ),
			self::COLUMN_TIME       => sprintf(
				/* translators: %s: site timezone, such as CDT or UTC+05:30. */
				__( 'Time of day (%s)', 'autoscribe' ),
				$this->timezone_label()
			),
			self::COLUMN_CATEGORIES => __( 'Category', 'autoscribe' ),
		);

		$rebuilt = array();

		foreach ( $columns as $key => $label ) {
			$rebuilt[ $key ] = $label;

			if ( 'title' === $key ) {
				$rebuilt = array_merge( $rebuilt, $added );
			}
		}

		// A screen without a title column still gets the three, at the end.
		if ( ! isset( $rebuilt[ self::COLUMN_REPEAT ] ) ) {
			$rebuilt = array_merge( $rebuilt, $added );
		}

		return $rebuilt;
	}

	/**
	 * Renders one cell.
	 *
	 * @since 1.18.0
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Prompt being listed.
	 * @return void
	 */
	public function render( string $column, int $post_id ): void {
		if ( ! in_array( $column, array( self::COLUMN_REPEAT, self::COLUMN_TIME, self::COLUMN_CATEGORIES ), true ) ) {
			return;
		}

		$prompt = Prompt::load( $post_id );

		if ( null === $prompt ) {
			$this->dash( __( 'Not available', 'autoscribe' ) );

			return;
		}

		switch ( $column ) {
			case self::COLUMN_REPEAT:
				$this->render_repeat( $prompt );
				break;

			case self::COLUMN_TIME:
				$this->render_time( $prompt );
				break;

			case self::COLUMN_CATEGORIES:
				$this->render_categories( $prompt );
				break;
		}
	}

	/**
	 * Renders the schedule with its parameters filled in.
	 *
	 * The second line answers a different question from the first: the schedule
	 * says what was configured, and the note says whether anything is going to
	 * act on it. A prompt can be switched off, or carry a schedule the plugin
	 * cannot read, and in both cases the queue holds nothing while the cell
	 * above still reads like a promise.
	 *
	 * @since 1.18.0
	 *
	 * @param Prompt $prompt Prompt being listed.
	 * @return void
	 */
	private function render_repeat( Prompt $prompt ): void {
		$schedule = $prompt->schedule();

		if ( is_wp_error( $schedule ) ) {
			echo esc_html( $this->type_label( $prompt->schedule_type() ) );
		} elseif ( Schedule::TYPE_CRON === $schedule->type() ) {
			printf( '<code>%s</code>', esc_html( $schedule->expression() ) );
		} else {
			echo esc_html( $this->describe( $schedule ) );
		}

		// Being switched off outranks the reason a schedule would not have run
		// anyway. Fixing the expression on a disabled prompt queues nothing.
		if ( ! $prompt->enabled() ) {
			$this->note( __( 'Disabled — nothing is queued.', 'autoscribe' ) );

			return;
		}

		if ( is_wp_error( $schedule ) ) {
			$this->note( __( 'Not valid — nothing is queued.', 'autoscribe' ) );
		}
	}

	/**
	 * Renders the local time of day, or says why there is not one.
	 *
	 * Four of the six schedule types are anchored to a clock time. The other two
	 * are not, and an empty cell would read as an unfinished prompt rather than
	 * as a schedule that genuinely has no time of day, so each says what governs
	 * it instead. A cron expression that does name a fixed hour is answered with
	 * that hour: the user asked for six in the morning either way, and the column
	 * should not go quiet because of how they spelled it.
	 *
	 * @since 1.18.0
	 *
	 * @param Prompt $prompt Prompt being listed.
	 * @return void
	 */
	private function render_time( Prompt $prompt ): void {
		$schedule = $prompt->schedule();

		if ( is_wp_error( $schedule ) ) {
			$this->dash( __( 'No time of day', 'autoscribe' ) );

			return;
		}

		switch ( $schedule->type() ) {
			case Schedule::TYPE_INTERVAL:
				$this->varies( __( 'counts from the last run', 'autoscribe' ) );
				break;

			case Schedule::TYPE_CRON:
				$times = $this->cron_times( $schedule->expression() );

				if ( array() === $times ) {
					$this->varies( __( 'no single fixed hour', 'autoscribe' ) );
					break;
				}

				echo esc_html( implode( ', ', $times ) );
				break;

			default:
				echo esc_html( $this->clock( $schedule->hour(), $schedule->minute() ) );
				break;
		}
	}

	/**
	 * Renders the categories generated posts are filed under, each linked.
	 *
	 * @since 1.18.0
	 *
	 * @param Prompt $prompt Prompt being listed.
	 * @return void
	 */
	private function render_categories( Prompt $prompt ): void {
		if ( 'post' !== $prompt->post_type() ) {
			$this->dash( __( 'No category', 'autoscribe' ) );
			$this->note( __( 'Pages have no categories.', 'autoscribe' ) );

			return;
		}

		$terms = $this->terms( $prompt->category_ids() );

		if ( array() === $terms ) {
			/*
			 * An empty list is not "no category". WordPress files a post with no
			 * category under the site default, so the honest answer is that
			 * default and a note saying nobody chose it. The same branch covers a
			 * category deleted after the prompt was saved, which Taxonomy_Applier
			 * drops silently for the same reason it cannot apply it.
			 */
			$default = $this->terms( array( (int) get_option( 'default_category' ) ) );

			if ( array() === $default ) {
				$this->dash( __( 'No category', 'autoscribe' ) );

				return;
			}

			$this->links( $default );
			$this->note( __( 'Site default — none chosen.', 'autoscribe' ) );

			return;
		}

		$this->links( $terms );
	}

	/**
	 * Resolves category IDs to the terms that still exist.
	 *
	 * @since 1.18.0
	 *
	 * @param int[] $ids Category IDs.
	 * @return WP_Term[]
	 */
	private function terms( array $ids ): array {
		$terms = array();

		foreach ( $ids as $id ) {
			$term = get_term( $id, 'category' );

			if ( $term instanceof WP_Term ) {
				$terms[] = $term;
			}
		}

		return $terms;
	}

	/**
	 * Prints a comma-separated list of category links.
	 *
	 * Each link goes to the posts filed under that category rather than to the
	 * term editor: the question the column raises is what this prompt has been
	 * publishing, not how the category is named.
	 *
	 * @since 1.18.0
	 *
	 * @param WP_Term[] $terms Categories to link.
	 * @return void
	 */
	private function links( array $terms ): void {
		$first = true;

		foreach ( $terms as $term ) {
			if ( ! $first ) {
				echo ', ';
			}

			$first = false;

			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					add_query_arg(
						array(
							'post_type' => 'post',
							'cat'       => $term->term_id,
						),
						admin_url( 'edit.php' )
					)
				),
				esc_html( $term->name )
			);
		}
	}

	/**
	 * Describes a schedule that is not a cron expression.
	 *
	 * @since 1.18.0
	 *
	 * @param Schedule $schedule Validated schedule.
	 * @return string
	 */
	private function describe( Schedule $schedule ): string {
		switch ( $schedule->type() ) {
			case Schedule::TYPE_WEEKLY:
				return sprintf(
					/* translators: %s: weekday name. */
					__( 'Every %s', 'autoscribe' ),
					$this->weekday_label( $schedule->weekday() )
				);

			case Schedule::TYPE_MONTHLY_DATE:
				return sprintf(
					/* translators: %d: day of the month. */
					__( 'Day %d each month', 'autoscribe' ),
					$schedule->day_of_month()
				);

			case Schedule::TYPE_MONTHLY_ORDINAL:
				return sprintf(
					/* translators: 1: ordinal such as Second, 2: weekday name. */
					__( '%1$s %2$s each month', 'autoscribe' ),
					$this->ordinal_label( $schedule->ordinal() ),
					$this->weekday_label( $schedule->weekday() )
				);

			case Schedule::TYPE_INTERVAL:
				return sprintf(
					/* translators: %d: number of hours between runs. */
					_n( 'Every %d hour', 'Every %d hours', $schedule->hours(), 'autoscribe' ),
					$schedule->hours()
				);
		}

		return __( 'Every day', 'autoscribe' );
	}

	/**
	 * Returns the clock times a cron expression fires at, when it has few enough.
	 *
	 * @since 1.18.0
	 *
	 * @param string $expression Cron expression.
	 * @return string[] Formatted times, or an empty array when the hour varies.
	 */
	private function cron_times( string $expression ): array {
		if ( ! CronExpression::isValidExpression( $expression ) ) {
			return array();
		}

		$parsed  = new CronExpression( $expression );
		$minutes = $this->fixed_values( (string) $parsed->getExpression( CronExpression::MINUTE ), 59 );
		$hours   = $this->fixed_values( (string) $parsed->getExpression( CronExpression::HOUR ), 23 );

		if ( array() === $minutes || array() === $hours ) {
			return array();
		}

		if ( count( $minutes ) * count( $hours ) > self::MAX_CRON_TIMES ) {
			return array();
		}

		$times = array();

		foreach ( $hours as $hour ) {
			foreach ( $minutes as $minute ) {
				$times[] = $this->clock( $hour, $minute );
			}
		}

		return $times;
	}

	/**
	 * Reads a cron field that names literal values, and nothing else.
	 *
	 * A step, a range, or a wildcard means the field does not pin a time, so
	 * those return nothing rather than an approximation.
	 *
	 * @since 1.18.0
	 *
	 * @param string $field Single cron field.
	 * @param int    $max   Highest value the field allows.
	 * @return int[] Sorted values, or an empty array.
	 */
	private function fixed_values( string $field, int $max ): array {
		if ( 1 !== preg_match( '/^\d{1,2}(,\d{1,2})*$/', $field ) ) {
			return array();
		}

		$values = array_unique( array_map( 'intval', explode( ',', $field ) ) );

		foreach ( $values as $value ) {
			if ( $value > $max ) {
				return array();
			}
		}

		sort( $values );

		return $values;
	}

	/**
	 * Formats an hour and minute in the site's time format.
	 *
	 * Deliberately formatted from a fixed UTC instant rather than from today's
	 * date in the site timezone. On the morning the clocks go forward, a local
	 * 02:30 does not exist, and building it there would display 3:30 for a
	 * schedule that reads 02:30 on every other day of the year.
	 *
	 * @since 1.18.0
	 *
	 * @param int $hour   Hour, 0-23.
	 * @param int $minute Minute, 0-59.
	 * @return string
	 */
	private function clock( int $hour, int $minute ): string {
		$utc = ( new DateTimeImmutable( '@0' ) )->setTime( $hour, $minute );

		return wp_date( (string) get_option( 'time_format' ), $utc->getTimestamp(), new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Prints "Varies" with the short reason underneath.
	 *
	 * The reason is printed rather than hidden in a tooltip. A cell that says
	 * only "Varies" invites the same question on every row, and a title
	 * attribute answers it for a mouse and for nobody else.
	 *
	 * @since 1.18.0
	 *
	 * @param string $reason Lower-case fragment explaining what governs the run.
	 * @return void
	 */
	private function varies( string $reason ): void {
		echo esc_html__( 'Varies', 'autoscribe' );
		$this->note( $reason );
	}

	/**
	 * Prints a second line of muted explanatory text.
	 *
	 * @since 1.18.0
	 *
	 * @param string $text Text to print.
	 * @return void
	 */
	private function note( string $text ): void {
		printf(
			'<br /><span class="description">%s</span>',
			esc_html( $text )
		);
	}

	/**
	 * Prints the list table's em dash, with a spoken label behind it.
	 *
	 * @since 1.18.0
	 *
	 * @param string $label What the dash stands for, for a screen reader.
	 * @return void
	 */
	private function dash( string $label ): void {
		printf(
			'<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">%s</span>',
			esc_html( $label )
		);
	}

	/**
	 * Names the site timezone as briefly as it can be named.
	 *
	 * A site set to a place has an abbreviation worth printing. A site set to a
	 * raw offset has none, and PHP renders one anyway as GMT+0530, which reads
	 * like a zone name and is not one.
	 *
	 * @since 1.18.0
	 *
	 * @return string
	 */
	private function timezone_label(): string {
		$zone = wp_timezone_string();

		if ( 1 !== preg_match( '/^[+-]/', $zone ) ) {
			return wp_date( 'T' );
		}

		return '+00:00' === $zone ? 'UTC' : 'UTC' . $zone;
	}

	/**
	 * Returns the editor's own label for a schedule type.
	 *
	 * @since 1.18.0
	 *
	 * @param string $type Schedule type.
	 * @return string
	 */
	private function type_label( string $type ): string {
		$choices = Prompt_Fields::schedule_type_choices();

		return $choices[ $type ] ?? $type;
	}

	/**
	 * Returns the editor's own label for a weekday.
	 *
	 * @since 1.18.0
	 *
	 * @param string $weekday Weekday slug.
	 * @return string
	 */
	private function weekday_label( string $weekday ): string {
		$choices = Prompt_Fields::weekday_choices();

		return $choices[ $weekday ] ?? $weekday;
	}

	/**
	 * Returns the editor's own label for an ordinal.
	 *
	 * @since 1.18.0
	 *
	 * @param string $ordinal Ordinal slug.
	 * @return string
	 */
	private function ordinal_label( string $ordinal ): string {
		$choices = Prompt_Fields::ordinal_choices();

		return $choices[ $ordinal ] ?? $ordinal;
	}
}
