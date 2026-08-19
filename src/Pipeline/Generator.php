<?php
/**
 * Synchronous generation orchestrator.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Admin\Settings;
use AutoScribe\Content\Article;
use AutoScribe\Content\Article_Validator;
use AutoScribe\Content\Taxonomy_Applier;
use AutoScribe\Content\Topic_Deduplicator;
use AutoScribe\Cost\Pricing_Table;
use AutoScribe\SEO\SEO_Adapter_Factory;
use AutoScribe\Media\Image_Sideloader;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Providers\Image\Null_Image;
use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Security\Content_Sanitizer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one prompt from instruction to published post, in a single request.
 *
 * Phase 4 replaces the body of run() with Action Scheduler dispatch across the
 * same steps. The steps themselves are written to be movable: each takes what
 * it needs and returns a value or a WP_Error, with no shared mutable state
 * beyond the Run row.
 *
 * @since 0.3.0
 */
final class Generator {

	/**
	 * Budget check step, which runs before anything is spent.
	 *
	 * @since 0.5.0
	 * @var Step_Budget_Check
	 */
	private Step_Budget_Check $budget_step;

	/**
	 * Topic proposal step, which catches duplicates before the body call.
	 *
	 * @since 0.5.0
	 * @var Step_Propose_Topic
	 */
	private Step_Propose_Topic $topic_step;

	/**
	 * Body generation step.
	 *
	 * @since 0.3.0
	 * @var Step_Generate_Body
	 */
	private Step_Generate_Body $body_step;

	/**
	 * Image generation step.
	 *
	 * @since 0.3.0
	 * @var Step_Generate_Image
	 */
	private Step_Generate_Image $image_step;

	/**
	 * Post assembly step.
	 *
	 * @since 0.3.0
	 * @var Step_Assemble_Post
	 */
	private Step_Assemble_Post $assemble_step;

	/**
	 * Media sideloader.
	 *
	 * @since 0.3.0
	 * @var Image_Sideloader
	 */
	private Image_Sideloader $sideloader;

	/**
	 * Builds the orchestrator.
	 *
	 * @since 0.3.0
	 *
	 * @param Provider_Registry $registry Provider registry.
	 */
	public function __construct( Provider_Registry $registry ) {
		$this->budget_step   = new Step_Budget_Check();
		$this->topic_step    = new Step_Propose_Topic( $registry, new Topic_Deduplicator() );
		$this->body_step     = new Step_Generate_Body( $registry, new Article_Validator() );
		$this->image_step    = new Step_Generate_Image( $registry );
		$this->assemble_step = new Step_Assemble_Post(
			new Content_Sanitizer(),
			new SEO_Adapter_Factory(),
			new Taxonomy_Applier()
		);
		$this->sideloader    = new Image_Sideloader();
	}

