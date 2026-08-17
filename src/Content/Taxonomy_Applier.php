<?php
/**
 * Taxonomy assignment.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Content;

use AutoScribe\Prompts\Prompt;

defined( 'ABSPATH' ) || exit;

/**
 * Applies categories and tags to a generated post.
 *
 * Section 7.3 sets the rules. Categories always come from the prompt's own
 * configuration and the model is never allowed to invent one. Tags follow the
 * prompt's tag mode, and in ai mode the model's suggestions are matched against
 * existing terms before any new term is created, with a hard cap of three new
 * terms per post.
 *
 * That cap is the load-bearing part. Without it the tag list becomes unusable
 * within a month, because every run invents its own near-synonyms and nothing
 * ever reuses them.
 *
 * @since 0.5.0
 */
final class Taxonomy_Applier {

	/**
	 * Maximum new terms one post may create, per section 7.3.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	public const MAX_NEW_TERMS = 3;

	/**
	 * Applies categories and tags.
	 *
	 * @since 0.5.0
	 *
	 * @param int      $post_id        Post to annotate.
	 * @param Prompt   $prompt         Prompt being run.
	 * @param string[] $suggested_tags Tags proposed by the model.
	 * @return void
	 */
	public function apply( int $post_id, Prompt $prompt, array $suggested_tags ): void {
		$this->apply_categories( $post_id, $prompt );
		$this->apply_tags( $post_id, $prompt, $suggested_tags );
	}

	/**
	 * Assigns the prompt's configured categories.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $post_id Post to annotate.
	 * @param Prompt $prompt  Prompt being run.
	 * @return void
	 */
	private function apply_categories( int $post_id, Prompt $prompt ): void {
		if ( 'post' !== $prompt->post_type() || array() === $prompt->category_ids() ) {
			return;
		}

		wp_set_post_terms( $post_id, $prompt->category_ids(), 'category', false );
	}

	/**
	 * Assigns tags according to the prompt's tag mode.
	 *
	 * @since 0.5.0
	 *
	 * @param int      $post_id        Post to annotate.
	 * @param Prompt   $prompt         Prompt being run.
	 * @param string[] $suggested_tags Tags proposed by the model.
	 * @return void
	 */
	private function apply_tags( int $post_id, Prompt $prompt, array $suggested_tags ): void {
		$mode = $prompt->tag_mode();

		if ( 'none' === $mode ) {
			return;
		}

		if ( 'fixed' === $mode ) {
			$names = array_map( 'sanitize_text_field', $prompt->fixed_tags() );

			if ( array() !== $names ) {
				wp_set_post_terms( $post_id, $names, 'post_tag', false );
			}

			return;
		}

		wp_set_post_terms( $post_id, $this->resolve_ai_tags( $suggested_tags ), 'post_tag', false );
	}

	/**
	 * Maps model-suggested tags onto existing terms, creating few new ones.
	 *
	 * @since 0.5.0
	 *
	 * @param string[] $suggested_tags Tags proposed by the model.
	 * @return int[]|string[] Term IDs for matches, names for permitted new terms.
	 */
	private function resolve_ai_tags( array $suggested_tags ): array {
		$resolved  = array();
		$new_terms = 0;

		foreach ( $suggested_tags as $suggested ) {
			$name = sanitize_text_field( (string) $suggested );

			if ( '' === $name ) {
				continue;
			}

			$existing = $this->find_existing_term( $name );

			if ( null !== $existing ) {
				$resolved[] = $existing;
				continue;
			}

			if ( $new_terms >= self::MAX_NEW_TERMS ) {
				continue;
			}

			$resolved[] = $name;
			++$new_terms;
		}

		return $resolved;
	}

	/**
	 * Finds an existing tag matching a suggestion.
	 *
	 * Matches on the slug as well as the name, so "Cold Brew" reuses the
	 * existing cold-brew term instead of creating a near-duplicate.
	 *
	 * @since 0.5.0
	 *
	 * @param string $name Suggested tag name.
	 * @return int|null Term ID, or null when no match exists.
	 */
	private function find_existing_term( string $name ): ?int {
		$term = get_term_by( 'name', $name, 'post_tag' );

		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}

		$term = get_term_by( 'slug', sanitize_title( $name ), 'post_tag' );

		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}

		return null;
	}
}
