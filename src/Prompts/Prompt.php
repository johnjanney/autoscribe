<?php
/**
 * Typed accessor over a prompt post.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Prompts;

use AutoScribe\Admin\Settings;
use AutoScribe\Scheduling\Schedule;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads prompt configuration from post meta.
 *
 * Section 3.2 stores prompts as a custom post type with meta prefixed
 * _autoscribe_. Routing every read through this object keeps the prefix and the
 * defaults in one place instead of scattering get_post_meta() calls across the
 * pipeline.
 *
 * @since 0.3.0
 */
final class Prompt {

	/**
	 * Meta key prefix, per section 3.2.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	private const PREFIX = '_autoscribe_';

	/**
	 * The underlying post.
	 *
	 * @since 0.3.0
	 * @var WP_Post
	 */
	private WP_Post $post;

	/**
	 * Wraps a post.
	 *
	 * @since 0.3.0
	 *
	 * @param WP_Post $post Prompt post.
	 */
	private function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Loads a prompt by post ID.
	 *
	 * @since 0.3.0
	 *
	 * @param int $post_id Prompt post ID.
	 * @return Prompt|null Prompt, or null when the ID is not a prompt.
	 */
	public static function load( int $post_id ): ?Prompt {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Prompt_Post_Type::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return new self( $post );
	}

	/**
	 * Returns the prompt post ID.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function id(): int {
		return (int) $this->post->ID;
	}

	/**
	 * Returns the prompt title.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function name(): string {
		return (string) $this->post->post_title;
	}

	/**
	 * Returns the configured text provider slug.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function text_provider(): string {
		return $this->string( 'text_provider', 'anthropic' );
	}

	/**
	 * Returns the configured text model identifier.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function text_model(): string {
		return $this->string( 'text_model' );
	}

	/**
	 * Returns the system prompt.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function system_prompt(): string {
		return $this->string( 'system_prompt' );
	}

	/**
	 * Returns the user prompt.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function user_prompt(): string {
		return $this->string( 'user_prompt' );
	}

	/**
	 * Returns the target word count.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function target_word_count(): int {
		$value = (int) $this->raw( 'target_word_count' );

		return $value > 0 ? $value : 800;
	}

	/**
	 * Returns the post status mode, either review or auto.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function post_status_mode(): string {
		return 'auto' === $this->string( 'post_status_mode', 'review' ) ? 'auto' : 'review';
	}

	/**
	 * Returns the post type generated posts are created as.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function post_type(): string {
		$type = $this->string( 'post_type', 'post' );

		return in_array( $type, array( 'post', 'page' ), true ) ? $type : 'post';
	}

	/**
	 * Returns the category IDs applied to generated posts.
	 *
	 * @since 0.3.0
	 *
	 * @return int[]
	 */
	public function category_ids(): array {
		$raw = $this->raw( 'category_ids' );

		return is_array( $raw ) ? array_map( 'intval', $raw ) : array();
	}

	/**
	 * Returns the author assigned to generated posts.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function author_id(): int {
		return (int) $this->raw( 'author_id' );
	}

	/**
	 * Returns the image mode, per section 6.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function image_mode(): string {
		$mode = $this->string( 'image_mode', 'optional' );

		return in_array( $mode, array( 'required', 'fallback', 'optional', 'none' ), true ) ? $mode : 'optional';
	}

	/**
	 * Returns the configured image provider slug.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function image_provider(): string {
		return $this->string( 'image_provider', 'none' );
	}

	/**
	 * Returns the configured image model identifier.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function image_model(): string {
		return $this->string( 'image_model' );
	}

	/**
	 * Returns the house-style suffix appended to every image prompt.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function image_style_suffix(): string {
		return $this->string( 'image_style_suffix' );
	}

	/**
	 * Returns the fallback attachment ID used when generation fails.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function fallback_image_id(): int {
		return (int) $this->raw( 'fallback_image_id' );
	}

	/**
	 * Whether web grounding is requested for this prompt.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function grounding_enabled(): bool {
		return (bool) $this->raw( 'grounding_enabled' );
	}

	/**
	 * Whether a Sources list is appended to grounded articles.
	 *
	 * Section 7.1 makes this optional, so it is a per-prompt choice rather than
	 * something the pipeline decides.
	 *
	 * @since 0.8.0
	 *
	 * @return bool
	 */
	public function append_sources(): bool {
		return (bool) $this->raw( 'append_sources' );
	}

	/**
	 * Returns the per-prompt monthly cap in cents, or 0 for no cap.
	 *
	 * @since 0.5.0
	 *
	 * @return int
	 */
	public function monthly_budget_cents(): int {
		return max( 0, (int) $this->raw( 'monthly_budget_cents' ) );
	}

