<?php
/**
 * Fallback SEO adapter.
 *
 * @package AutoScribe
 */

namespace AutoScribe\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Stores SEO metadata under the plugin's own keys when no SEO plugin is active.
 *
 * Section 7.3: fall back to this when none is present, and store the values
 * under _autoscribe_ keys so nothing is lost. That matters because the model
 * was already paid to produce them, and installing an SEO plugin later should
 * not mean regenerating every article to recover its metadata.
 *
 * @since 0.5.0
 */
final class Null_Adapter implements SEO_Adapter_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'No SEO plugin', 'autoscribe' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Always true: it is the fallback, so it is always usable.
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return true;
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
			'title'         => '_autoscribe_seo_title',
			'description'   => '_autoscribe_meta_description',
			'focus_keyword' => '_autoscribe_focus_keyword',
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
