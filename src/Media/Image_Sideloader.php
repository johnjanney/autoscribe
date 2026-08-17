<?php
/**
 * Featured image sideloading.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Media;

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
 * first for inline data and download_url() for a short-lived URL.
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

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $image->mime_type(),
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
			return $bytes;
		}

		$url = $image->url();

		if ( ! is_string( $url ) || '' === $url ) {
			return new WP_Error(
				'autoscribe_image_empty',
				__( 'The image provider returned neither image data nor a URL.', 'autoscribe' )
			);
		}

		$temp = download_url( $url );

		if ( is_wp_error( $temp ) ) {
			return $temp;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			WP_Filesystem();
		}

		$contents = $wp_filesystem->get_contents( $temp );
		wp_delete_file( $temp );

		if ( false === $contents || '' === $contents ) {
			return new WP_Error(
				'autoscribe_image_empty',
				__( 'The downloaded image was empty.', 'autoscribe' )
			);
		}

		return $contents;
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