	/**
	 * Returns how many past posts duplicate detection compares against.
	 *
	 * @since 0.5.0
	 *
	 * @return int
	 */
	public function dedupe_lookback(): int {
		$value = (int) $this->raw( 'dedupe_lookback' );

		return $value > 0 ? $value : 50;
	}

	/**
	 * Returns the tag mode: fixed, ai, or none.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function tag_mode(): string {
		$mode = $this->string( 'tag_mode', 'none' );

		return in_array( $mode, array( 'fixed', 'ai', 'none' ), true ) ? $mode : 'none';
	}

	/**
	 * Returns the fixed tag list.
	 *
	 * @since 0.5.0
	 *
	 * @return string[]
	 */
	public function fixed_tags(): array {
		$raw = $this->raw( 'fixed_tags' );

		return is_array( $raw ) ? array_map( 'strval', $raw ) : array();
	}

	/**
	 * Returns the configured schedule type.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function schedule_type(): string {
		return $this->string( 'schedule_type', Schedule::TYPE_DAILY );
	}

	/**
	 * Returns the raw schedule parameters.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string, mixed>
	 */
	public function schedule_params(): array {
		$raw = $this->raw( 'schedule_params' );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Returns the validated schedule.
	 *
	 * @since 0.4.0
	 *
	 * @return Schedule|WP_Error
	 */
	public function schedule(): Schedule|WP_Error {
		return Schedule::create( $this->schedule_type(), $this->schedule_params() );
	}

	/**
	 * Returns a fingerprint of the settings a run depends on.
	 *
	 * A run used to read its configuration once, because it happened entirely
	 * inside one request. Spread across queued actions it reads the prompt again
	 * at every step, so an edit landing part-way through is applied to the
	 * remaining steps — settings that were never budget-checked, and, if the
	 * publication mode changed, a run that began under review finishing by
	 * publishing. Recording this at the start lets a later step notice.
	 *
	 * Built from the editor's own field list rather than from every meta key on
	 * the post, which keeps it to the settings a person can actually change and
	 * leaves out the ones the plugin writes to the prompt while it runs — the
	 * cached next-run timestamp and the attempt counter would otherwise make
	 * every run look edited.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function config_fingerprint(): string {
		$values = array();

		foreach ( array_keys( Prompt_Fields::all() ) as $field ) {
			$values[ $field ] = $this->raw( $field );
		}

		/*
		 * Site settings count too, and leaving them out made the guard narrower
		 * than it reads. A prompt with a blank model field resolves through the
		 * site default at every step, so changing that default mid-run swaps the
		 * model the budget was checked for. The default model of each provider
		 * this prompt uses is therefore part of what the run was checked against.
		 *
		 * Force review is here for a different reason: not because a run should
		 * fail when it changes, but so it cannot be *relaxed* under an open run
		 * without anyone noticing. Tightening it is handled separately — see
		 * Generator::final_status(), where an open run keeps the stricter of the
		 * two settings.
		 */
		$values['@default_text_model']  = Settings::default_model( $this->text_provider() );
		$values['@default_image_model'] = Settings::default_model( $this->image_provider() );
		$values['@force_review']        = Settings::force_review() ? '1' : '0';

		ksort( $values );

		return hash( 'sha256', (string) wp_json_encode( $values ) );
	}

	/**
	 * Returns the cached next-run timestamp.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function next_run_ts(): int {
		return (int) $this->raw( 'next_run_ts' );
	}

	/**
	 * Caches the next-run timestamp for display.
	 *
	 * @since 0.4.0
	 *
	 * @param int $timestamp UTC Unix timestamp.
	 * @return void
	 */
	public function set_next_run_ts( int $timestamp ): void {
		update_post_meta( $this->id(), self::PREFIX . 'next_run_ts', $timestamp );
	}

	/**
	 * Whether the prompt is enabled.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) $this->raw( 'enabled' );
	}

	/**
	 * Reads a meta value.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key Meta key without the prefix.
	 * @return mixed
	 */
	private function raw( string $key ) {
		return get_post_meta( $this->id(), self::PREFIX . $key, true );
	}

	/**
	 * Reads a meta value as a string, falling back when empty.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key      Meta key without the prefix.
	 * @param string $fallback Value to use when the meta is empty.
	 * @return string
	 */
	private function string( string $key, string $fallback = '' ): string {
		$value = $this->raw( $key );

		if ( ! is_string( $value ) || '' === $value ) {
			return $fallback;
		}

		return $value;
	}
}
