<?php
/**
 * Provider registry.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers;

use AutoScribe\Providers\Image\Google_Image;
use AutoScribe\Providers\Image\Null_Image;
use AutoScribe\Providers\Image\OpenAI_Image;
use AutoScribe\Providers\Text\Anthropic;
use AutoScribe\Providers\Text\DeepSeek;
use AutoScribe\Providers\Text\Google;
use AutoScribe\Providers\Text\OpenAI;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves provider slugs to adapters.
 *
 * Text and image providers are held in separate maps because section 2.1
 * establishes that the two capabilities do not travel together: a user must be
 * able to pick Claude for the article and a Gemini image model for the picture.
 *
 * @since 0.2.0
 */
final class Provider_Registry {

	/**
	 * Text adapters keyed by slug.
	 *
	 * @since 0.2.0
	 * @var array<string, Text_Provider_Interface>
	 */
	private array $text_providers = array();

	/**
	 * Image adapters keyed by slug.
	 *
	 * @since 0.2.0
	 * @var array<string, Image_Provider_Interface>
	 */
	private array $image_providers = array();

	/**
	 * Builds the registry with the four text and three image adapters.
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		foreach ( array( new Anthropic(), new OpenAI(), new Google(), new DeepSeek() ) as $provider ) {
			$this->text_providers[ $provider->slug() ] = $provider;
		}

		foreach ( array( new OpenAI_Image(), new Google_Image(), new Null_Image() ) as $provider ) {
			$this->image_providers[ $provider->slug() ] = $provider;
		}
	}

	/**
	 * Returns every registered text adapter.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, Text_Provider_Interface>
	 */
	public function text_providers(): array {
		return $this->text_providers;
	}

	/**
	 * Returns every registered image adapter.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, Image_Provider_Interface>
	 */
	public function image_providers(): array {
		return $this->image_providers;
	}

	/**
	 * Resolves a text adapter by slug.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return Text_Provider_Interface|null Adapter, or null when unknown.
	 */
	public function text_provider( string $slug ): ?Text_Provider_Interface {
		return $this->text_providers[ $slug ] ?? null;
	}

	/**
	 * Resolves an image adapter by slug.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return Image_Provider_Interface|null Adapter, or null when unknown.
	 */
	public function image_provider( string $slug ): ?Image_Provider_Interface {
		return $this->image_providers[ $slug ] ?? null;
	}
}
