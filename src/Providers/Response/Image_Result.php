<?php
/**
 * Result of a successful image generation call.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral image result.
 *
 * Section 6 of the brief notes that providers return either base64 data or a
 * short-lived URL, so both are representable here and exactly one is set.
 *
 * @since 0.2.0
 */
final class Image_Result {

	/**
	 * Decoded image bytes, when the provider returned inline data.
	 *
	 * @since 0.2.0
	 * @var string|null
	 */
	private ?string $bytes;

	/**
	 * Short-lived URL, when the provider returned a link.
	 *
	 * @since 0.2.0
	 * @var string|null
	 */
	private ?string $url;

	/**
	 * MIME type of the image.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $mime_type;

	/**
	 * Model that produced the image.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $model;

	/**
	 * Builds an image result.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $bytes     Decoded image bytes, or null when a URL was returned.
	 * @param string|null $url       Short-lived URL, or null when bytes were returned.
	 * @param string      $mime_type MIME type of the image.
	 * @param string      $model     Model that produced the image.
	 */
	public function __construct( ?string $bytes, ?string $url, string $mime_type, string $model ) {
		$this->bytes     = $bytes;
		$this->url       = $url;
		$this->mime_type = $mime_type;
		$this->model     = $model;
	}

	/**
	 * Returns the decoded image bytes, if the provider sent inline data.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null
	 */
	public function bytes(): ?string {
		return $this->bytes;
	}

	/**
	 * Returns the image URL, if the provider sent a link.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null
	 */
	public function url(): ?string {
		return $this->url;
	}

	/**
	 * Returns the MIME type.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function mime_type(): string {
		return $this->mime_type;
	}

	/**
	 * Returns the model that produced the image.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}
}
