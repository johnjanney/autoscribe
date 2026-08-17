<?php
/**
 * Contract every text provider adapter implements.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers;

use AutoScribe\Providers\Request\Generation_Request;
use AutoScribe\Providers\Response\Generation_Result;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One text provider.
 *
 * @since 0.2.0
 */
interface Text_Provider_Interface {

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
	 * These are suggestions for a datalist, never values baked into a request.
	 * Section 2.2 requires the model ID to stay user-editable so a retirement
	 * does not break the plugin.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array;

	/**
	 * Whether the provider offers server-side web search grounding.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_web_search(): bool;

	/**
	 * Whether the provider can constrain output to a JSON Schema.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_strict_json(): bool;

	/**
	 * Whether schema-constrained output and grounding work in the same call.
	 *
	 * Not implied by the previous two. On some providers the combination is
	 * rejected or silently degrades, so the pipeline needs to know before it
	 * asks for both and gets neither.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function supports_strict_json_with_search(): bool;

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
	 * Generates text.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $api_key Provider API key.
	 * @param string             $model   Model identifier.
	 * @param Generation_Request $request What to generate.
	 * @return Generation_Result|WP_Error Result on success, error otherwise.
	 */
	public function generate( string $api_key, string $model, Generation_Request $request ): Generation_Result|WP_Error;
}
