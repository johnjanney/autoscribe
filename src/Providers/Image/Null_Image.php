<?php
/**
 * Null image provider.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Image;

use AutoScribe\Providers\Image_Provider_Interface;
use AutoScribe\Providers\Response\Image_Result;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stands in when a prompt's image mode is "none".
 *
 * Makes no HTTP request at all, which is the point: the pipeline can always
 * resolve an image provider and never has to branch on null. The error code it
 * returns is deliberately distinct from a real failure so section 6's image
 * mode handling can tell "deliberately skipped" apart from "generation failed".
 *
 * @since 0.2.0
 */
final class Null_Image implements Image_Provider_Interface {

	/**
	 * Error code signalling a deliberate skip rather than a failure.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const SKIPPED = 'autoscribe_image_disabled';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'No image', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function suggested_models(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Always succeeds. There is no remote service to reach, so there is nothing
	 * that can be misconfigured.
	 *
	 * @since 0.2.0
	 *
	 * @param string $api_key Ignored.
	 * @param string $model   Ignored.
	 * @return bool|WP_Error
	 */
	public function test_connection( string $api_key, string $model ): bool|WP_Error {
		unset( $api_key, $model );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 *
	 * @param string $api_key Ignored.
	 * @param string $model   Ignored.
	 * @param string $prompt  Ignored.
	 * @return Image_Result|WP_Error
	 */
	public function generate_image( string $api_key, string $model, string $prompt ): Image_Result|WP_Error {
		unset( $api_key, $model, $prompt );

		return new WP_Error(
			self::SKIPPED,
			__( 'Image generation is switched off for this prompt.', 'autoscribe' )
		);
	}
}
