<?php
/**
 * Featured image sideloading.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Media;

use AutoScribe\Providers\Http;
use AutoScribe\Providers\Response\Image_Result;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a generated image into a WordPress attachment.
 *
 * Section 6 lists wp_insert_attachment, wp_generate_attachment_metadata,
 * wp_update_attachment_metadata, and set_post_thumbnail, but omits the step
 * that puts the file on disk in the first place. All of those functions assume
 * a file already exists inside the uploads directory, so wp_upload_bits() runs
 * first, over inline data or over a bounded fetch of the short-lived URL some
 * providers return instead.
 *
 * @since 0.3.0
 */
final class Image_Sideloader {

	/**
	 * Meta flag marking an attachment as machine-generated, per section 6.
	 *
	 * @since 0.3.0
	 * @var string
	 */
	public const GENERATED_META = '_autoscribe_generated';

	/**
	 * Largest generated image the plugin will accept, in bytes.
	 *
	 * The bytes are held in memory and then written to the uploads directory, so
	 * an unbounded response is both a memory and a disk problem. Twenty megabytes
	 * is generous for a featured image from any of the providers. For a URL
	 * result the limit is passed to the HTTP layer, so the transfer stops at the
	 * ceiling rather than being measured after it has already arrived.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	public const MAX_IMAGE_BYTES = 20971520;

	/**
	 * Largest generated image the plugin will process, in total pixels.
	 *
	 * Byte size alone does not bound the cost of generating thumbnails: a highly
	 * compressible image can be small on disk and enormous once decoded, which is
	 * the decompression bomb that turns one attachment into an out-of-memory
	 * fatal. 50 megapixels is roughly four times a 4K frame.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	public const MAX_IMAGE_PIXELS = 50000000;

	/**
	 * Timeout for fetching an image from a provider URL, in seconds.
	 *
	 * WordPress otherwise defaults an HTTP request to 5 seconds and download_url()
	 * to 300, and neither is right here: the first is too short for a large image
	 * over a slow link, and the second is longer than the whole generation budget
	 * and long enough for a stalled connection to hold a queue worker open on its
	 * own.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	public const DOWNLOAD_TIMEOUT = 60;

	/**
	 * Sideloads an image and attaches it to a post.
	 *
	 * @since 0.3.0
	 *
	 * @param Image_Result $image    Provider result.
	 * @param int          $post_id  Post to attach to.
	 * @param string       $alt_text Alt text for the attachment.
	 * @param string       $title    Human-readable attachment title.
	 * @return int|WP_Error Attachment ID, or an error.
	 */
	public function sideload( Image_Result $image, int $post_id, string $alt_text, string $title ): int|WP_Error {
		$this->require_media_functions();

		$bytes = $this->resolve_bytes( $image );

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$filename = $this->filename( $image, $title );
		$upload   = wp_upload_bits( $filename, null, $bytes );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'autoscribe_upload_failed', (string) $upload['error'] );
		}

		/*
		 * The file is on disk before it can be inspected, because the checks that
		 * matter — what it actually is, and how large it decodes to — read the
		 * file rather than the buffer. Anything that fails from here on removes
		 * the file again, so a rejected or half-finished sideload does not leave
		 * an orphan in the uploads directory.
		 */
		$verified = $this->verify_file( (string) $upload['file'], $image->mime_type() );

		if ( is_wp_error( $verified ) ) {
			wp_delete_file( (string) $upload['file'] );

			return $verified;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $verified,
				'post_title'     => $title,
				'post_content'   => '',
				'post_excerpt'   => $alt_text,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( (string) $upload['file'] );

			return $attachment_id;
		}

		$attachment_id = (int) $attachment_id;

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
		);

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		update_post_meta( $attachment_id, self::GENERATED_META, 1 );

		return $attachment_id;
	}

	/**
	 * Loads the admin-only media functions.
	 *
	 * Section 6 flags this: in a cron, WP-CLI, or Action Scheduler context these
	 * files are not loaded and the calls fatal.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	private function require_media_functions(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Returns the raw image bytes, downloading them when necessary.
	 *
	 * Section 6 requires both provider shapes to work: inline base64 data, and a
	 * short-lived URL that has to be fetched before it expires.
	 *
	 * @since 0.3.0
	 *
	 * @param Image_Result $image Provider result.
	 * @return string|WP_Error
	 */
	private function resolve_bytes( Image_Result $image ): string|WP_Error {
		$bytes = $image->bytes();

		if ( is_string( $bytes ) && '' !== $bytes ) {
			return $this->within_byte_limit( $bytes );
		}

		$url = $image->url();

		if ( ! is_string( $url ) || '' === $url ) {
			return new WP_Error(
				'autoscribe_image_empty',
				__( 'The image provider returned neither image data nor a URL.', 'autoscribe' )
			);
		}

		/*
		 * Not download_url(). That function streams the whole response to a
		 * temporary file with no caller-supplied ceiling, so a provider — or
		 * anything that has managed to put a URL in front of this code — could
		 * fill the disk and then the request's memory, and the size check would
		 * only run afterwards, once the damage was done. Version 1.0.1 checked
		 * the twenty-megabyte limit in exactly that position.
		 *
		 * limit_response_size stops the transfer at the limit instead, so the
		 * ceiling applies to bandwidth, disk, and memory rather than only to what
		 * reaches the uploads directory. One byte over the limit is requested so
		 * that a file exactly at the limit still passes and anything larger is
		 * visibly truncated rather than silently accepted.
		 *
		 * wp_safe_remote_get() rather than wp_remote_get(), because the URL comes
		 * from a provider response and should not be able to reach the site's own
		 * private network.
		 */
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::DOWNLOAD_TIMEOUT,
				'limit_response_size' => self::MAX_IMAGE_BYTES + 1,
				'user-agent'          => Http::user_agent(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status > 299 ) {
			return new WP_Error(
				'autoscribe_image_empty',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The image URL returned HTTP status %d.', 'autoscribe' ),
					$status
				)
			);
		}

		$contents = (string) wp_remote_retrieve_body( $response );

		if ( '' === $contents ) {
			return new WP_Error(
				'autoscribe_image_empty',
				__( 'The downloaded image was empty.', 'autoscribe' )
			);
		}

		return $this->within_byte_limit( $contents );
	}

	/**
	 * Rejects image data larger than the plugin will handle.
	 *
	 * @since 1.0.1
	 *
	 * @param string $bytes Raw image data.
	 * @return string|WP_Error The data unchanged, or an error.
	 */
	private function within_byte_limit( string $bytes ): string|WP_Error {
		if ( strlen( $bytes ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error(
				'autoscribe_image_too_large',
				sprintf(
					/* translators: %d: size limit in megabytes. */
					__( 'The generated image is larger than the %d MB the plugin will accept.', 'autoscribe' ),
					(int) ( self::MAX_IMAGE_BYTES / MB_IN_BYTES )
				)
			);
		}

		return $bytes;
	}

	/**
	 * Confirms an uploaded file really is an image the plugin can process.
	 *
	 * The provider's declared MIME type is a claim about the bytes, not a fact
	 * about them, and it was previously stored on the attachment without ever
	 * being checked. Reading the type from the file itself and bounding the pixel
	 * count keeps a mislabelled or deliberately hostile response from reaching
	 * wp_generate_attachment_metadata(), which is where the expensive decoding
	 * happens.
	 *
	 * @since 1.0.1
	 *
	 * @param string $path     Absolute path to the uploaded file.
	 * @param string $declared MIME type the provider claimed.
	 * @return string|WP_Error The verified MIME type, or an error.
	 */
	private function verify_file( string $path, string $declared ): string|WP_Error {
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Returns false for a non-image, which is the answer being tested for.

		if ( false === $size || empty( $size['mime'] ) ) {
			return new WP_Error(
				'autoscribe_image_invalid',
				__( 'The image provider returned data that is not a readable image.', 'autoscribe' )
			);
		}

		if ( ( (int) $size[0] * (int) $size[1] ) > self::MAX_IMAGE_PIXELS ) {
			return new WP_Error(
				'autoscribe_image_too_large',
				sprintf(
					/* translators: 1: image width, 2: image height. */
					__( 'The generated image is %1$d by %2$d pixels, which is beyond what the plugin will process.', 'autoscribe' ),
					(int) $size[0],
					(int) $size[1]
				)
			);
		}

		$checked = wp_check_filetype_and_ext( $path, basename( $path ) );

		if ( empty( $checked['type'] ) ) {
			return new WP_Error(
				'autoscribe_image_invalid',
				sprintf(
					/* translators: %s: MIME type the provider declared. */
					__( 'The generated file is not a type this site permits, despite being declared as %s.', 'autoscribe' ),
					$declared
				)
			);
		}

		return (string) $checked['type'];
	}

	/**
	 * Builds a filename with an extension matching the MIME type.
	 *
	 * @since 0.3.0
	 *
	 * @param Image_Result $image Provider result.
	 * @param string       $title Attachment title.
	 * @return string
	 */
	private function filename( Image_Result $image, string $title ): string {
		$extensions = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);

		$extension = $extensions[ $image->mime_type() ] ?? 'png';
		$slug      = sanitize_title( $title );

		if ( '' === $slug ) {
			$slug = 'autoscribe-image';
		}

		return $slug . '.' . $extension;
	}
}
