<?php
/**
 * Contract every SEO plugin adapter implements.
 *
 * @package AutoScribe
 */

namespace AutoScribe\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * One SEO plugin integration.
 *
 * Section 7.3 warns that published sources disagree about these meta keys and
 * that they must be verified. They were, against each plugin's own
 * documentation, and the results are recorded on each adapter.
 *
 * The adapters are not symmetric. Rank Math and SEOPress read the values back
 * out of post meta, so writing the meta is the whole integration. Yoast also
 * maintains a separate indexables table and reads that at render time, so
 * writing its meta is necessary but not sufficient. Yoast_Adapter documents how
 * that is handled.
 *
 * @since 0.5.0
 */
interface SEO_Adapter_Interface {

	/**
	 * Returns the stable slug for this adapter.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Returns the human-readable plugin name.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the target plugin is active on this site.
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * Returns the meta keys this adapter writes.
	 *
	 * Keyed by role: title, description, focus_keyword.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, string>
	 */
	public function meta_keys(): array;

	/**
	 * Writes the SEO metadata for a post.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $post_id          Post to annotate.
	 * @param string $seo_title        SEO title, already sanitised and truncated.
	 * @param string $meta_description Meta description, already sanitised and truncated.
	 * @param string $focus_keyword    Focus keyword, already sanitised.
	 * @return void
	 */
	public function apply( int $post_id, string $seo_title, string $meta_description, string $focus_keyword ): void;
}
