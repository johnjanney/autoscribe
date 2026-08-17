<?php
/**
 * SEO adapter tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\SEO;

use AutoScribe\SEO\Null_Adapter;
use AutoScribe\SEO\Rank_Math_Adapter;
use AutoScribe\SEO\SEO_Adapter_Factory;
use AutoScribe\SEO\SEO_Adapter_Interface;
use AutoScribe\SEO\SEOPress_Adapter;
use AutoScribe\SEO\Yoast_Adapter;
use WP_UnitTestCase;

/**
 * Covers the section 7.3 meta keys.
 *
 * The keys are asserted twice over: once as literal strings, so a typo or a
 * silent rename is caught, and once by writing through the adapter and reading
 * the value back out of post meta.
 *
 * The literal assertions matter because section 7.3 flags these as contested,
 * particularly whether Rank Math uses a leading underscore. It does not, and
 * that is pinned here so nobody "fixes" it later to match the other two.
 *
 * @since 0.5.0
 */
final class SEO_AdapterTest extends WP_UnitTestCase {

	/**
	 * Returns every adapter with its verified keys.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, array{0: SEO_Adapter_Interface, 1: array<string, string>}>
	 */
	public function adapters(): array {
		return array(
			'yoast'     => array(
				new Yoast_Adapter(),
				array(
					'title'         => '_yoast_wpseo_title',
					'description'   => '_yoast_wpseo_metadesc',
					'focus_keyword' => '_yoast_wpseo_focuskw',
				),
			),
			'rank_math' => array(
				new Rank_Math_Adapter(),
				array(
					'title'         => 'rank_math_title',
					'description'   => 'rank_math_description',
					'focus_keyword' => 'rank_math_focus_keyword',
				),
			),
			'seopress'  => array(
				new SEOPress_Adapter(),
				array(
					'title'         => '_seopress_titles_title',
					'description'   => '_seopress_titles_desc',
					'focus_keyword' => '_seopress_analysis_target_kw',
				),
			),
			'none'      => array(
				new Null_Adapter(),
				array(
					'title'         => '_autoscribe_seo_title',
					'description'   => '_autoscribe_meta_description',
					'focus_keyword' => '_autoscribe_focus_keyword',
				),
			),
		);
	}

	/**
	 * Each adapter declares exactly the keys verified in section 7.3.
	 *
	 * @since 0.5.0
	 *
	 * @param SEO_Adapter_Interface $adapter  Adapter under test.
	 * @param array<string, string> $expected Expected meta keys.
	 * @return void
	 *
	 * @dataProvider adapters
	 */
	public function test_adapter_declares_the_verified_keys( SEO_Adapter_Interface $adapter, array $expected ): void {
		$this->assertSame( $expected, $adapter->meta_keys(), $adapter->slug() );
	}

	/**
	 * Each adapter writes to the keys it declares.
	 *
	 * @since 0.5.0
	 *
	 * @param SEO_Adapter_Interface $adapter  Adapter under test.
	 * @param array<string, string> $expected Expected meta keys.
	 * @return void
	 *
	 * @dataProvider adapters
	 */
	public function test_adapter_writes_to_its_keys( SEO_Adapter_Interface $adapter, array $expected ): void {
		$post_id = self::factory()->post->create();

		$adapter->apply( $post_id, 'The SEO Title', 'The meta description.', 'focus keyword' );

		$this->assertSame( 'The SEO Title', get_post_meta( $post_id, $expected['title'], true ), $adapter->slug() );
		$this->assertSame( 'The meta description.', get_post_meta( $post_id, $expected['description'], true ), $adapter->slug() );
		$this->assertSame( 'focus keyword', get_post_meta( $post_id, $expected['focus_keyword'], true ), $adapter->slug() );
	}

	/**
	 * Rank Math's keys carry no leading underscore.
	 *
	 * Section 7.3 names this as the specific point where published sources
	 * disagree. The consequence is not cosmetic: without the underscore these
	 * are not protected meta, so they appear in the custom fields UI, unlike
	 * the Yoast and SEOPress keys.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_rank_math_keys_have_no_leading_underscore(): void {
		foreach ( ( new Rank_Math_Adapter() )->meta_keys() as $role => $key ) {
			$this->assertStringStartsNotWith( '_', $key, $role );
			$this->assertStringStartsWith( 'rank_math_', $key, $role );
		}
	}

	/**
	 * Yoast and SEOPress keys are protected meta.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_yoast_and_seopress_keys_are_protected(): void {
		foreach ( array( new Yoast_Adapter(), new SEOPress_Adapter() ) as $adapter ) {
			foreach ( $adapter->meta_keys() as $role => $key ) {
				$this->assertStringStartsWith( '_', $key, $adapter->slug() . ':' . $role );
				$this->assertTrue( is_protected_meta( $key, 'post' ), $adapter->slug() . ':' . $role );
			}
		}
	}

	/**
	 * With no SEO plugin active, detection falls back and loses nothing.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_detection_falls_back_to_the_null_adapter(): void {
		$adapter = ( new SEO_Adapter_Factory() )->detect();

		$this->assertInstanceOf( Null_Adapter::class, $adapter );
		$this->assertSame( 'none', $adapter->slug() );
	}

	/**
	 * The factory offers all four adapters in detection order.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_factory_lists_every_adapter(): void {
		$slugs = array_map(
			static fn( SEO_Adapter_Interface $adapter ): string => $adapter->slug(),
			( new SEO_Adapter_Factory() )->adapters()
		);

		$this->assertSame( array( 'yoast', 'rank_math', 'seopress', 'none' ), $slugs );
	}

	/**
	 * No two adapters share a meta key.
	 *
	 * A collision would mean one plugin silently reading another's value.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function test_adapters_do_not_share_keys(): void {
		$all = array();

		foreach ( ( new SEO_Adapter_Factory() )->adapters() as $adapter ) {
			$all = array_merge( $all, array_values( $adapter->meta_keys() ) );
		}

		$this->assertSame( count( $all ), count( array_unique( $all ) ) );
	}
}
