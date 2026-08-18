<?php
/**
 * Model ID resolution.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers;

use AutoScribe\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which model ID a call should use.
 *
 * Section 2.2 makes model IDs configuration rather than constants, and section
 * 9.4 puts a default model per provider on the settings screen. Those two only
 * connect if generation reads the setting, which it previously did not: a blank
 * prompt field fell straight through to the adapter's first hard-coded
 * suggestion, so an administrator could set a site default, see it saved, and
 * have it silently ignored on every run.
 *
 * The order is prompt, then site default, then the adapter's suggestion. Most
 * specific wins, and the adapter's suggestion is the last resort rather than the
 * first answer.
 *
 * @since 1.0.1
 */
final class Model_Resolver {

	/**
	 * Returns the model ID to use for a provider.
	 *
	 * @since 1.0.1
	 *
	 * @param string   $prompt_model Model set on the prompt, possibly empty.
	 * @param string   $slug         Provider slug.
	 * @param string[] $suggestions  The adapter's suggested model IDs.
	 * @return string Empty when nothing is configured anywhere.
	 */
	public static function resolve( string $prompt_model, string $slug, array $suggestions = array() ): string {
		$prompt_model = trim( $prompt_model );

		if ( '' !== $prompt_model ) {
			return $prompt_model;
		}

		$default = trim( Settings::default_model( $slug ) );

		if ( '' !== $default ) {
			return $default;
		}

		return (string) ( $suggestions[0] ?? '' );
	}
}
