<?php
/**
 * Editable per-model pricing.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Cost;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the rates used to estimate what a run costs.
 *
 * Section 7.4 is emphatic that these figures are an estimate and that provider
 * billing is the authority, so the table is a stored option the user can edit
 * rather than constants. Seeded values are a starting point that will drift;
 * nothing here should ever be presented as authoritative.
 *
 * Two costs the section 7.4 field list cannot express are added here. Search
 * grounding is billed per grounded request with no token component, so a
 * grounded run would otherwise under-report and slip past the cap. Image
 * pricing in practice varies by size and quality tier; a single per-image rate
 * is a v1 approximation and is documented as one.
 *
 * @since 0.5.0
 */
final class Pricing_Table {

	/**
	 * Option holding the rate table.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const OPTION = 'autoscribe_pricing';

	/**
	 * Rate key: US dollars per million input tokens.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const INPUT_PER_MILLION = 'input_per_million';

	/**
	 * Rate key: US dollars per million output tokens.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const OUTPUT_PER_MILLION = 'output_per_million';

	/**
	 * Rate key: US dollars per generated image.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const PER_IMAGE = 'per_image';

	/**
	 * Rate key: US dollars per grounded request.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const PER_GROUNDED_REQUEST = 'per_grounded_request';

	/**
	 * Returns the seeded default rates.
	 *
	 * Keyed by model identifier. Absent models fall back to the wildcard entry
	 * so an unknown or newly released model still produces a non-zero estimate
	 * rather than silently costing nothing and defeating the cap.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, array<string, float>>
	 */
	public static function defaults(): array {
		return array(
			'*'                      => self::rate( 5.0, 25.0, 0.04, 0.01 ),
			'claude-opus-5'          => self::rate( 5.0, 25.0, 0.0, 0.01 ),
			'claude-sonnet-5'        => self::rate( 3.0, 15.0, 0.0, 0.01 ),
			'claude-haiku-4-5'       => self::rate( 1.0, 5.0, 0.0, 0.01 ),
			'gpt-image-2'            => self::rate( 0.0, 0.0, 0.04, 0.0 ),
			'gemini-3.1-flash-image' => self::rate( 0.0, 0.0, 0.04, 0.0 ),
		);
	}

	/**
	 * Builds one rate row.
	 *
	 * @since 0.5.0
	 *
	 * @param float $input    Dollars per million input tokens.
	 * @param float $output   Dollars per million output tokens.
	 * @param float $image    Dollars per image.
	 * @param float $grounded Dollars per grounded request.
	 * @return array<string, float>
	 */
	public static function rate( float $input, float $output, float $image, float $grounded ): array {
		return array(
			self::INPUT_PER_MILLION    => $input,
			self::OUTPUT_PER_MILLION   => $output,
			self::PER_IMAGE            => $image,
			self::PER_GROUNDED_REQUEST => $grounded,
		);
	}

	/**
	 * Returns the whole table, seeded defaults merged under stored overrides.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, array<string, float>>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Returns the rate row for one model.
	 *
	 * @since 0.5.0
	 *
	 * @param string $model Model identifier.
	 * @return array<string, float>
	 */
	public function rates_for( string $model ): array {
		$table = $this->all();

		return $table[ $model ] ?? $table['*'];
	}

	/**
	 * Stores an override for one model.
	 *
	 * @since 0.5.0
	 *
	 * @param string               $model Model identifier.
	 * @param array<string, float> $rate  Rate row.
	 * @return void
	 */
	public function set( string $model, array $rate ): void {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored[ $model ] = $rate;

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Converts usage into whole cents.
	 *
	 * Rounds up, so an estimate never understates what a run will cost. A cap
	 * that is beaten by rounding is not a cap.
	 *
	 * @since 0.5.0
	 *
	 * @param string $text_model        Model that generated the text.
	 * @param int    $input_tokens      Prompt tokens.
	 * @param int    $output_tokens     Generated tokens.
	 * @param string $image_model       Model that generated images, or an empty string.
	 * @param int    $images            Number of images generated.
	 * @param int    $grounded_requests Number of grounded requests.
	 * @return int
	 */
	public function cost_cents(
		string $text_model,
		int $input_tokens,
		int $output_tokens,
		string $image_model = '',
		int $images = 0,
		int $grounded_requests = 0
	): int {
		$text = $this->rates_for( $text_model );

		$dollars  = ( $input_tokens / 1000000 ) * $text[ self::INPUT_PER_MILLION ];
		$dollars += ( $output_tokens / 1000000 ) * $text[ self::OUTPUT_PER_MILLION ];
		$dollars += $grounded_requests * $text[ self::PER_GROUNDED_REQUEST ];

		if ( $images > 0 ) {
			$image    = $this->rates_for( '' !== $image_model ? $image_model : $text_model );
			$dollars += $images * $image[ self::PER_IMAGE ];
		}

		return (int) ceil( $dollars * 100 );
	}
}
