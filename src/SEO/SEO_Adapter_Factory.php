<?php
/**
 * SEO adapter detection.
 *
 * @package AutoScribe
 */

namespace AutoScribe\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the adapter matching the site's active SEO plugin.
 *
 * Section 7.3 asks for runtime detection with a fallback, but does not say
 * where the detecting happens, so it happens here.
 *
 * The order is fixed rather than arbitrary: if two SEO plugins are somehow both
 * active, writing to both would be worse than picking one, because the site
 * would then have two sources of truth disagreeing about the same page.
 *
 * @since 0.5.0
 */
final class SEO_Adapter_Factory {

	/**
	 * Returns every adapter, in detection order.
	 *
	 * @since 0.5.0
	 *
	 * @return SEO_Adapter_Interface[]
	 */
	public function adapters(): array {
		return array(
			new Yoast_Adapter(),
			new Rank_Math_Adapter(),
			new SEOPress_Adapter(),
			new Null_Adapter(),
		);
	}

	/**
	 * Returns the adapter for the active SEO plugin.
	 *
	 * @since 0.5.0
	 *
	 * @return SEO_Adapter_Interface
	 */
	public function detect(): SEO_Adapter_Interface {
		foreach ( $this->adapters() as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}

		return new Null_Adapter();
	}
}
