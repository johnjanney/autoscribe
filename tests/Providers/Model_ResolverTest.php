<?php
/**
 * Model resolution tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Providers;

use AutoScribe\Admin\Settings;
use AutoScribe\Providers\Model_Resolver;
use WP_UnitTestCase;

/**
 * Covers which model ID a call ends up using.
 *
 * Section 9.4 puts a default model per provider on the settings screen, and
 * section 2.2 makes model IDs configuration. Those two only connect if
 * generation reads the setting, which it did not: a blank prompt field fell
 * straight through to the adapter's first hard-coded suggestion, so an
 * administrator could set a site default, watch it save, and have it ignored on
 * every run.
 *
 * @since 1.0.1
 */
final class Model_ResolverTest extends WP_UnitTestCase {

	/**
	 * Clears the settings option between tests.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * A model set on the prompt wins over everything else.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_prompt_model_wins(): void {
		Settings::save( array( 'default_models' => array( 'anthropic' => 'site-default' ) ) );

		$this->assertSame(
			'prompt-model',
			Model_Resolver::resolve( 'prompt-model', 'anthropic', array( 'suggested' ) )
		);
	}

	/**
	 * A blank prompt field falls back to the site default, not the suggestion.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_site_default_beats_the_adapter_suggestion(): void {
		Settings::save( array( 'default_models' => array( 'anthropic' => 'site-default' ) ) );

		$this->assertSame(
			'site-default',
			Model_Resolver::resolve( '', 'anthropic', array( 'suggested' ) )
		);
	}

	/**
	 * With nothing configured, the adapter's suggestion is the last resort.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_suggestion_is_the_last_resort(): void {
		$this->assertSame(
			'suggested',
			Model_Resolver::resolve( '', 'anthropic', array( 'suggested', 'other' ) )
		);
	}

	/**
	 * Nothing configured anywhere resolves to an empty string, not a guess.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_nothing_configured_resolves_to_empty(): void {
		$this->assertSame( '', Model_Resolver::resolve( '', 'anthropic' ) );
	}

	/**
	 * Whitespace is not a configured model.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public function test_whitespace_is_treated_as_unset(): void {
		Settings::save( array( 'default_models' => array( 'anthropic' => 'site-default' ) ) );

		$this->assertSame(
			'site-default',
			Model_Resolver::resolve( '   ', 'anthropic', array( 'suggested' ) )
		);
	}
}
