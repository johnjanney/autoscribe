<?php
/**
 * Uninstall routine tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests;

use AutoScribe\Activation;
use AutoScribe\Cost\Budget_Guard;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\Media\Image_Sideloader;
use AutoScribe\Pipeline\Step_Assemble_Post;
use AutoScribe\Prompts\Prompt_Fields;
use AutoScribe\Prompts\Prompt_Post_Type;
use AutoScribe\Security\Key_Store;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers what uninstalling removes, and what it deliberately does not.
 *
 * The interesting half is the second one. Section 6 puts _autoscribe_generated
 * on every generated attachment so a human can find and bulk-delete AI images
 * later, and section 10 puts _autoscribe_run_id on every generated post to keep
 * it auditable. Uninstall leaves that content in place because it belongs to the
 * site owner, so destroying the only means of identifying it would leave them
 * worse off than before.
 *
 * These tests run the real uninstall.php. That drops a table, which MySQL
 * commits implicitly and the surrounding transaction therefore cannot roll
 * back, so tear_down puts the schema and capabilities back by hand.
 *
 * @since 0.8.0
 */
final class UninstallTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Every option the plugin creates.
	 *
	 * @since 0.8.0
	 * @var string[]
	 */
	private const OPTIONS = array(
		Activation::DB_VERSION_OPTION,
		'autoscribe_settings',
		Key_Store::OPTION,
		Pricing_Table::OPTION,
		Budget_Guard::GLOBAL_CAP_OPTION,
		Budget_Guard::NOTICE_SENT_OPTION,
	);

	/**
	 * Restores the schema and capabilities the uninstall removed.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Activation::DB_VERSION_OPTION );

		$this->with_real_tables(
			static function (): void {
				Activation::maybe_upgrade();
			}
		);

		$role = get_role( 'administrator' );

		if ( null !== $role ) {
			foreach ( Activation::capabilities() as $capability ) {
				$role->add_cap( $capability );
			}
		}

		// One test unregisters the post type. Registration happens on init,
		// which has long since fired, so it has to be put back by hand or every
		// later test loses the ability to create a prompt.
		if ( ! post_type_exists( Prompt_Post_Type::POST_TYPE ) ) {
			( new Prompt_Post_Type() )->register();
		}

		parent::tear_down();
	}

	/**
	 * Runs a callback with the harness's table rewriting switched off.
	 *
	 * WP_UnitTestCase filters every query so that CREATE TABLE becomes CREATE
	 * TEMPORARY TABLE and DROP TABLE becomes DROP TEMPORARY TABLE, which is how
	 * it keeps tests isolated. That rewriting makes a DROP of a real table a
	 * silent no-op, so a test of the uninstall would pass whether or not the
	 * table was ever dropped. Removing the filters for the duration is what
	 * makes the assertion mean something.
	 *
	 * @since 0.8.0
	 *
	 * @param callable $callback Work to run against real tables.
	 * @return void
	 */
	private function with_real_tables( callable $callback ): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			$callback();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Runs the real uninstall file.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'autoscribe/autoscribe.php' );
		}

		$path = dirname( __DIR__ ) . '/uninstall.php';

		$this->with_real_tables(
			static function () use ( $path ): void {
				require $path;
			}
		);
	}

	/**
	 * Creates a post that looks like something the plugin generated.
	 *
	 * @since 0.8.0
	 *
	 * @return array{post: int, attachment: int}
	 */
	private function generated_content(): array {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'A generated article',
			)
		);

		update_post_meta( $post_id, Step_Assemble_Post::RUN_ID_META, 42 );
		update_post_meta( $post_id, Step_Assemble_Post::TOPIC_KEY_META, 'a-generated-article' );

		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'generated.jpg',
				'post_parent'    => $post_id,
				'post_mime_type' => 'image/jpeg',
			)
		);

		update_post_meta( $attachment_id, Image_Sideloader::GENERATED_META, 1 );

		return array(
			'post'       => $post_id,
			'attachment' => $attachment_id,
		);
	}

	/**
	 * Every option the plugin creates is removed.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_every_option_is_removed(): void {
		foreach ( self::OPTIONS as $option ) {
			update_option( $option, 'set' );
		}

		foreach ( self::OPTIONS as $option ) {
			$this->assertNotFalse( get_option( $option, false ), $option . ' was not set up for the test' );
		}

		$this->uninstall();

		foreach ( self::OPTIONS as $option ) {
			$this->assertFalse( get_option( $option, false ), $option . ' survived the uninstall' );
		}
	}

	/**
	 * The runs table is dropped.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_the_runs_table_is_dropped(): void {
		global $wpdb;

		$table = Activation::table_name();

		$this->assertSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
			'the table was missing before the test began'
		);

		$this->uninstall();

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	/**
	 * Prompts and their configuration meta are removed.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_prompts_and_their_meta_are_removed(): void {
		$prompt_id = $this->create_prompt( array( 'user_prompt' => 'Write about espresso.' ) );

		$this->assertSame( 'Write about espresso.', get_post_meta( $prompt_id, '_autoscribe_user_prompt', true ) );

		$this->uninstall();

		$this->assertNull( get_post( $prompt_id ) );
		$this->assertSame( '', get_post_meta( $prompt_id, '_autoscribe_user_prompt', true ) );
		$this->assertSame( '', get_post_meta( $prompt_id, '_autoscribe_schedule_params', true ) );
	}

	/**
	 * Generated content survives, and stays identifiable.
	 *
	 * This is the assertion the whole file exists for. A wildcard sweep over
	 * _autoscribe_% would pass every other test here and fail this one.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_generated_content_keeps_its_identifying_flags(): void {
		$content = $this->generated_content();

		$this->uninstall();

		$this->assertNotNull( get_post( $content['post'] ), 'the generated post was deleted' );
		$this->assertNotNull( get_post( $content['attachment'] ), 'the generated attachment was deleted' );

		$this->assertSame(
			'42',
			(string) get_post_meta( $content['post'], Step_Assemble_Post::RUN_ID_META, true ),
			'the run link was destroyed, leaving the post unauditable'
		);

		$this->assertSame(
			'1',
			(string) get_post_meta( $content['attachment'], Image_Sideloader::GENERATED_META, true ),
			'the generated-image flag was destroyed, so section 6 bulk cleanup is impossible'
		);

		$this->assertSame(
			'a-generated-article',
			(string) get_post_meta( $content['post'], Step_Assemble_Post::TOPIC_KEY_META, true )
		);
	}

	/**
	 * Capabilities are removed from every role.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_capabilities_are_removed(): void {
		$role = get_role( 'administrator' );

		foreach ( Activation::capabilities() as $capability ) {
			$role->add_cap( $capability );
		}

		$this->uninstall();

		$role = get_role( 'administrator' );

		foreach ( Activation::capabilities() as $capability ) {
			$this->assertFalse( $role->has_cap( $capability ), $capability . ' survived the uninstall' );
		}
	}

	/**
	 * Every prompt field has a matching key in the uninstall sweep.
	 *
	 * The sweep is an explicit list rather than a wildcard, which is what makes
	 * preserving the generated-content flags possible. The cost is that a new
	 * prompt field can be forgotten there, and would then be left behind on
	 * every uninstall with nothing to notice. This is that notice.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_the_sweep_covers_every_prompt_field(): void {
		$fields = Prompt_Fields::all();

		// Written to an ordinary post rather than a prompt, so that deleting the
		// prompt post cannot be what makes the meta disappear. Only the explicit
		// key sweep can clear these, which is exactly what is being checked.
		$carrier = (int) self::factory()->post->create();

		$expected = array();

		foreach ( $fields as $key => $field ) {
			if ( ! empty( $field['param'] ) ) {
				// Schedule parameters live inside schedule_params, not under a
				// meta key of their own.
				continue;
			}

			$expected[] = Prompt_Fields::PREFIX . $key;

			update_post_meta( $carrier, Prompt_Fields::PREFIX . $key, 'set' );
		}

		$this->assertNotEmpty( $expected );

		$this->uninstall();

		foreach ( $expected as $meta_key ) {
			$this->assertSame(
				'',
				(string) get_post_meta( $carrier, $meta_key, true ),
				$meta_key . ' is a prompt field but survived the uninstall'
			);
		}
	}

	/**
	 * Prompt posts are found even though the post type is not registered.
	 *
	 * Uninstall runs without the plugin loaded, so the post type does not
	 * exist and WP_Query cannot be used. It matches on the stored post_type
	 * string instead, and a rename would silently orphan every prompt.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_prompt_posts_are_found_without_the_post_type_registered(): void {
		$prompt_id = $this->create_prompt();

		unregister_post_type( Prompt_Post_Type::POST_TYPE );

		$this->assertFalse( post_type_exists( Prompt_Post_Type::POST_TYPE ) );

		$this->uninstall();

		$this->assertNull( get_post( $prompt_id ) );
	}
}
