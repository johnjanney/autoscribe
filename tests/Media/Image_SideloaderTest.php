<?php
/**
 * Image sideloading tests.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Tests\Media;

use AutoScribe\Media\Image_Sideloader;
use AutoScribe\Providers\Response\Image_Result;
use WP_UnitTestCase;

/**
 * Covers the URL branch of section 6's "base64 data or a short-lived URL".
 *
 * The inline-data branch is exercised through the pipeline tests. The URL branch
 * was not covered at all, which is how 1.0.1 shipped a twenty-megabyte limit
 * applied only after download_url() had already written the whole response to
 * disk and the whole file had been read into memory. The check protected the
 * uploads directory and nothing else.
 *
 * @since 1.0.2
 */
final class Image_SideloaderTest extends WP_UnitTestCase {

	/**
	 * Request arguments captured from the last mocked download.
	 *
	 * @since 1.0.2
	 * @var array<string, mixed>
	 */
	private array $captured = array();

	/**
	 * The registered mock, so it can be removed cleanly.
	 *
	 * @since 1.0.2
	 * @var callable|null
	 */
	private $mock = null;

	/**
	 * Re-arms the bootstrap tripwire.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( null !== $this->mock ) {
			remove_filter( 'pre_http_request', $this->mock, 10 );

			$this->mock = null;
		}

		parent::tear_down();
	}

	/**
	 * The download asks the transport to stop at the size limit.
	 *
	 * This is the whole fix. Measuring afterwards cannot prevent a multi-gigabyte
	 * response from filling the disk and the request's memory first.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_the_download_is_bounded_before_it_starts(): void {
		$this->mock_download( $this->image_bytes() );

		$post_id = self::factory()->post->create();

		( new Image_Sideloader() )->sideload(
			new Image_Result( null, 'https://example.com/generated.jpg', 'image/jpeg', 'gpt-image-2' ),
			$post_id,
			'A picture.',
			'A picture'
		);

		$this->assertArrayHasKey( 'limit_response_size', $this->captured );
		$this->assertSame( Image_Sideloader::MAX_IMAGE_BYTES + 1, $this->captured['limit_response_size'] );
		$this->assertSame( Image_Sideloader::DOWNLOAD_TIMEOUT, $this->captured['timeout'] );
	}

	/**
	 * A response over the limit is rejected rather than attached.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_an_oversized_download_is_rejected(): void {
		$this->mock_download( str_repeat( 'x', Image_Sideloader::MAX_IMAGE_BYTES + 1 ) );

		$post_id = self::factory()->post->create();

		$result = ( new Image_Sideloader() )->sideload(
			new Image_Result( null, 'https://example.com/huge.jpg', 'image/jpeg', 'gpt-image-2' ),
			$post_id,
			'A picture.',
			'A picture'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_image_too_large', $result->get_error_code() );
	}

	/**
	 * A URL that answers with an error status fails the sideload.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_a_failed_download_is_reported(): void {
		$this->mock_download( 'Not found', 404 );

		$post_id = self::factory()->post->create();

		$result = ( new Image_Sideloader() )->sideload(
			new Image_Result( null, 'https://example.com/missing.jpg', 'image/jpeg', 'gpt-image-2' ),
			$post_id,
			'A picture.',
			'A picture'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'autoscribe_image_empty', $result->get_error_code() );
	}

	/**
	 * A valid URL image becomes the post's featured image.
	 *
	 * @since 1.0.2
	 *
	 * @return void
	 */
	public function test_a_url_image_is_attached(): void {
		$this->mock_download( $this->image_bytes() );

		$post_id = self::factory()->post->create();

		$attachment_id = ( new Image_Sideloader() )->sideload(
			new Image_Result( null, 'https://example.com/generated.jpg', 'image/jpeg', 'gpt-image-2' ),
			$post_id,
			'A picture.',
			'A picture'
		);

		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame( 'A picture.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Answers the download with the given body and status.
	 *
	 * @since 1.0.2
	 *
	 * @param string $body   Response body.
	 * @param int    $status HTTP status code.
	 * @return void
	 */
	private function mock_download( string $body, int $status = 200 ): void {
		$this->mock = function ( $preempt, $args, $url ) use ( $body, $status ) {
			unset( $preempt, $url );

			$this->captured = $args;

			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => $status,
					'message' => 200 === $status ? 'OK' : 'Error',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $this->mock, 10, 3 );
	}

	/**
	 * Returns the bytes of a real image from the WordPress test data.
	 *
	 * The sideloader verifies the file with getimagesize(), so the test needs an
	 * image the decoder will actually accept rather than arbitrary bytes.
	 *
	 * @since 1.0.2
	 *
	 * @return string
	 */
	private function image_bytes(): string {
		return (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
