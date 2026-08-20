<?php
/**
 * The run log list table.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Pipeline\Run;
use AutoScribe\Prompts\Prompt_Post_Type;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists generation runs, per section 9.3.
 *
 * @since 0.7.0
 */
final class Runs_List_Table extends WP_List_Table {

	/**
	 * Rows shown per page.
	 *
	 * @since 0.7.0
	 * @var int
	 */
	private const PER_PAGE = 20;

	/**
	 * Nonce action protecting the filter form.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private const FILTER_ACTION = 'autoscribe_runs_filter';

	/**
	 * Nonce field name on the filter form.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	private const FILTER_NONCE = 'autoscribe_filter_nonce';

	/**
	 * Builds the table.
	 *
	 * @since 0.7.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'autoscribe_run',
				'plural'   => 'autoscribe_runs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Returns the column headings.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'started_at' => __( 'Date', 'autoscribe' ),
			'prompt_id'  => __( 'Prompt', 'autoscribe' ),
			'status'     => __( 'Status', 'autoscribe' ),
			'title'      => __( 'Title', 'autoscribe' ),
			'post_id'    => __( 'Post', 'autoscribe' ),
			'model'      => __( 'Model', 'autoscribe' ),
			'tokens'     => __( 'Tokens', 'autoscribe' ),
			'cost_cents' => __( 'Est. spend', 'autoscribe' ),
			'attempt'    => __( 'Attempt', 'autoscribe' ),
			'error'      => __( 'Error', 'autoscribe' ),
		);
	}

	/**
	 * Loads the rows for the current page.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$filters = $this->current_filters();
		$paged   = $this->get_pagenum();

		$this->items = Run::query(
			array_merge(
				$filters,
				array(
					'per_page' => self::PER_PAGE,
					'paged'    => $paged,
				)
			)
		);

		$total = Run::count( $filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Reads the filter values from the request.
	 *
	 * The filter form carries a nonce and the filters are only honoured when it
	 * verifies. That is stricter than a read-only screen strictly needs, but it
	 * means the request is checked before the superglobal is touched rather than
	 * after, and an unfiltered list is a safe thing to fall back to.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed>
	 */
	private function current_filters(): array {
		$unfiltered = array(
			'prompt_id' => 0,
			'status'    => '',
			'month'     => '',
		);

		$nonce = isset( $_GET[ self::FILTER_NONCE ] )
			? sanitize_text_field( wp_unslash( $_GET[ self::FILTER_NONCE ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::FILTER_ACTION ) ) {
			return $unfiltered;
		}

		$prompt_id = isset( $_GET['autoscribe_prompt'] ) ? absint( wp_unslash( $_GET['autoscribe_prompt'] ) ) : 0;
		$status    = isset( $_GET['autoscribe_status'] ) ? sanitize_key( wp_unslash( $_GET['autoscribe_status'] ) ) : '';
		$month     = isset( $_GET['autoscribe_month'] ) ? sanitize_text_field( wp_unslash( $_GET['autoscribe_month'] ) ) : '';

		return array(
			'prompt_id' => $prompt_id,
			'status'    => in_array( $status, self::statuses(), true ) ? $status : '',
			'month'     => 1 === preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : '',
		);
	}

	/**
	 * Returns the selectable run statuses.
	 *
	 * @since 0.7.0
	 *
	 * @return string[]
	 */
	private static function statuses(): array {
		return array(
			Run::STATUS_RUNNING,
			Run::STATUS_SUCCESS,
			Run::STATUS_FAILED,
			Run::STATUS_SKIPPED_BUDGET,
			Run::STATUS_SKIPPED_DUPLICATE,
		);
	}

	/**
	 * Renders the filter controls above the table.
	 *
	 * @since 0.7.0
	 *
	 * @param string $which Which tablenav is being rendered.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = $this->current_filters();

		echo '<div class="alignleft actions">';

		wp_nonce_field( self::FILTER_ACTION, self::FILTER_NONCE, false );

		echo '<select name="autoscribe_prompt">';
		printf( '<option value="0">%s</option>', esc_html__( 'All prompts', 'autoscribe' ) );

		$prompts = get_posts(
			array(
				'post_type'      => Prompt_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $prompts as $prompt ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $prompt->ID,
				selected( (int) $prompt->ID, (int) $filters['prompt_id'], false ),
				esc_html( get_the_title( $prompt ) )
			);
		}

		echo '</select>';

		echo '<select name="autoscribe_status">';
		printf( '<option value="">%s</option>', esc_html__( 'All statuses', 'autoscribe' ) );

		foreach ( self::statuses() as $status ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status ),
				selected( $status, (string) $filters['status'], false ),
				esc_html( $status )
			);
		}

		echo '</select>';

		printf(
			'<input type="month" name="autoscribe_month" value="%s" />',
			esc_attr( (string) $filters['month'] )
		);

		submit_button( __( 'Filter', 'autoscribe' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Renders the message shown when nothing matches.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No runs recorded yet.', 'autoscribe' );
	}

	/**
	 * Renders any column without a dedicated method.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $item        Run row.
	 * @param string               $column_name Column being rendered.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Renders the start date in the site timezone.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $item Run row.
	 * @return string
	 */
	public function column_started_at( array $item ): string {
		$timestamp = strtotime( (string) ( $item['started_at'] ?? '' ) . ' UTC' );

		if ( false === $timestamp ) {
			return '&mdash;';
		}

		$label = esc_html( Next_Run_Readout::format( $timestamp ) );

		if ( Run::STATUS_FAILED !== ( $item['status'] ?? '' ) ) {
			return $label;
		}

		return $label . $this->row_actions(
			array(
				'retry' => sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( Actions::url( Actions::ACTION_RETRY, (int) $item['prompt_id'] ) ),
					esc_html__( 'Retry', 'autoscribe' )
				),
			)
		);
	}

