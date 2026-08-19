<?php
/**
 * Post assembly step.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Pipeline;

use AutoScribe\Content\Article;
use AutoScribe\Content\Taxonomy_Applier;
use AutoScribe\Prompts\Prompt;
use AutoScribe\SEO\SEO_Adapter_Factory;
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
	 * SEO adapter detection.
	 *
	 * @since 0.5.0
	 * @var SEO_Adapter_Factory
	 */
	private SEO_Adapter_Factory $seo;

	/**
	 * Taxonomy assignment.
	 *
	 * @since 0.5.0
	 * @var Taxonomy_Applier
	 */
	private Taxonomy_Applier $taxonomy;

	/**
	 * Builds the step.
	 *
	 * @since 0.3.0
	 *
	 * @param Content_Sanitizer   $sanitizer Output sanitiser.
	 * @param SEO_Adapter_Factory $seo       SEO adapter detection.
	 * @param Taxonomy_Applier    $taxonomy  Taxonomy assignment.
	 */
	public function __construct( Content_Sanitizer $sanitizer, SEO_Adapter_Factory $seo, Taxonomy_Applier $taxonomy ) {
		$this->sanitizer = $sanitizer;
		$this->seo       = $seo;
		$this->taxonomy  = $taxonomy;
	}

	/**
	 * Builds the optional Sources list from section 7.1.
	 *
	 * Appended after sanitisation rather than before, because this markup is the
	 * plugin's own and would only be re-escaped by a second pass. The URLs are
	 * not ours, though — they come from the provider — so each one goes through
	 * esc_url, and anything that survives as empty is dropped rather than
	 * rendered as a link to nowhere.
	 *
	 * @since 0.8.0
	 *
	 * @param Prompt $prompt Prompt being run.
	 * @param Run    $run    Run recording progress.
	 * @return string Markup to append, or an empty string.
	 */
	private function sources_block( Prompt $prompt, Run $run ): string {
		if ( ! $prompt->append_sources() ) {
			return '';
		}

		$items = '';

		foreach ( $run->sources() as $url ) {
			$safe = esc_url( (string) $url );

			if ( '' === $safe ) {
				continue;
			}

			$items .= sprintf( '<li><a href="%1$s" rel="nofollow noopener">%1$s</a></li>', $safe );
		}

		if ( '' === $items ) {
			return '';
		}

		return sprintf(
			'<h2>%s</h2><ul>%s</ul>',
			esc_html__( 'Sources', 'autoscribe' ),
			$items
		);
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
		/*
		 * Idempotency, required by section 5: a retried step must not create a
		 * second post. A post already bound to this run is updated in place
		 * rather than returned untouched, because a retry that regenerated the
		 * article has new content to write and returning early would leave the
		 * earlier attempt's text sitting under a run that never produced it.
		 */
		$existing = $run->post_id();

		if ( null !== $existing && 'draft' !== get_post_status( $existing ) ) {
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

		$body .= $this->sources_block( $prompt, $run );

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

		if ( null !== $existing ) {
			$postarr['ID'] = $existing;

			$post_id = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

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

		/*
		 * SEO metadata is written while the post is still a draft, before the
		 * pipeline transitions it to its final status. That ordering is what
		 * makes the Yoast adapter work: Yoast rebuilds its indexable on save,
		 * and the indexable is what it reads when rendering, so the metadata has
		 * to be in place before that save rather than after it.
		 */
		$this->seo->detect()->apply(
			$post_id,
			$this->sanitizer->sanitize_seo_title( $article->seo_title() ),
			$this->sanitizer->sanitize_meta_description( $article->meta_description() ),
			sanitize_text_field( $article->focus_keyword() )
		);

		$this->taxonomy->apply( $post_id, $prompt, $article->suggested_tags() );

		/*
		 * Later steps read the post back off the run rather than receiving it as
		 * an argument, because they run in separate requests. A refused write
		 * therefore leaves a post nothing points at: the image step would attach
		 * its picture to post 0 and publishing would look for one that was never
		 * recorded. The post itself is left in place — it is a draft, and losing
		 * the link to it is better than deleting an article that was paid for.
		 */
		if ( ! $run->record_post( $post_id ) ) {
			return new WP_Error(
				'autoscribe_state_not_recorded',
				sprintf(
					/* translators: %d: post ID. */
					__( 'The generated post (%d) could not be linked to this run, so the run was stopped rather than continuing without knowing what it had written.', 'autoscribe' ),
					$post_id
				)
			);
		}
		$run->record_article(
			$this->sanitizer->sanitize_title( $article->title() ),
			$this->sanitizer->sanitize_topic_key( $article->topic_key() )
		);

		return $post_id;
	}
}
