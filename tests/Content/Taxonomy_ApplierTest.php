<?php
/**
 * Taxonomy assignment tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Content;

use AutoScribe\Content\Taxonomy_Applier;
use AutoScribe\Prompts\Prompt;
use AutoScribe\Tests\Support\Creates_Prompts;
use WP_UnitTestCase;

/**
 * Covers section 7.3's taxonomy rules.
 *
 * @since 0.8.0
 */
final class Taxonomy_ApplierTest extends WP_UnitTestCase {

	use Creates_Prompts;

	/**
	 * Creates a plain post to annotate.
	 *
	 * @since 0.8.0
	 *
	 * @return int
	 */
	private function target_post(): int {
		return (int) self::factory()->post->create( array( 'post_type' => 'post' ) );
	}

	/**
	 * Applies taxonomy for a prompt configured with the given meta.
	 *
	 * @since 0.8.0
	 *
	 * @param int                  $post_id   Post to annotate.
	 * @param array<string, mixed> $meta      Prompt meta overrides.
	 * @param string[]             $suggested Model-suggested tags.
	 * @return void
	 */
	private function apply( int $post_id, array $meta, array $suggested = array() ): void {
		$prompt = Prompt::load( $this->create_prompt( $meta ) );

		$this->assertNotNull( $prompt );

		( new Taxonomy_Applier() )->apply( $post_id, $prompt, $suggested );
	}

	/**
	 * Returns the tag names on a post.
	 *
	 * @since 0.8.0
	 *
	 * @param int $post_id Post to read.
	 * @return string[]
	 */
	private function tag_names( int $post_id ): array {
		$names = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );

		sort( $names );

		return $names;
	}

	/**
	 * Tag mode "none" applies nothing, even when the model suggests tags.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_tag_mode_none_applies_nothing(): void {
		$post_id = $this->target_post();

		$this->apply( $post_id, array( 'tag_mode' => 'none' ), array( 'espresso', 'water' ) );

		$this->assertSame( array(), $this->tag_names( $post_id ) );
	}

	/**
	 * Tag mode "fixed" applies the configured list and ignores suggestions.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_tag_mode_fixed_ignores_the_model(): void {
		$post_id = $this->target_post();

		$this->apply(
			$post_id,
			array(
				'tag_mode'   => 'fixed',
				'fixed_tags' => array( 'coffee', 'brewing' ),
			),
			array( 'something-the-model-invented' )
		);

		$this->assertSame( array( 'brewing', 'coffee' ), $this->tag_names( $post_id ) );
	}

	/**
	 * Tag mode "ai" applies the model's suggestions.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_tag_mode_ai_applies_suggestions(): void {
		$post_id = $this->target_post();

		$this->apply( $post_id, array( 'tag_mode' => 'ai' ), array( 'espresso', 'water' ) );

		$this->assertSame( array( 'espresso', 'water' ), $this->tag_names( $post_id ) );
	}

	/**
	 * An existing term is reused rather than duplicated.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_an_existing_term_is_matched_by_name(): void {
		$existing = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Cold Brew',
			)
		);

		$post_id = $this->target_post();

		$this->apply( $post_id, array( 'tag_mode' => 'ai' ), array( 'Cold Brew' ) );

		$ids = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );

		$this->assertSame( array( (int) $existing ), array_map( 'intval', $ids ) );
	}

	/**
	 * A suggestion matching an existing slug reuses that term.
	 *
	 * The model returns prose, not slugs, so "cold brew" and "Cold Brew" have
	 * to land on the same term. Without the slug fallback the tag list fills up
	 * with case variants of the same idea.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_an_existing_term_is_matched_by_slug(): void {
		$existing = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Cold Brew',
				'slug'     => 'cold-brew',
			)
		);

		$post_id = $this->target_post();

		$this->apply( $post_id, array( 'tag_mode' => 'ai' ), array( 'cold brew' ) );

		$ids = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );

		$this->assertSame( array( (int) $existing ), array_map( 'intval', $ids ) );
		$this->assertCount(
			1,
			get_terms(
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => false,
				)
			)
		);
	}

	/**
	 * No more than three new terms are created for one post.
	 *
	 * Section 7.3 caps this. Without the cap the tag list becomes unusable
	 * within a month, because every run invents near-synonyms nothing reuses.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_no_more_than_three_new_terms_are_created(): void {
		$post_id = $this->target_post();

		$this->apply(
			$post_id,
			array( 'tag_mode' => 'ai' ),
			array( 'one', 'two', 'three', 'four', 'five' )
		);

		$this->assertSame( Taxonomy_Applier::MAX_NEW_TERMS, count( $this->tag_names( $post_id ) ) );
		$this->assertSame( array( 'one', 'three', 'two' ), $this->tag_names( $post_id ) );
	}

	/**
	 * Existing terms do not count against the new-term cap.
	 *
	 * The cap exists to limit vocabulary growth, not to limit tagging. A post
	 * that reuses five existing tags creates no new vocabulary and should not
	 * be truncated.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_existing_terms_do_not_count_against_the_cap(): void {
		foreach ( array( 'alpha', 'beta', 'gamma', 'delta' ) as $name ) {
			self::factory()->term->create(
				array(
					'taxonomy' => 'post_tag',
					'name'     => $name,
				)
			);
		}

		$post_id = $this->target_post();

		$this->apply(
			$post_id,
			array( 'tag_mode' => 'ai' ),
			array( 'alpha', 'beta', 'gamma', 'delta', 'one', 'two', 'three' )
		);

		// Four reused plus the three new ones the cap allows.
		$this->assertCount( 7, $this->tag_names( $post_id ) );
	}

	/**
	 * Empty suggestions are skipped rather than creating blank terms.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_empty_suggestions_are_skipped(): void {
		$post_id = $this->target_post();

		$this->apply( $post_id, array( 'tag_mode' => 'ai' ), array( '', '   ', 'real' ) );

		$this->assertSame( array( 'real' ), $this->tag_names( $post_id ) );
	}

	/**
	 * Categories come from the prompt and never from the model.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_categories_come_from_the_prompt(): void {
		$wanted = (int) self::factory()->category->create();

		$post_id = $this->target_post();

		$this->apply( $post_id, array( 'category_ids' => array( $wanted ) ), array( 'ignored' ) );

		$ids = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );

		$this->assertSame( array( $wanted ), array_map( 'intval', $ids ) );
	}

	/**
	 * A page target gets no categories, since pages are not categorised.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function test_a_page_target_gets_no_categories(): void {
		$category_id = (int) self::factory()->category->create();
		$post_id     = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->apply(
			$post_id,
			array(
				'post_type'    => 'page',
				'category_ids' => array( $category_id ),
			)
		);

		$this->assertSame( array(), wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) ) );
	}
}