	/**
	 * Runs one prompt end to end.
	 *
	 * @since 0.3.0
	 *
	 * @param int         $prompt_id       Prompt to run.
	 * @param string|null $status_override Final post status, or null to use the prompt's mode.
	 * @param int         $attempt         Attempt number, so the run row records the real one.
	 * @return array<string, int|string>|WP_Error Summary of what was produced, or an error.
	 */
	public function run( int $prompt_id, ?string $status_override = null, int $attempt = 1 ): array|WP_Error {
		$prompt = Prompt::load( $prompt_id );

		if ( null === $prompt ) {
			return new WP_Error(
				'autoscribe_unknown_prompt',
				sprintf(
					/* translators: %d: post ID. */
					__( 'No prompt exists with ID %d.', 'autoscribe' ),
					$prompt_id
				)
			);
		}

		$run = Run::start( $prompt_id, $attempt );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$grounded = $prompt->grounding_enabled() ? 1 : 0;
		$pricing  = new Pricing_Table();

		// Section 7.4: first, and before any paid call. On a breach the run is
		// abandoned rather than partially executed.
		$budget = $this->budget_step->run( $prompt, $run );

		if ( is_wp_error( $budget ) ) {
			return $budget;
		}

		$run->record_step( 'budget_check' );

		/*
		 * The attempt immediately before this one may have got as far as a draft
		 * before failing. Bind it to this run so assembly updates that draft
		 * instead of adding a second one. Run::adoptable_draft() refuses anything
		 * that is not the previous attempt of this retry series, and anything a
		 * person has touched since.
		 *
		 * This is resolved before the proposal call, not after the body call, so
		 * that duplicate detection can be told to ignore the draft this run is
		 * about to overwrite.
		 */
		$inherited = Run::adoptable_draft( $prompt_id, $run->id(), $attempt );

		if ( null !== $inherited ) {
			$run->adopt_post( $inherited );
		}

		// Section 7.2: a cheap proposal call, so a duplicate is caught before
		// paying to write an article that would be discarded.
		$topic = $this->topic_step->run( $prompt, $run, (int) $inherited );

		if ( is_wp_error( $topic ) ) {
			if ( 'autoscribe_duplicate_topic' !== $topic->get_error_code() ) {
				$run->fail( $topic->get_error_message(), $pricing, $grounded );
			}

			return $topic;
		}

		$run->record_step( 'propose_topic' );

		$article = $this->body_step->run( $prompt, $run, $topic );

		if ( is_wp_error( $article ) ) {
			$run->fail( $article->get_error_message(), $pricing, $grounded );

			return $article;
		}

		$run->record_step( 'generate_body' );

		$post_id = $this->assemble_step->run( $prompt, $article, $run );

		if ( is_wp_error( $post_id ) ) {
			$run->fail( $post_id->get_error_message(), $pricing, $grounded );

			return $post_id;
		}

		$run->record_step( 'assemble_post' );

		$attachment_id = $this->attach_image( $prompt, $article, $run, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			$run->fail( $attachment_id->get_error_message(), $pricing, $grounded );

			return $attachment_id;
		}

		$run->record_step( 'generate_image' );

		$status = $this->final_status( $prompt, $status_override );

		/*
		 * A refused status transition used to pass unnoticed, and the run then
		 * reported success for a post still sitting in draft. Section 10 makes
		 * the difference between draft and published the whole safety model, so
		 * the failure is surfaced rather than swallowed.
		 */
		if ( 'draft' !== $status ) {
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				$run->fail( $updated->get_error_message(), $pricing, $grounded );

				return $updated;
			}
		}

		// Section 7.4: replace the reservation with what the run actually cost,
		// now that the providers have reported their usage.
		$cost = $run->settle_cost( $pricing, $grounded );

		$run->succeed();

		if ( 'draft' === $status ) {
			$this->send_review_notice( $article, $post_id );
		}

		if ( $this->budget_step->guard()->should_send_warning() ) {
			$this->send_budget_warning();
		}

