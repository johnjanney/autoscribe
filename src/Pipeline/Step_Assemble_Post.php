<?php
/**
 * Post assembly step.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Content\Article;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Security\Content_Sanitizer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Inserts the generated article as a post.
 *
 * The post is created as a draft and promoted later. Section 5 orders image
 * generation before assembly, but section 6 requires that a failure in
 * "required" mode leave the post saved as a draft, and no post exists at that
 * point in the stated order. Creating the draft first makes all four image
 * modes expressible and gives set_post_thumbnail() an ID to attach to.
 *
 * @since 0.3.0
 */
final class Step_Assemble_Post {

	/**
	 * Meta key linking a post back to its run, per section 10.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const RUN_ID_META = '_autoscribe_run_id';

	/**
	 * Meta key holding the deduplication topic key, read by section 7.2.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const TOPIC_KEY_META = '_autoscribe_topic_key';

	/**
	 * Output sanitiser.
	 *
	 * @since 0.3.0
	 * @var Content_Sanitizer
	 */
	private Content_Sanitizer $sanitizer;

	/**
	 * Builds the step.
	 *
	 * @since 0.3.0
	 *
	 * @param Content_Sanitizer $sanitizer Output sanitiser.
	 */
	public function __construct( Content_Sanitizer $sanitizer ) {
		$this->sanitizer = $sanitizer;
	}

	/**
	 * Creates the draft post.
	 *
	 * @since 0.3.0
	 *
	 * @param Prompt  $prompt  Prompt being run.
	 * @param Article $article Validated article.
	 * @param Run     $run     Run recording progress.
	 * @return int|WP_Error Post ID, or an error.
	 */
	public function run( Prompt $prompt, Article $article, Run $run ): int|WP_Error {
		// Idempotency, required by section 5: a retried step must not create a
		// second post.
		$existing = $run->post_id();

		if ( null !== $existing ) {
			return $existing;
		}

		$body = $this->sanitizer->sanitize_body( $article->raw_content_html() );

		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			return new WP_Error(
				'autoscribe_empty_body',
				__( 'Nothing survived sanitisation of the generated body.', 'autoscribe' )
			);
		}

		if ( $this->sanitizer->has_dangerous_uri( $body ) ) {
			return new WP_Error(
				'autoscribe_unsafe_body',
				__( 'A dangerous URI scheme survived sanitisation; the article was discarded.', 'autoscribe' )
			);
		}

		$postarr = array(
			'post_type'    => $prompt->post_type(),
			'post_status'  => 'draft',
			'post_title'   => $this->sanitizer->sanitize_title( $article->title() ),
			'post_content' => $body,
			'post_excerpt' => $this->sanitizer->sanitize_meta_description( $article->excerpt() ),
		);

		if ( $prompt->author_id() > 0 ) {
			$postarr['post_author'] = $prompt->author_id();
		}

		if ( 'post' === $prompt->post_type() && array() !== $prompt->category_ids() ) {
			$postarr['post_category'] = $prompt->category_ids();
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, self::RUN_ID_META, $run->id() );
		update_post_meta(
			$post_id,
			self::TOPIC_KEY_META,
			$this->sanitizer->sanitize_topic_key( $article->topic_key() )
		);

		$run->record_post( $post_id );
		$run->record_article(
			$this->sanitizer->sanitize_title( $article->title() ),
			$this->sanitizer->sanitize_topic_key( $article->topic_key() )
		);

		return $post_id;
	}
}
