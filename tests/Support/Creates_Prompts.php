<?php
/**
 * Prompt creation helper for tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Support;

use AutoScribe\Prompts\Prompt_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Creates configured prompt posts.
 *
 * @since 0.5.0
 */
trait Creates_Prompts {

	/**
	 * Creates a prompt with sensible defaults, overridable per test.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, mixed> $meta Meta values without the prefix.
	 * @return int Prompt post ID.
	 */
	protected function create_prompt( array $meta = array() ): int {
		$prompt_id = self::factory()->post->create(
			array(
				'post_type'   => Prompt_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test prompt',
			)
		);

		$defaults = array(
			'text_provider'     => 'anthropic',
			'text_model'        => 'claude-opus-5',
			'system_prompt'     => 'You are a careful writer.',
			'user_prompt'       => 'Write about espresso.',
			'target_word_count' => 800,
			'post_type'         => 'post',
			'post_status_mode'  => 'auto',
			'image_mode'        => 'none',
			'tag_mode'          => 'none',
			'enabled'           => 1,
		);

		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			update_post_meta( $prompt_id, '_autoscribe_' . $key, $value );
		}

		return (int) $prompt_id;
	}

	/**
	 * Creates a post that already covers a topic.
	 *
	 * @since 0.5.0
	 *
	 * @param string $title     Post title.
	 * @param string $topic_key Topic key to record.
	 * @param string $status    Post status.
	 * @return int Post ID.
	 */
	protected function create_covered_post( string $title, string $topic_key, string $status = 'publish' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => $status,
				'post_title'  => $title,
			)
		);

		update_post_meta( $post_id, '_autoscribe_topic_key', $topic_key );

		return (int) $post_id;
	}
}