	/**
	 * Renders the prompt name, linked to its editor.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $item Run row.
	 * @return string
	 */
	public function column_prompt_id( array $item ): string {
		$prompt_id = (int) ( $item['prompt_id'] ?? 0 );
		$title     = get_the_title( $prompt_id );
		$link      = get_edit_post_link( $prompt_id );

		if ( '' === $title ) {
			/* translators: %d: prompt post ID. */
			$title = sprintf( __( 'Prompt %d', 'autoscribe' ), $prompt_id );
		}

		if ( null === $link ) {
			return esc_html( $title );
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $title ) );
	}

	/**
	 * Renders a link to the generated post.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $item Run row.
	 * @return string
	 */
	public function column_post_id( array $item ): string {
		$post_id = (int) ( $item['post_id'] ?? 0 );

		if ( $post_id <= 0 ) {
			return '&mdash;';
		}

		$link = get_edit_post_link( $post_id );

		if ( null === $link ) {
			return esc_html( (string) $post_id );
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html__( 'Edit', 'autoscribe' ) );
	}

	/**
	 * Renders the models used.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $item Run row.
	 * @return string
	 */
	public function column_model( array $item ): string {
		$models = array_filter(
			array(
				(string) ( $item['text_model'] ?? '' ),
				(string) ( $item['image_model'] ?? '' ),
			)
		);

		return empty( $models ) ? '&mdash;' : esc_html( implode( ', ', $models ) );
	}

	/**
	 * Renders the token counts.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $item Run row.
	 * @return string
	 */
	public function column_tokens( array $item ): string {
		return esc_html(
			sprintf(
				/* translators: 1: input tokens, 2: output tokens. */
				__( '%1$s in / %2$s out', 'autoscribe' ),
				number_format_i18n( (int) ( $item['input_tokens'] ?? 0 ) ),
				number_format_i18n( (int) ( $item['output_tokens'] ?? 0 ) )
			)
		);
	}

	/**
	 * Renders the estimated spend.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $item Run row.
	 * @return string
	 */
	public function column_cost_cents( array $item ): string {
		$spend = esc_html( number_format_i18n( (int) ( $item['cost_cents'] ?? 0 ) / 100, 2 ) );

		if ( empty( $item['cost_stale'] ) ) {
			return $spend;
		}

		/*
		 * The run recorded a charge that has not been priced into this figure yet
		 * — a worker that died between the two writes, or a database that refused
		 * the second one. It is shown rather than hidden because the budget guard
		 * refuses to authorise a run while any of these are outstanding, and an
		 * operator told that generation has stopped for an accounting reason needs
		 * somewhere to see which run it is.
		 */
		return sprintf(
			'%1$s<br /><span class="description">%2$s</span>',
			$spend,
			esc_html__( 'Accounting pending', 'autoscribe' )
		);
	}
}