		return array(
			'run_id'        => $run->id(),
			'post_id'       => $post_id,
			'attachment_id' => (int) $attachment_id,
			'status'        => $status,
			'cost_cents'    => $cost,
		);
	}

	/**
	 * Tells the notification address that a draft is waiting for review.
	 *
	 * Section 10 requires this for every review-mode draft, with the title, the
	 * opening of the article, and a direct edit link. Without it review mode is
	 * a queue nobody is told about, which is the failure that leads people to
	 * turn review off and publish unread.
	 *
	 * @since 1.0.1
	 *
	 * @param Article $article Validated article.
	 * @param int     $post_id Draft post ID.
	 * @return void
	 */
	private function send_review_notice( Article $article, int $post_id ): void {
		$address = Settings::notification_email();

		if ( '' === $address ) {
			return;
		}

		$opening = trim( wp_strip_all_tags( $article->raw_content_html() ) );

		wp_mail(
			$address,
			sprintf(
				/* translators: %s: article title. */
				__( 'AutoScribe draft ready for review: %s', 'autoscribe' ),
				$article->title()
			),
			sprintf(
				/* translators: 1: article title, 2: opening of the article, 3: edit URL. */
				__( "AutoScribe generated a draft and held it for review.\n\n%1\$s\n\n%2\$s\n\nReview and publish it here:\n%3\$s", 'autoscribe' ),
				$article->title(),
				mb_substr( $opening, 0, 200 ),
				(string) get_edit_post_link( $post_id, 'raw' )
			)
		);
	}

	/**
	 * Tells the notification address that a run failed for good.
	 *
	 * Section 5 asks for one notification after the attempts are exhausted, not
	 * one per attempt, so this is called by the queue handler once it has decided
	 * there will be no further try.
	 *
	 * @since 1.0.1
	 *
	 * @param int      $prompt_id Prompt that failed.
	 * @param WP_Error $error     The final failure.
	 * @return void
	 */
	public static function send_failure_notice( int $prompt_id, WP_Error $error ): void {
		$address = Settings::notification_email();

		if ( '' === $address ) {
			return;
		}

		wp_mail(
			$address,
			sprintf(
				/* translators: %s: prompt title. */
				__( 'AutoScribe run failed: %s', 'autoscribe' ),
				get_the_title( $prompt_id )
			),
			sprintf(
				/* translators: 1: prompt title, 2: error message, 3: edit URL. */
				__( "An AutoScribe prompt failed and will not be retried.\n\nPrompt: %1\$s\nReason: %2\$s\n\nThe run log has the detail. The prompt is here:\n%3\$s", 'autoscribe' ),
				get_the_title( $prompt_id ),
				$error->get_error_message(),
				(string) get_edit_post_link( $prompt_id, 'raw' )
			)
		);
	}

	/**
	 * Sends the single monthly warning required by section 7.4.
	 *
	 * The guard decides whether to send; this only performs the delivery, so the
	 * "one email per month, not one per run" rule lives in one place.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	private function send_budget_warning(): void {
		$address = Settings::notification_email();

		if ( '' === $address ) {
			return;
		}

		wp_mail(
			$address,
			__( 'AutoScribe has reached 80% of its monthly budget', 'autoscribe' ),
			__( 'Estimated AutoScribe spend for this month has passed 80 percent of the configured global cap. Runs will stop once the cap is reached. These figures are estimates; your provider billing is the authority.', 'autoscribe' )
		);
	}

	/**
	 * Generates and attaches the featured image, honouring the image mode.
	 *
	 * Section 6 defines four behaviours on failure. Only "required" aborts the
	 * run; the post has already been created as a draft by this point, which is
	 * exactly what that mode asks for.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt  $prompt  Prompt being run.
	 * @param Article $article Validated article.
	 * @param Run     $run     Run recording progress.
	 * @param int     $post_id Draft post ID.
	 * @return int|WP_Error Attachment ID, 0 when no image was attached, or an error.
	 */
	private function attach_image( Prompt $prompt, Article $article, Run $run, int $post_id ): int|WP_Error {
		$mode = $prompt->image_mode();

		if ( 'none' === $mode ) {
			return 0;
		}

		$image = $this->image_step->run( $prompt, $article, $run );

		if ( ! is_wp_error( $image ) ) {
			$attachment_id = $this->sideloader->sideload(
				$image,
				$post_id,
				$this->sanitized_alt( $article ),
				$article->title()
			);

			if ( ! is_wp_error( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );

				return $attachment_id;
			}

			$image = $attachment_id;
		}

		if ( Null_Image::SKIPPED === $image->get_error_code() ) {
			return 0;
		}

		if ( 'required' === $mode ) {
			return $image;
		}

		if ( 'fallback' === $mode && $prompt->fallback_image_id() > 0 ) {
			set_post_thumbnail( $post_id, $prompt->fallback_image_id() );

			return $prompt->fallback_image_id();
		}

		return 0;
	}

	/**
	 * Returns the alt text, sanitised and length-capped.
	 *
	 * @since 0.3.0
	 *
	 * @param Article $article Validated article.
	 * @return string
	 */
	private function sanitized_alt( Article $article ): string {
		return ( new Content_Sanitizer() )->sanitize_image_alt( $article->image_alt() );
	}

	/**
	 * Resolves the final post status.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt      $prompt   Prompt being run.
	 * @param string|null $override Explicit status, or null.
	 * @return string
	 */
	private function final_status( Prompt $prompt, ?string $override ): string {
		/*
		 * Section 10's global override wins over everything, including an explicit
		 * caller override. It is the safety catch for the moment a provider changes
		 * behaviour or a prompt starts producing garbage, and a catch that any
		 * caller can step around is not a catch. Checked first for that reason.
		 */
		if ( Settings::force_review() ) {
			return 'draft';
		}

		if ( is_string( $override ) && in_array( $override, array( 'draft', 'publish', 'pending' ), true ) ) {
			return $override;
		}

		return 'auto' === $prompt->post_status_mode() ? 'publish' : 'draft';
	}
}
