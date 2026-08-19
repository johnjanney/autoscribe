<?php
/**
 * Duplicate topic detection.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Content;

use AutoScribe\Pipeline\Step_Assemble_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Detects whether a proposed topic has already been covered.
 *
 * Section 7.2 chooses a deterministic method over embeddings, correctly: no
 * embeddings endpoint exists across all four providers, so anything
 * vector-based would not be portable.
 *
 * Two corrections to the section as written.
 *
 * It says to query published posts. Section 10's review mode saves generated
 * posts as drafts, so on the configuration the brief itself recommends, every
 * generated topic would be invisible to this check and the same article would
 * be regenerated at full price on every run until a human published one. Drafts,
 * pending, and scheduled posts are therefore included.
 *
 * It also calls post_exists(). That function lives in wp-admin/includes/post.php,
 * which is not loaded during an Action Scheduler run, so the call would be a
 * fatal rather than a check. A direct title lookup is used instead, which also
 * lets the query be scoped to the right post type.
 *
 * @since 0.5.0
 */
final class Topic_Deduplicator {


	/**
	 * Default similarity percentage at which two topics are treated as the same.
	 *
	 * Section 7.2 proposes 82. similar_text() compares characters, not meaning,
	 * so on hyphenated slugs it scores unrelated articles low and neighbouring
	 * ones high. The real defence is the already-covered list injected into the
	 * proposal prompt; this threshold is a backstop, so it defaults slightly
	 * lower and is filterable, as section 7.2 asks.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	public const DEFAULT_THRESHOLD = 78;

	/**
	 * Post statuses that count as already covering a topic.
	 *
	 * @since 0.5.0
	 * @var string[]
	 */
	public const COUNTED_STATUSES = array( 'publish', 'draft', 'pending', 'future' );

	/**
	 * Returns the similarity threshold, filterable per section 7.2.
	 *
	 * @since 0.5.0
	 *
	 * @return int
	 */
	public function threshold(): int {
		/**
		 * Filters the topic similarity threshold.
		 *
		 * @since 0.5.0
		 *
		 * @param int $threshold Percentage above which two topics collide.
		 */
		return (int) apply_filters( 'autoscribe_topic_similarity_threshold', self::DEFAULT_THRESHOLD );
	}

	/**
	 * Returns recently covered topics.
	 *
	 * @since 0.5.0
	 *
	 * Reads the most recent posts and collects the topic keys among them, which
	 * is what section 7.2 describes. Filtering the query by meta key instead
	 * would be a slow meta query, and would also change the meaning of the
	 * lookback from "the last N posts" to "the last N generated posts".
	 *
	 * @param string $post_type       Post type generated posts are created as.
	 * @param int[]  $category_ids    Restrict to these categories, or empty for all.
	 * @param int    $lookback        Maximum number of posts to consider.
	 * @param int    $exclude_post_id Post to ignore, such as a draft this run will overwrite.
	 * @return array<string, string> Topic key mapped to post title.
	 */
	public function recent_topics( string $post_type, array $category_ids, int $lookback, int $exclude_post_id = 0 ): array {
		$args = array(
			'post_type'              => $post_type,
			'post_status'            => self::COUNTED_STATUSES,
			'posts_per_page'         => max( 1, min( 500, $lookback ) ),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);

		if ( array() !== $category_ids ) {
			$args['category__in'] = array_map( 'intval', $category_ids );
		}

		if ( $exclude_post_id > 0 ) {
			$args['post__not_in'] = array( $exclude_post_id );
		}

		$topics = array();

		foreach ( get_posts( $args ) as $post ) {
			$key = (string) get_post_meta( $post->ID, Step_Assemble_Post::TOPIC_KEY_META, true );

			if ( '' !== $key ) {
				$topics[ $key ] = (string) $post->post_title;
			}
		}

		return $topics;
	}

	/**
	 * Returns why a proposed topic collides, or null when it does not.
	 *
	 * @since 0.5.0
	 *
	 * @param string                $topic_key Proposed topic key.
	 * @param string                $title     Proposed title.
	 * @param array<string, string> $existing  Existing topics from recent_topics().
	 * @param string                $post_type Post type to check titles against.
	 * @param int                   $exclude   Post to ignore, such as a draft this run will overwrite.
	 * @return string|null Human-readable reason, or null when the topic is new.
	 */
	public function collision_reason( string $topic_key, string $title, array $existing, string $post_type = 'post', int $exclude = 0 ): ?string {
		if ( '' !== $topic_key && array_key_exists( $topic_key, $existing ) ) {
			return sprintf(
				/* translators: %s: the topic key that already exists. */
				__( 'The topic key %s has already been used.', 'autoscribe' ),
				$topic_key
			);
		}

		$threshold = $this->threshold();

		foreach ( $existing as $key => $existing_title ) {
			$percent = 0.0;
			similar_text( $topic_key, (string) $key, $percent );

			if ( $percent > $threshold ) {
				return sprintf(
					/* translators: 1: existing topic key, 2: similarity percentage, 3: existing post title. */
					__( 'The proposed topic is %2$d%% similar to the existing topic %1$s ("%3$s").', 'autoscribe' ),
					$key,
					(int) round( $percent ),
					$existing_title
				);
			}
		}

		if ( '' !== $title && $this->title_exists( $title, $post_type, $exclude ) ) {
			return sprintf(
				/* translators: %s: the duplicate title. */
				__( 'A post titled "%s" already exists.', 'autoscribe' ),
				$title
			);
		}

		return null;
	}

	/**
	 * Whether a post with this exact title already exists.
	 *
	 * Replaces post_exists(), which is unavailable outside the admin.
	 *
	 * @since 0.5.0
	 *
	 * @param string $title     Title to look for.
	 * @param string $post_type Post type to search.
	 * @param int    $exclude   Post to ignore, or 0 to consider every post.
	 * @return bool
	 */
	private function title_exists( string $title, string $post_type, int $exclude = 0 ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status != 'trash' AND ID <> %d LIMIT 1",
				$title,
				$post_type,
				$exclude
			)
		);

		return null !== $found;
	}
}
