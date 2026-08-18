<?php
/**
 * Image generation step.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Content\Article;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Model_Resolver;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Providers\Response\Image_Result;
use AutoScribe\Security\Key_Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the image provider for the featured image.
 *
 * @since 0.3.0
 */
final class Step_Generate_Image {

	/**
	 * Provider registry.
	 *
	 * @since 0.3.0
	 * @var Provider_Registry
	 */
	private Provider_Registry $registry;

	/**
	 * Builds the step.
	 *
	 * @since 0.3.0
	 *
	 * @param Provider_Registry $registry Provider registry.
	 */
	public function __construct( Provider_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Generates the featured image.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt  $prompt  Prompt being run.
	 * @param Article $article Validated article carrying the image prompt.
	 * @param Run     $run     Run recording progress.
	 * @return Image_Result|WP_Error
	 */
	public function run( Prompt $prompt, Article $article, Run $run ): Image_Result|WP_Error {
		$slug     = 'none' === $prompt->image_mode() ? 'none' : $prompt->image_provider();
		$provider = $this->registry->image_provider( $slug );

		if ( null === $provider ) {
			return new WP_Error(
				'autoscribe_unknown_provider',
				sprintf(
					/* translators: %s: provider slug. */
					__( 'No image provider is registered under the slug %s.', 'autoscribe' ),
					$slug
				)
			);
		}

		if ( 'none' === $slug ) {
			return $provider->generate_image( '', '', '' );
		}

		$api_key = Key_Store::get( $slug );

		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$model = Model_Resolver::resolve( $prompt->image_model(), $slug, $provider->suggested_models() );

		if ( '' === $model ) {
			return new WP_Error(
				'autoscribe_missing_model',
				__( 'No image model ID is configured for this prompt.', 'autoscribe' )
			);
		}

		$result = $provider->generate_image( $api_key, $model, $this->image_prompt( $prompt, $article ) );

		if ( ! is_wp_error( $result ) ) {
			$run->record_image( $result->model() );
		}

		return $result;
	}

	/**
	 * Combines the article's image prompt with the prompt's house style.
	 *
	 * Section 6 uses the suffix to hold a consistent look across every article
	 * without editing each prompt.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt  $prompt  Prompt being run.
	 * @param Article $article Validated article.
	 * @return string
	 */
	private function image_prompt( Prompt $prompt, Article $article ): string {
		return trim( $article->image_prompt() . ' ' . $prompt->image_style_suffix() );
	}
}
