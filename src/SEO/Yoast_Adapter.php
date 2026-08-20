<?php
/**
 * Yoast SEO adapter.
 *
 * @package AutoScribe
 */

namespace AutoScribe\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Writes Yoast SEO metadata.
 *
 * Meta keys verified against Yoast's developer documentation: the underscore
 * prefix is real, which also makes these protected meta, hidden from the custom
 * fields UI and not writable over REST without an auth callback.
 *
 * Yoast is the one adapter of the three with a second storage location. It
 * maintains a wp_yoast_indexable table and reads that when rendering, so a
 * value written only to post meta after the post has already been saved leaves
 * the indexable stale: get_post_meta() returns the new description while the
 * page source still shows Yoast's generated fallback.
 *
 * That is why section 7.3's suggested verification — write a value, then read
 * wp_postmeta directly — cannot detect the bug. It inspects the table that is
 * not consulted, and passes on a broken adapter. The real check is viewing page
 * source.
 *
 * This is handled by ordering rather than by touching Yoast's internals. The
 * pipeline creates the post as a draft, writes SEO meta while it is still a
 * draft, and only then transitions it to its final status. That transition is a
 * post save, which is exactly when Yoast rebuilds the indexable, so it is built
 * with the metadata already present. Reaching into Yoast's repository classes
 * would work today and break on their next refactor.
 *
 * The residual gap is a review-mode post that is never opened in the editor: its
 * indexable was built at insert time, before the meta existed. Opening and
 * saving it, which is what review mode is for, rebuilds it.
 *
 * @since 0.5.0
 */
final class Yoast_Adapter implements SEO_Adapter_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Yoast SEO', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
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
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
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
