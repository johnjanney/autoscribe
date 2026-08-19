<?php
/**
 * Validated article payload.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Content;

defined( 'ABSPATH' ) || exit;

/**
 * One article as returned by the structured output contract in section 5.1.
 *
 * Construction is only reachable through Article_Validator, so an instance of
 * this class is a statement that the payload decoded and satisfied the schema.
 *
 * @since 0.3.0
 */
final class Article {

	/**
	 * Decoded payload fields.
	 *
	 * @since 0.3.0
	 * @var array<string, mixed>
	 */
	private array $fields;

	/**
	 * Builds an article from already-validated fields.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $fields Validated payload.
	 */
	public function __construct( array $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Returns the article title.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function title(): string {
		return (string) ( $this->fields['title'] ?? '' );
	}

	/**
	 * Returns the deduplication topic key.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function topic_key(): string {
		return (string) ( $this->fields['topic_key'] ?? '' );
	}

	/**
	 * Returns the excerpt.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function excerpt(): string {
		return (string) ( $this->fields['excerpt'] ?? '' );
	}

	/**
	 * Returns the raw, unsanitised body HTML.
	 *
	 * Never pass this to the database directly. Section 5.2 requires it to go
	 * through Content_Sanitizer first.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function raw_content_html(): string {
		return (string) ( $this->fields['content_html'] ?? '' );
	}

	/**
	 * Returns the SEO title.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function seo_title(): string {
		return (string) ( $this->fields['seo_title'] ?? '' );
	}

	/**
	 * Returns the meta description.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function meta_description(): string {
		return (string) ( $this->fields['meta_description'] ?? '' );
	}

	/**
	 * Returns the focus keyword.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function focus_keyword(): string {
		return (string) ( $this->fields['focus_keyword'] ?? '' );
	}

	/**
	 * Returns the suggested tags.
	 *
	 * @since 0.3.0
	 *
	 * @return string[]
	 */
	public function suggested_tags(): array {
		$tags = $this->fields['suggested_tags'] ?? array();

		return is_array( $tags ) ? array_map( 'strval', $tags ) : array();
	}

	/**
	 * Returns the featured image prompt.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function image_prompt(): string {
		return (string) ( $this->fields['image_prompt'] ?? '' );
	}

	/**
	 * Returns the featured image alt text.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function image_alt(): string {
		return (string) ( $this->fields['image_alt'] ?? '' );
	}

	/**
	 * Returns the validated fields, ready to be stored.
	 *
	 * The counterpart is Article_Validator::from_array(), which is where the
	 * rebuilding lives rather than here: an Article is a statement that the
	 * schema was satisfied, and only the validator can make that statement. A
	 * from_array() on this class would be able to mint one that had never been
	 * checked, which is the whole invariant gone.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->fields;
	}
}
