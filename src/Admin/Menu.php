<?php
/**
 * Admin menu, screens, and notices.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

use AutoScribe\Activation;
use AutoScribe\Pipeline\Step_Assemble_Post;
use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Scheduler;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the menu described in section 9.1.
 *
 * The prompt post type already registers its own top-level menu, so the Run Log
 * and Settings screens are added underneath it rather than creating a second
 * top-level entry that would sit next to the first.
 *
 * @since 0.7.0
 */
final class Menu {

	/**
	 * Run log menu slug.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const RUNS_SLUG = 'autoscribe-runs';

	/**
	 * Settings page renderer.
	 *
	 * @since 0.7.0
	 * @var Settings_Page
	 */
	private Settings_Page $settings_page;

	/**
	 * Queue health check, for the Run Log warning.
	 *
	 * @since 1.13.2
	 * @var Queue_Health
	 */
	private Queue_Health $queue_health;

	/**
	 * Builds the menu.
	 *
	 * @since 0.7.0
	 *
	 * @param Provider_Registry $providers Provider registry.
	 * @param Scheduler         $scheduler Queue wrapper.
	 */
	public function __construct( Provider_Registry $providers, Scheduler $scheduler ) {
		$this->settings_page = new Settings_Page( $providers, $scheduler );
		$this->queue_health  = new Queue_Health( $scheduler );
	}

	/**
	 * Registers the menu entries and the admin notice.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Adds the Run Log and Settings screens.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function add_pages(): void {
		$parent = 'edit.php?post_type=' . Prompt_Post_Type::POST_TYPE;

		add_submenu_page(
			$parent,
			__( 'Run Log', 'autoscribe' ),
			__( 'Run Log', 'autoscribe' ),
			Activation::MANAGE_CAPABILITY,
			self::RUNS_SLUG,
			array( $this, 'render_runs' )
		);

		add_submenu_page(
			$parent,
			__( 'AutoScribe Settings', 'autoscribe' ),
			__( 'Settings', 'autoscribe' ),
			Activation::MANAGE_CAPABILITY,
			Settings_Page::SLUG,
			array( $this->settings_page, 'render' )
		);
	}

	/**
	 * Renders the run log screen.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function render_runs(): void {
		if ( ! current_user_can( Activation::MANAGE_CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to view the AutoScribe run log.', 'autoscribe' ),
				'',
				array( 'response' => 403 )
			);
		}

		$table = new Runs_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AutoScribe Run Log', 'autoscribe' ) . '</h1>';

		$stalled = $this->queue_health->stall_warning();

		if ( null !== $stalled ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $stalled ) );
		}

		echo '<p>' . esc_html__( 'Spend figures are estimates computed from reported token usage. Your provider billing is the authority.', 'autoscribe' ) . '</p>';

		printf(
			'<form method="get"><input type="hidden" name="post_type" value="%1$s" /><input type="hidden" name="page" value="%2$s" />',
			esc_attr( Prompt_Post_Type::POST_TYPE ),
			esc_attr( self::RUNS_SLUG )
		);

		$table->display();

		echo '</form></div>';
	}

	/**
	 * Renders queued notices and the pending-draft count.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( Activation::MANAGE_CAPABILITY ) ) {
			return;
		}

		$key    = 'autoscribe_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			delete_transient( $key );

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'error' === ( $notice['type'] ?? '' ) ? 'error' : 'success',
				esc_html( (string) $notice['message'] )
			);
		}

		$this->render_pending_drafts_notice();
	}

	/**
	 * Tells the user how many generated drafts are waiting for review.
	 *
	 * Section 10 asks for this. Without it, review mode quietly accumulates
	 * drafts nobody looks at, which is the failure mode that makes people turn
	 * review off and publish unreviewed instead.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	private function render_pending_drafts_notice(): void {
		/*
		 * Counted through a meta_query rather than by reading 100 recent drafts
		 * and filtering in PHP. The old approach was cheaper per query but wrong:
		 * it silently stopped counting at 100, and the sites most in need of this
		 * notice are precisely the ones that have let more than 100 generated
		 * drafts pile up. The comparison is EXISTS against an indexed meta_key,
		 * and only the count is fetched, so no post rows are hydrated.
		 */
		$query = new WP_Query(
			array(
				'post_type'              => array( 'post', 'page' ),
				'post_status'            => 'draft',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- EXISTS on an indexed meta_key, counting only.
					array(
						'key'     => Step_Assemble_Post::RUN_ID_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$count = (int) $query->found_posts;

		if ( 0 === $count ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of drafts awaiting review. */
					_n(
						'AutoScribe has %d generated draft waiting for review.',
						'AutoScribe has %d generated drafts waiting for review.',
						$count,
						'autoscribe'
					),
					$count
				)
			)
		);
	}
}
