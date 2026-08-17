<?php
/**
 * Contract every image provider adapter implements.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers;

use AutoScribe\Providers\Response\Image_Result;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One image provider.
 *
 * Kept separate from the text contract because section 2.1 establishes that
 * text and image capability do not travel together: Anthropic and DeepSeek
 * generate no images at all, so a combined interface would force two adapters
 * to implement a method they can never satisfy.
 *
 * @since 0.2.0
 */
interface Image_Provider_Interface {

	/**
	 * Returns the stable slug stored in prompt meta.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Returns the human-readable provider name.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Returns model IDs to seed the editable model field.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array;

	/**
	 * Verifies that a key and model ID are usable.
	 *
	 * @since 0.2.0
	 *
	 * @param string $api_key Provider API key.
	 * @param string $model   Model identifier to check.
	 * @return bool|WP_Error True on success, error describing the failure otherwise.
	 */
	public function test_connection( string $api_key, string $model ): bool|WP_Error;

	/**
	 * Generates one image.
	 *
	 * @since 0.2.0
	 *
	 * @param string $api_key Provider API key.
	 * @param string $model   Model identifier.
	 * @param string $prompt  Fully assembled image prompt.
	 * @return Image_Result|WP_Error Result on success, error otherwise.
	 */
	public function generate_image( string $api_key, string $model, string $prompt ): Image_Result|WP_Error;
}
