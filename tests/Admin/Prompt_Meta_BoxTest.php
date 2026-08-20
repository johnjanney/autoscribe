<?php
/**
 * Prompt editor tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Admin;

use AutoScribe\Activation;
use AutoScribe\Admin\Prompt_Meta_Box;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Prompts\Prompt_Fields;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers the section 9.2 editor: authorisation, and the render/save round trip.
 *
 * @since 0.7.0
 */
final class Prompt_Meta_BoxTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Administrator used by most tests.
	 *
	 * @since 0.7.0
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Grants the plugin capabilities and signs in an administrator.
	 *
	 * Activation normally grants these. The activation hook does not fire in the
	 * test suite, so without this every capability check would fail for a reason
	 * unrelated to what is being tested.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$role = get_role( 'administrator' );

		foreach ( Activation::capabilities() as $capability ) {
			$role->add_cap( $capability );
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Clears request state between tests.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST = array();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Renders the meta box and returns its markup.
	 *
	 * @since 0.7.0
	 *
	 * @param int $prompt_id Prompt to render.
	 * @return string
	 */
	private function render( int $prompt_id ): string {
		ob_start();

		( new Prompt_Meta_Box() )->render( get_post( $prompt_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Builds a valid submission covering every field.
	 *
	 * @since 0.7.0
	 *
	 * @param int $category_id Category to select.
	 * @return array<string, mixed>
	 */
	private function submission( int $category_id ): array {
		$values = array();

		foreach ( Prompt_Fields::all() as $key => $field ) {
			switch ( (string) $field['type'] ) {
				case 'bool':
					$values[ $key ] = '1';
					break;

				case 'int':
					$values[ $key ] = (string) ( $field['min'] ?? 1 );
					break;

				case 'select':
					$choices        = Prompt_Fields::choices( $field );
					$values[ $key ] = (string) array_key_last( $choices );
					break;

				case 'terms':
					$values[ $key ] = array( (string) $category_id );
					break;

				case 'csv':
					$values[ $key ] = 'alpha, beta';
					break;

				case 'time':
					$values[ $key ] = '07:30';
					break;

				case 'user':
					$values[ $key ] = (string) $this->admin_id;
					break;

				default:
					$values[ $key ] = 'value-for-' . $key;
					break;
			}
		}

		/*
		 * Every select takes its last choice, which for text_provider is DeepSeek
		 * — the one provider with no web search. Grounding is refused for that
		 * combination on save, so the round trip would fail on a field that is
		 * behaving correctly. Pinned to a provider that can ground instead;
		 * test_grounding_is_refused_for_a_provider_without_search covers the
		 * refusal on its own.
		 */
		$values['text_provider'] = 'anthropic';

		return $values;
	}

	/**
	 * Every defined field is rendered, and every rendered field saves.
	 *
	 * This is the test that keeps the two passes honest. A field added to the
	 * definition but forgotten in the form, or rendered but never read back,
	 * fails here rather than failing silently in production by discarding what
	 * the user typed.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_every_field_renders_and_round_trips(): void {
		$category_id = self::factory()->category->create();
		$prompt_id   = $this->create_prompt();

		$markup = $this->render( $prompt_id );
		$fields = Prompt_Fields::all();

		$this->assertNotEmpty( $fields );

		foreach ( $fields as $key => $field ) {
			$this->assertStringContainsString(
				'name="' . Prompt_Fields::INPUT_NAME . '[' . $key . ']',
				$markup,
				$key . ' is defined but never rendered'
			);
		}

		$submitted = $this->submission( $category_id );

		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'autoscribe_save_prompt_' . $prompt_id ),
			Prompt_Fields::INPUT_NAME   => $submitted,
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$params = get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'schedule_params', true );

		$this->assertIsArray( $params );

		foreach ( $fields as $key => $field ) {
			$expected = Prompt_Fields::sanitize( $field, $submitted[ $key ] );

			if ( ! empty( $field['param'] ) ) {
				$this->assertSame( $expected, $params[ $key ] ?? null, $key . ' did not round trip as a schedule parameter' );

				continue;
			}

			$stored = get_post_meta( $prompt_id, Prompt_Fields::PREFIX . $key, true );

			if ( 'bool' === $field['type'] ) {
				$this->assertSame( $expected, (bool) $stored, $key . ' did not round trip' );

				continue;
			}

			if ( is_array( $expected ) ) {
				$this->assertSame( $expected, $stored, $key . ' did not round trip' );

				continue;
			}

			$this->assertSame( (string) $expected, (string) $stored, $key . ' did not round trip' );
		}
	}

	/**
	 * Grounding cannot be saved against a provider that has no web search.
	 *
	 * Section 7.1 forbids saving a configuration that cannot run. The editor
	 * disables the control, but the control is a courtesy: this save path is also
	 * reached by the REST API, by WP-CLI, and by an imported prompt, none of
	 * which ever see it.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_grounding_is_refused_for_a_provider_without_search(): void {
		$prompt_id = $this->create_prompt();

		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'autoscribe_save_prompt_' . $prompt_id ),
			Prompt_Fields::INPUT_NAME   => array(
				'text_provider'     => 'deepseek',
				'grounding_enabled' => '1',
			),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$this->assertFalse(
			(bool) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'grounding_enabled', true ),
			'DeepSeek has no web search, so grounding must not survive the save'
		);
	}

	/**
	 * Grounding is kept for a provider that does have web search.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_grounding_is_kept_for_a_provider_with_search(): void {
		$prompt_id = $this->create_prompt();

		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'autoscribe_save_prompt_' . $prompt_id ),
			Prompt_Fields::INPUT_NAME   => array(
				'text_provider'     => 'anthropic',
				'grounding_enabled' => '1',
			),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$this->assertTrue(
			(bool) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'grounding_enabled', true )
		);
	}

	/**
	 * A submission with no nonce writes nothing.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_save_without_a_nonce_writes_nothing(): void {
		$prompt_id = $this->create_prompt( array( 'user_prompt' => 'original instruction' ) );

		$_POST = array(
			Prompt_Fields::INPUT_NAME => array( 'user_prompt' => 'injected instruction' ),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$this->assertSame(
			'original instruction',
			get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'user_prompt', true )
		);
	}

	/**
	 * A submission carrying a wrong nonce writes nothing.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_save_with_a_bad_nonce_writes_nothing(): void {
		$prompt_id = $this->create_prompt( array( 'user_prompt' => 'original instruction' ) );

		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'some_other_action' ),
			Prompt_Fields::INPUT_NAME   => array( 'user_prompt' => 'injected instruction' ),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$this->assertSame(
			'original instruction',
			get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'user_prompt', true )
		);
	}

	/**
	 * A user without the capability writes nothing, even with a valid nonce.
	 *
	 * The nonce proves the request came from the editor. It says nothing about
	 * whether this user may change anything, which is why both checks exist.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_save_without_the_capability_writes_nothing(): void {
		$prompt_id = $this->create_prompt( array( 'user_prompt' => 'original instruction' ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $subscriber );

		$this->assertFalse( current_user_can( Activation::MANAGE_CAPABILITY ) );

		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'autoscribe_save_prompt_' . $prompt_id ),
			Prompt_Fields::INPUT_NAME   => array( 'user_prompt' => 'injected instruction' ),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$this->assertSame(
			'original instruction',
			get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'user_prompt', true )
		);
	}

	/**
	 * A saved schedule is valid enough for the calculator to accept.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_saved_schedule_parameters_build_a_valid_schedule(): void {
		$prompt_id = $this->create_prompt();

		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'autoscribe_save_prompt_' . $prompt_id ),
			Prompt_Fields::INPUT_NAME   => array(
				'schedule_type' => 'monthly_ordinal',
				'ordinal'       => 'second',
				'weekday'       => 'tuesday',
				'time'          => '06:00',
			),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$prompt = Prompt::load( $prompt_id );

		$this->assertNotNull( $prompt );
		$this->assertNotWPError( $prompt->schedule() );
		$this->assertSame( 'monthly_ordinal', $prompt->schedule_type() );
	}

	/**
	 * A hostile select value falls back to the declared default.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_unlisted_select_values_are_rejected(): void {
		$prompt_id = $this->create_prompt();

		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'autoscribe_save_prompt_' . $prompt_id ),
			Prompt_Fields::INPUT_NAME   => array(
				'post_type'        => 'attachment',
				'post_status_mode' => 'whatever',
			),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$this->assertSame( 'post', get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'post_type', true ) );
		$this->assertSame( 'review', get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'post_status_mode', true ) );
	}

	/**
	 * No stored API key is echoed into the editor markup.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function test_render_does_not_leak_a_stored_key(): void {
		\AutoScribe\Security\Key_Store::set( 'anthropic', 'sk-secret-value-9876' );

		$markup = $this->render( $this->create_prompt() );

		$this->assertStringNotContainsString( 'sk-secret-value-9876', $markup );
	}
	/**
	 * A daily prompt is not asked which week of the month it means.
	 *
	 * Every schedule parameter used to render for every schedule type, so a daily
	 * prompt displayed a weekday, a day of the month, an ordinal week, an interval
	 * and a cron expression — five controls, four of which had no effect on it and
	 * none of which said so. The field list has always recorded which types each
	 * parameter belongs to; nothing read it.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_a_daily_prompt_only_shows_daily_fields(): void {
		$markup = $this->render_editor(
			$this->create_prompt(
				array(
					'schedule_type'   => 'daily',
					'schedule_params' => array( 'time' => '06:00' ),
				)
			)
		);

		$this->assertStringContainsString( 'autoscribe-field-time', $markup, 'A daily run happens at a time.' );

		foreach ( array( 'weekday', 'day_of_month', 'ordinal', 'hours', 'expression' ) as $irrelevant ) {
			$this->assertMatchesRegularExpression(
				'/<tr[^>]*style="display:none"[^>]*>(?:(?!<\/tr>).)*autoscribe-field-' . $irrelevant . '/s',
				$markup,
				sprintf( 'The %s row belongs to another schedule type and must be hidden.', $irrelevant )
			);
		}
	}

	/**
	 * A monthly prompt shows the fields that monthly type uses.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_a_monthly_ordinal_prompt_shows_its_own_fields(): void {
		$markup = $this->render_editor(
			$this->create_prompt(
				array(
					'schedule_type'   => 'monthly_ordinal',
					'schedule_params' => array(
						'time'    => '06:00',
						'ordinal' => 'second',
						'weekday' => 'tuesday',
					),
				)
			)
		);

		foreach ( array( 'time', 'ordinal', 'weekday' ) as $relevant ) {
			$this->assertDoesNotMatchRegularExpression(
				'/<tr[^>]*style="display:none"[^>]*>(?:(?!<\/tr>).)*autoscribe-field-' . $relevant . '/s',
				$markup,
				sprintf( 'The %s row is part of this schedule and must be shown.', $relevant )
			);
		}

		$this->assertMatchesRegularExpression(
			'/<tr[^>]*style="display:none"[^>]*>(?:(?!<\/tr>).)*autoscribe-field-day_of_month/s',
			$markup,
			'A prompt that repeats on the second Tuesday has no day of the month.'
		);
	}

	/**
	 * Every schedule parameter says which types it belongs to.
	 *
	 * The script follows the control as it changes, and it reads these.
	 *
	 * @since 1.10.0
	 *
	 * @return void
	 */
	public function test_schedule_rows_declare_the_types_they_belong_to(): void {
		$markup = $this->render_editor( $this->create_prompt() );

		$this->assertStringContainsString( 'data-autoscribe-for="daily weekly', $markup );
		$this->assertStringContainsString( 'data-autoscribe-for="interval"', $markup );
		$this->assertStringContainsString( 'data-autoscribe-for="cron_expression"', $markup );
	}

	/**
	 * The author is chosen by name, from the people who can hold a post.
	 *
	 * It was a number box until 1.14.0, which asked an editor to know a user ID
	 * and gave them no way to find one out. A wrong number is not rejected by
	 * anything either — every integer is a plausible user ID — so the failure was
	 * a post credited to somebody else, or to nobody.
	 *
	 * @since 1.14.0
	 *
	 * @return void
	 */
	public function test_the_author_field_offers_the_users_who_can_write(): void {
		$writer = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Rosa Writer',
				'user_login'   => 'rosa',
			)
		);

		$reader = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'reader',
			)
		);

		$markup = $this->render( $this->create_prompt( array( 'author_id' => $writer ) ) );

		$this->assertStringContainsString(
			'<select id="autoscribe-field-author_id" name="' . Prompt_Fields::INPUT_NAME . '[author_id]">',
			$markup,
			'The author control is a dropdown rather than a number box.'
		);
		$this->assertStringContainsString(
			'<option value="' . $writer . '" selected=\'selected\'>Rosa Writer (rosa)</option>',
			$markup,
			'It names the author the prompt holds, and it is the one selected.'
		);
		$this->assertStringNotContainsString(
			'<option value="' . $reader . '"',
			$markup,
			'Somebody who cannot write posts is not offered as one.'
		);
		$this->assertStringContainsString(
			'<option value="0"',
			$markup,
			'And leaving it unset stays possible.'
		);
	}

	/**
	 * An author the prompt already names is offered even when the list omits it.
	 *
	 * The list is capped and filtered by capability, so a stored author can fall
	 * outside it. Rendering without them would mean opening the tab and pressing
	 * Update quietly reassigned the prompt.
	 *
	 * @since 1.14.0
	 *
	 * @return void
	 */
	public function test_an_author_who_can_no_longer_write_is_still_shown(): void {
		$writer = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Sam Former',
				'user_login'   => 'sam',
			)
		);

		$prompt_id = $this->create_prompt( array( 'author_id' => $writer ) );

		// The role is taken away after the prompt was configured.
		( new \WP_User( $writer ) )->set_role( 'subscriber' );

		$markup = $this->render( $prompt_id );

		$this->assertStringContainsString(
			'<option value="' . $writer . '" selected=\'selected\'>Sam Former (sam)</option>',
			$markup
		);

		// And saving the form as rendered keeps them.
		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'autoscribe_save_prompt_' . $prompt_id ),
			Prompt_Fields::INPUT_NAME   => array( 'author_id' => (string) $writer ),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$this->assertSame(
			$writer,
			(int) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'author_id', true )
		);
	}

	/**
	 * An author who does not exist is not stored.
	 *
	 * @since 1.14.0
	 *
	 * @return void
	 */
	public function test_an_author_who_does_not_exist_is_discarded(): void {
		$prompt_id = $this->create_prompt();

		$_POST = array(
			Prompt_Meta_Box::NONCE_NAME => wp_create_nonce( 'autoscribe_save_prompt_' . $prompt_id ),
			Prompt_Fields::INPUT_NAME   => array( 'author_id' => '999999' ),
		);

		( new Prompt_Meta_Box() )->save( $prompt_id );

		$this->assertSame(
			0,
			(int) get_post_meta( $prompt_id, Prompt_Fields::PREFIX . 'author_id', true ),
			'A post credited to a user who is not there is worse than one credited to nobody.'
		);
	}

	/**
	 * Renders the prompt editor and returns its markup.
	 *
	 * @since 1.10.0
	 *
	 * @param int $prompt_id Prompt to render.
	 * @return string
	 */
	private function render_editor( int $prompt_id ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();

		( new Prompt_Meta_Box( new Provider_Registry() ) )->render( get_post( $prompt_id ) );

		return (string) ob_get_clean();
	}
}
