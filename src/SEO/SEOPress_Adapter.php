<?php
/**
 * SEOPress adapter.
 *
 * @package AutoScribe
 */

namespace AutoScribe\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Writes SEOPress metadata.
 *
 * Meta keys verified against SEOPress's own published list of the post meta it
 * generates. The target keyword key is not symmetrical with the title and
 * description keys: those live under _seopress_titles_, while the keyword lives
 * under _seopress_analysis_, and it holds a comma-separated list rather than a
 * single value.
 *
 * SEOPress stores these in post meta only, so writing the meta is the entire
 * integration.
 *
 * @since 0.5.0
 */
final class SEOPress_Adapter implements SEO_Adapter_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'seopress';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'SEOPress', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' );
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
			'title'         => '_seopress_titles_title',
			'description'   => '_seopress_titles_desc',
			'focus_keyword' => '_seopress_analysis_target_kw',
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
	 * @return bool
	 */
	public function apply( int $post_id, string $seo_title, string $meta_description, string $focus_keyword ): bool {
		$keys = $this->meta_keys();

		return Meta_Writer::write(
			$post_id,
			array(
				$keys['title']         => $seo_title,
				$keys['description']   => $meta_description,
				$keys['focus_keyword'] => $focus_keyword,
			)
		);
	}
}
