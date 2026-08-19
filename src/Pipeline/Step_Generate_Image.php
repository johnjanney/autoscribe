<?php
/**
 * Image generation step.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Content\Article;
use AutoScribe\Media\Image_Sideloader;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Image\Null_Image;
use AutoScribe\Providers\Model_Resolver;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Providers\Response\Image_Result;
use AutoScribe\Security\Content_Sanitizer;
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
	 * Media sideloader.
	 *
	 * @since 1.1.0
	 * @var Image_Sideloader
	 */
	private Image_Sideloader $sideloader;

	/**
	 * Content sanitiser, used for the alt text.
	 *
	 * @since 1.1.0
	 * @var Content_Sanitizer
	 */
	private Content_Sanitizer $sanitizer;

	/**
	 * Builds the step.
	 *
	 * @since 0.3.0
	 *
	 * @param Provider_Registry      $registry   Provider registry.
	 * @param Image_Sideloader|null  $sideloader Sideloader, or null to build a default.
	 * @param Content_Sanitizer|null $sanitizer Sanitiser, or null to build a default.
	 */
	public function __construct( Provider_Registry $registry, ?Image_Sideloader $sideloader = null, ?Content_Sanitizer $sanitizer = null ) {
		$this->registry   = $registry;
		$this->sideloader = $sideloader instanceof Image_Sideloader ? $sideloader : new Image_Sideloader();
		$this->sanitizer  = $sanitizer instanceof Content_Sanitizer ? $sanitizer : new Content_Sanitizer();
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

		if ( ! is_wp_error( $result ) && ! $run->record_image( $result->model() ) ) {
			return new WP_Error(
				'autoscribe_usage_not_recorded',
				__( 'An image was generated and charged for, but the run log would not record it. The run was stopped so the charge is still counted against the monthly cap.', 'autoscribe' )
			);
		}

		return $result;
	}

	/**
	 * Generates the featured image and attaches it to the post.
	 *
	 * This used to live in Generator as a private method, which left the step
	 * owning only the provider call while the sideload, the thumbnail, and the
	 * decision about what to do when an image cannot be had sat outside it. That
	 * split had a practical cost as well as an aesthetic one: the idempotency
	 * guard section 5 asks for belongs with the paid call, and a guard in the
	 * orchestrator could not be tested, because the orchestrator runs the whole
	 * pipeline once and never re-enters. Phase 3 needs this as a real step in any
	 * case.
	 *
	 * Section 6's image_mode decides what a failure means: `required` fails the
	 * run and leaves the draft, `fallback` attaches the prompt's stock image,
	 * `optional` publishes without one, and `none` never gets here.
	 *
	 * @since 1.1.0
	 *
	 * @param Prompt  $prompt  Prompt being run.
	 * @param Article $article Validated article.
	 * @param Run     $run     Run recording progress.
	 * @param int     $post_id Post to attach to.
	 * @return int|WP_Error Attachment ID, 0 where no image was attached, or an error.
	 */
	public function attach( Prompt $prompt, Article $article, Run $run, int $post_id ): int|WP_Error {
		$mode = $prompt->image_mode();

		if ( 'none' === $mode ) {
			return 0;
		}

		$settled = $this->settled_image( $run, $post_id );

		if ( null !== $settled ) {
			return $settled;
		}

		$image = $this->run( $prompt, $article, $run );

		if ( ! is_wp_error( $image ) ) {
			$attachment_id = $this->sideloader->sideload(
				$image,
				$post_id,
				$this->sanitizer->sanitize_image_alt( $article->image_alt() ),
				$article->title()
			);

			if ( is_wp_error( $attachment_id ) ) {
				$image = $attachment_id;
			} elseif ( $this->set_thumbnail( $post_id, (int) $attachment_id ) ) {
				return $this->remember( $run, (int) $attachment_id );
			} else {
				/*
				 * One branch each, and no assignment after them. Written as an
				 * inner condition followed by a common assignment, the failure
				 * error was replaced by the attachment ID a line later and the
				 * mode handling below then called get_error_code() on an integer.
				 */
				$image = new WP_Error(
					'autoscribe_thumbnail_not_set',
					__( 'The featured image was generated but WordPress would not attach it to the post.', 'autoscribe' )
				);
			}
		}

		if ( Null_Image::SKIPPED === $image->get_error_code() ) {
			return $this->remember( $run, 0 );
		}

		if ( 'required' === $mode ) {
			return $image;
		}

		if ( 'fallback' === $mode && $this->set_thumbnail( $post_id, $prompt->fallback_image_id() ) ) {
			return $this->remember( $run, $prompt->fallback_image_id() );
		}

		return $this->remember( $run, 0 );
	}

	/**
	 * Returns the image outcome this run already settled on, if it settled one.
	 *
	 * Section 5 requires each step to be idempotent keyed by run ID. Image
	 * generation is a paid call, so re-entering without this guard buys a second
	 * picture for a post that already has one.
	 *
	 * Every terminal outcome is recorded, including the ones that produced no
	 * image at all. "This run gave up on an image" is a decision, and re-running
	 * would pay a provider to make it again — a prompt in optional mode whose
	 * provider is having a bad hour would buy an image on every re-entry until
	 * one happened to succeed.
	 *
	 * The thumbnail is re-applied rather than assumed, because the attachment can
	 * survive while the post's featured image is cleared by hand in between.
	 *
	 * @since 1.1.0
	 *
	 * @param Run $run     Run recording progress.
	 * @param int $post_id Post the image belongs to.
	 * @return int|null Attachment ID, 0 where the run settled on no image, or
	 *                  null when nothing has been settled yet.
	 */
	private function settled_image( Run $run, int $post_id ): ?int {
		$stored = $run->payload()['image'] ?? null;

		if ( ! is_array( $stored ) || ! array_key_exists( 'attachment_id', $stored ) ) {
			return null;
		}

		$attachment_id = (int) $stored['attachment_id'];

		if ( 0 === $attachment_id ) {
			return 0;
		}

		if ( null === get_post( $attachment_id ) ) {
			// Deleted since. Generating again is the lesser evil.
			return null;
		}

		return $this->set_thumbnail( $post_id, $attachment_id ) ? $attachment_id : null;
	}

	/**
	 * Attaches a featured image and confirms it is really attached.
	 *
	 * WordPress returns false from set_post_thumbnail() when it fails *and* when
	 * the post already carries that same thumbnail, so its return value cannot tell a
	 * refusal from a no-op. Asking the post what its thumbnail is answers the
	 * question that actually matters, and it is the question section 6's
	 * `required` mode turns on: a run that reports success without a featured
	 * image has published exactly what that mode exists to prevent.
	 *
	 * @since 1.1.1
	 *
	 * @param int $post_id       Post to attach to.
	 * @param int $attachment_id Attachment to attach, or 0 to check nothing.
	 * @return bool
	 */
	private function set_thumbnail( int $post_id, int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return (int) get_post_thumbnail_id( $post_id ) === $attachment_id;
	}

	/**
	 * Records the image outcome so a re-entry does not buy another one.
	 *
	 * @since 1.1.0
	 *
	 * @param Run $run           Run recording progress.
	 * @param int $attachment_id Attachment ID, or 0 where no image was attached.
	 * @return int|WP_Error
	 */
	private function remember( Run $run, int $attachment_id ): int|WP_Error {
		if ( ! $run->merge_payload( array( 'image' => array( 'attachment_id' => $attachment_id ) ) ) ) {
			return new WP_Error(
				'autoscribe_state_not_recorded',
				__( 'The featured image outcome could not be written to the run log, so the run was stopped rather than continuing on state that would be lost.', 'autoscribe' )
			);
		}

		return $attachment_id;
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
