<?php
/**
 * Rank Math adapter.
 *
 * @package AutoScribe
 */

namespace AutoScribe\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Writes Rank Math metadata.
 *
 * Section 7.3 specifically flags the leading-underscore question as one where
 * published sources disagree. Verified against Rank Math's own documentation:
 * there is no leading underscore. The keys are rank_math_title,
 * rank_math_description, and rank_math_focus_keyword.
 *
 * That is not cosmetic. Without the underscore these are not protected meta, so
 * unlike the Yoast and SEOPress keys they appear in the custom fields UI.
 *
 * Rank Math stores these in post meta only, with no second cache to keep in
 * sync, so writing the meta is the entire integration.
 *
 * @since 0.5.0
 */
final class Rank_Math_Adapter implements SEO_Adapter_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'rank_math';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Rank Math', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, string>
	 */
	public function meta_keys(): array {
		return array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @param int    $post_id          Post to annotate.
	 * @param string $seo_title        SEO title.
	 * @param string $meta_description Meta description.
	 * @param string $focus_keyword    Focus keyword.
	 * @return void
	 */
	public function apply( int $post_id, string $seo_title, string $meta_description, string $focus_keyword ): void {
		$keys = $this->meta_keys();

		update_post_meta( $post_id, $keys['title'], $seo_title );
		update_post_meta( $post_id, $keys['description'], $meta_description );
		update_post_meta( $post_id, $keys['focus_keyword'], $focus_keyword );
	}
}
