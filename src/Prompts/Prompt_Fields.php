<?php
/**
 * Single definition of every editable prompt field.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Prompts;

use AutoScribe\Providers\Provider_Registry;
use AutoScribe\Scheduling\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Describes each prompt field once, for both rendering and saving.
 *
 * Section 3.2 lists twenty-three meta keys and section 9.2 groups them into five
 * tabs. Describing a field in the form markup and again in the save handler is
 * how a field ends up rendered but never persisted: the two lists drift, nothing
 * errors, and the value silently fails to save. So there is one list, and both
 * passes read from it. Adding a field here makes it appear in the editor and
 * persist, with no second edit.
 *
 * @since 0.7.0
 */
final class Prompt_Fields {

	/**
	 * Meta key prefix, per section 3.2.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const PREFIX = '_autoscribe_';

	/**
	 * Form field name the meta box posts its values under.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const INPUT_NAME = 'autoscribe_prompt';

	/**
	 * Returns the tab identifiers and their labels, in display order.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'content'    => __( 'Content', 'autoscribe' ),
			'schedule'   => __( 'Schedule', 'autoscribe' ),
			'image'      => __( 'Image', 'autoscribe' ),
			'publishing' => __( 'Publishing', 'autoscribe' ),
			'limits'     => __( 'Limits', 'autoscribe' ),
		);
	}

	/**
	 * Returns every editable field, keyed by field name.
	 *
	 * A field carrying 'param' => true is stored inside the schedule_params
	 * array rather than under a meta key of its own, because section 4.1 gives
	 * each schedule type a different parameter set and storing them as one array
	 * keeps Schedule::create() the only thing that has to know which.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array_merge(
			self::content_fields(),
			self::schedule_fields(),
			self::image_fields(),
			self::publishing_fields(),
			self::limit_fields()
		);
	}

	/**
	 * Returns the Content tab fields.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function content_fields(): array {
		return array(
			'text_provider'     => array(
				'tab'         => 'content',
				'label'       => __( 'Text provider', 'autoscribe' ),
				'type'        => 'select',
				'default'     => 'anthropic',
				'choices'     => array( self::class, 'text_provider_choices' ),
				'description' => __( 'Which service writes the article.', 'autoscribe' ),
			),
			'text_model'        => array(
				'tab'         => 'content',
				'label'       => __( 'Text model', 'autoscribe' ),
				'type'        => 'model',
				'default'     => '',
				'suggestions' => array( self::class, 'text_model_suggestions' ),
				'description' => __( 'Editable. Model IDs are retired regularly, so this is never fixed in code.', 'autoscribe' ),
			),
			'system_prompt'     => array(
				'tab'         => 'content',
				'label'       => __( 'System prompt', 'autoscribe' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Persona and rules. Sent on every call.', 'autoscribe' ),
			),
			'user_prompt'       => array(
				'tab'         => 'content',
				'label'       => __( 'Topic instruction', 'autoscribe' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'What to write about.', 'autoscribe' ),
			),
			'target_word_count' => array(
				'tab'     => 'content',
				'label'   => __( 'Target word count', 'autoscribe' ),
				'type'    => 'int',
				'default' => 800,
				'min'     => 100,
				'max'     => 10000,
			),
			'grounding_enabled' => array(
				'tab'         => 'content',
				'label'       => __( 'Use web search grounding', 'autoscribe' ),
				'type'        => 'bool',
				'default'     => false,
				'description' => __( 'Grounded content is untrusted third-party text entering the model context. Human review is strongly recommended when this is on.', 'autoscribe' ),
			),
			'append_sources'    => array(
				'tab'         => 'content',
				'label'       => __( 'Append a Sources list', 'autoscribe' ),
				'type'        => 'bool',
				'default'     => false,
				'description' => __( 'Adds the URLs a grounded call reported using to the end of the article. They are recorded on the run either way.', 'autoscribe' ),
			),
		);
	}

	/**
	 * Returns the Schedule tab fields.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schedule_fields(): array {
		return array(
			'enabled'       => array(
				'tab'         => 'schedule',
				'label'       => __( 'Enabled', 'autoscribe' ),
				'type'        => 'bool',
				'default'     => false,
				'description' => __( 'A disabled prompt is removed from the queue entirely.', 'autoscribe' ),
			),
			'schedule_type' => array(
				'tab'     => 'schedule',
				'label'   => __( 'Repeats', 'autoscribe' ),
				'type'    => 'select',
				'default' => Schedule::TYPE_DAILY,
				'choices' => array( self::class, 'schedule_type_choices' ),
			),
			'time'          => array(
				'tab'     => 'schedule',
				'label'   => __( 'Time of day', 'autoscribe' ),
				'type'    => 'time',
				'default' => '06:00',
				'param'   => true,
				'for'     => array(
					Schedule::TYPE_DAILY,
					Schedule::TYPE_WEEKLY,
					Schedule::TYPE_MONTHLY_DATE,
					Schedule::TYPE_MONTHLY_ORDINAL,
				),
			),
			'weekday'       => array(
				'tab'     => 'schedule',
				'label'   => __( 'Weekday', 'autoscribe' ),
				'type'    => 'select',
				'default' => 'monday',
				'param'   => true,
				'choices' => array( self::class, 'weekday_choices' ),
				'for'     => array( Schedule::TYPE_WEEKLY, Schedule::TYPE_MONTHLY_ORDINAL ),
			),
			'day_of_month'  => array(
				'tab'         => 'schedule',
				'label'       => __( 'Day of month', 'autoscribe' ),
				'type'        => 'int',
				'default'     => 1,
				'min'         => 1,
				'max'         => 31,
				'param'       => true,
				'for'         => array( Schedule::TYPE_MONTHLY_DATE ),
				'description' => __( 'A day past the end of a short month rolls back to that month\'s last day.', 'autoscribe' ),
			),
			'ordinal'       => array(
				'tab'     => 'schedule',
				'label'   => __( 'Which week', 'autoscribe' ),
				'type'    => 'select',
				'default' => 'first',
				'param'   => true,
				'choices' => array( self::class, 'ordinal_choices' ),
				'for'     => array( Schedule::TYPE_MONTHLY_ORDINAL ),
			),
			'hours'         => array(
				'tab'     => 'schedule',
				'label'   => __( 'Every N hours', 'autoscribe' ),
				'type'    => 'int',
				'default' => 24,
				'min'     => 1,
				'max'     => 8760,
				'param'   => true,
				'for'     => array( Schedule::TYPE_INTERVAL ),
			),
			'expression'    => array(
				'tab'         => 'schedule',
				'label'       => __( 'Cron expression', 'autoscribe' ),
				'type'        => 'text',
				'default'     => '0 6 * * *',
				'param'       => true,
				'for'         => array( Schedule::TYPE_CRON ),
				'description' => __( 'Five fields, evaluated in the site timezone.', 'autoscribe' ),
			),
		);
	}

	/**
	 * Returns the Image tab fields.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function image_fields(): array {
		return array(
			'image_mode'         => array(
				'tab'     => 'image',
				'label'   => __( 'Featured image', 'autoscribe' ),
				'type'    => 'select',
				'default' => 'optional',
				'choices' => array( self::class, 'image_mode_choices' ),
			),
			'image_provider'     => array(
				'tab'         => 'image',
				'label'       => __( 'Image provider', 'autoscribe' ),
				'type'        => 'select',
				'default'     => 'none',
				'choices'     => array( self::class, 'image_provider_choices' ),
				'description' => __( 'Anthropic and DeepSeek generate no images, so the image provider is chosen separately from the text provider.', 'autoscribe' ),
			),
			'image_model'        => array(
				'tab'         => 'image',
				'label'       => __( 'Image model', 'autoscribe' ),
				'type'        => 'model',
				'default'     => '',
				'suggestions' => array( self::class, 'image_model_suggestions' ),
			),
			'image_style_suffix' => array(
				'tab'         => 'image',
				'label'       => __( 'House style suffix', 'autoscribe' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Appended to every image prompt, so every article shares a look without editing each prompt.', 'autoscribe' ),
			),
			'fallback_image_id'  => array(
				'tab'         => 'image',
				'label'       => __( 'Fallback image ID', 'autoscribe' ),
				'type'        => 'int',
				'default'     => 0,
				'min'         => 0,
				'description' => __( 'Attachment ID used when the mode is "fallback" and generation fails.', 'autoscribe' ),
			),
		);
	}

	/**
	 * Returns the Publishing tab fields.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function publishing_fields(): array {
		return array(
			'post_status_mode' => array(
				'tab'     => 'publishing',
				'label'   => __( 'On completion', 'autoscribe' ),
				'type'    => 'select',
				'default' => 'review',
				'choices' => array( self::class, 'status_mode_choices' ),
			),
			'post_type'        => array(
				'tab'     => 'publishing',
				'label'   => __( 'Create as', 'autoscribe' ),
				'type'    => 'select',
				'default' => 'post',
				'choices' => array( self::class, 'post_type_choices' ),
			),
			'category_ids'     => array(
				'tab'     => 'publishing',
				'label'   => __( 'Categories', 'autoscribe' ),
				'type'    => 'terms',
				'default' => array(),
			),
			'tag_mode'         => array(
				'tab'     => 'publishing',
				'label'   => __( 'Tags', 'autoscribe' ),
				'type'    => 'select',
				'default' => 'none',
				'choices' => array( self::class, 'tag_mode_choices' ),
			),
			'fixed_tags'       => array(
				'tab'         => 'publishing',
				'label'       => __( 'Fixed tags', 'autoscribe' ),
				'type'        => 'csv',
				'default'     => array(),
				'description' => __( 'Comma separated. Used when the tag mode is "fixed".', 'autoscribe' ),
			),
			'author_id'        => array(
				'tab'     => 'publishing',
				'label'   => __( 'Author', 'autoscribe' ),
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
			),
		);
	}

	/**
	 * Returns the Limits tab fields.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function limit_fields(): array {
		return array(
			'monthly_budget_cents' => array(
				'tab'         => 'limits',
				'label'       => __( 'Monthly cap (cents)', 'autoscribe' ),
				'type'        => 'int',
				'default'     => 0,
				'min'         => 0,
				'description' => __( 'Zero means no per-prompt cap. The global cap still applies and always wins.', 'autoscribe' ),
			),
			'dedupe_lookback'      => array(
				'tab'         => 'limits',
				'label'       => __( 'Duplicate look-back', 'autoscribe' ),
				'type'        => 'int',
				'default'     => 50,
				'min'         => 0,
				'max'         => 500,
				'description' => __( 'How many recent posts a proposed topic is compared against.', 'autoscribe' ),
			),
		);
	}

	/**
	 * Returns the selectable options for a field.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return array<string, string> Value to label.
	 */
	public static function choices( array $field ): array {
		if ( ! isset( $field['choices'] ) ) {
			return array();
		}

		$choices = is_callable( $field['choices'] ) ? call_user_func( $field['choices'] ) : $field['choices'];

		return is_array( $choices ) ? $choices : array();
	}

	/**
	 * Returns the suggested values offered alongside an editable model field.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return string[]
	 */
	public static function suggestions( array $field ): array {
		if ( ! isset( $field['suggestions'] ) || ! is_callable( $field['suggestions'] ) ) {
			return array();
		}

		$values = call_user_func( $field['suggestions'] );

		return is_array( $values ) ? $values : array();
	}

	/**
	 * Converts a raw submitted value into the value that will be stored.
	 *
	 * Every branch returns a value of the field's declared type, so a hostile or
	 * malformed submission cannot write a value the accessors in Prompt would
	 * then have to defend against.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $raw   Raw submitted value.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize( array $field, $raw ) {
		switch ( (string) $field['type'] ) {
			case 'bool':
				return (bool) $raw;

			case 'int':
				return self::clamp( (int) $raw, $field );

			case 'select':
				$choices = self::choices( $field );
				$value   = sanitize_text_field( (string) $raw );

				return array_key_exists( $value, $choices ) ? $value : (string) $field['default'];

			case 'terms':
				return self::sanitize_terms( $raw );

			case 'csv':
				return self::sanitize_csv( $raw );

			case 'time':
				return self::sanitize_time( (string) $raw, (string) $field['default'] );

			case 'textarea':
				return sanitize_textarea_field( (string) $raw );

			case 'model':
			case 'text':
			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Constrains an integer to the field's declared bounds.
	 *
	 * @since 0.7.0
	 *
	 * @param int                  $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 * @return int
	 */
	private static function clamp( int $value, array $field ): int {
		if ( isset( $field['min'] ) ) {
			$value = max( (int) $field['min'], $value );
		}

		if ( isset( $field['max'] ) ) {
			$value = min( (int) $field['max'], $value );
		}

		return $value;
	}

	/**
	 * Reduces submitted term IDs to categories that actually exist.
	 *
	 * Section 7.3 says categories come from the prompt and the model never
	 * invents one, so an ID naming a term that is gone is dropped rather than
	 * stored and failed on later.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $raw Submitted value.
	 * @return int[]
	 */
	private static function sanitize_terms( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();

		foreach ( $raw as $candidate ) {
			$id = absint( $candidate );

			if ( $id > 0 && null !== term_exists( $id, 'category' ) ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Splits a comma separated list into sanitized values.
	 *
	 * Accepts an array as well as a string, because the field's own default is
	 * the empty array and that default is what gets sanitized whenever the field
	 * is absent from a submission.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $raw Submitted value.
	 * @return string[]
	 */
	private static function sanitize_csv( $raw ): array {
		$parts = is_array( $raw )
			? array_map( 'strval', $raw )
			: array_map( 'trim', explode( ',', (string) $raw ) );

		$clean = array();

		foreach ( $parts as $part ) {
			$value = sanitize_text_field( $part );

			if ( '' !== $value ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Validates a 24-hour time of day.
	 *
	 * @since 0.7.0
	 *
	 * @param string $raw      Submitted value.
	 * @param string $fallback Value to use when the input is not a valid time.
	 * @return string
	 */
	private static function sanitize_time( string $raw, string $fallback ): string {
		if ( 1 !== preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', trim( $raw ) ) ) {
			return $fallback;
		}

		return trim( $raw );
	}

	/**
	 * Returns the registered text providers as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function text_provider_choices(): array {
		$choices = array();

		foreach ( ( new Provider_Registry() )->text_providers() as $provider ) {
			$choices[ $provider->slug() ] = $provider->label();
		}

		return $choices;
	}

	/**
	 * Returns the registered image providers as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function image_provider_choices(): array {
		$choices = array();

		foreach ( ( new Provider_Registry() )->image_providers() as $provider ) {
			$choices[ $provider->slug() ] = $provider->label();
		}

		return $choices;
	}

	/**
	 * Returns every suggested text model across all providers.
	 *
	 * @since 0.7.0
	 *
	 * @return string[]
	 */
	public static function text_model_suggestions(): array {
		$models = array();

		foreach ( ( new Provider_Registry() )->text_providers() as $provider ) {
			$models = array_merge( $models, $provider->suggested_models() );
		}

		return array_values( array_unique( $models ) );
	}

	/**
	 * Returns every suggested image model across all providers.
	 *
	 * @since 0.7.0
	 *
	 * @return string[]
	 */
	public static function image_model_suggestions(): array {
		$models = array();

		foreach ( ( new Provider_Registry() )->image_providers() as $provider ) {
			$models = array_merge( $models, $provider->suggested_models() );
		}

		return array_values( array_unique( $models ) );
	}

	/**
	 * Returns the schedule types as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function schedule_type_choices(): array {
		return array(
			Schedule::TYPE_DAILY           => __( 'Every day', 'autoscribe' ),
			Schedule::TYPE_WEEKLY          => __( 'Every week', 'autoscribe' ),
			Schedule::TYPE_MONTHLY_DATE    => __( 'Monthly, on a date', 'autoscribe' ),
			Schedule::TYPE_MONTHLY_ORDINAL => __( 'Monthly, on a weekday', 'autoscribe' ),
			Schedule::TYPE_INTERVAL        => __( 'Every N hours', 'autoscribe' ),
			Schedule::TYPE_CRON            => __( 'Cron expression', 'autoscribe' ),
		);
	}

	/**
	 * Returns the weekdays as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function weekday_choices(): array {
		$labels = array(
			'monday'    => __( 'Monday', 'autoscribe' ),
			'tuesday'   => __( 'Tuesday', 'autoscribe' ),
			'wednesday' => __( 'Wednesday', 'autoscribe' ),
			'thursday'  => __( 'Thursday', 'autoscribe' ),
			'friday'    => __( 'Friday', 'autoscribe' ),
			'saturday'  => __( 'Saturday', 'autoscribe' ),
			'sunday'    => __( 'Sunday', 'autoscribe' ),
		);

		$choices = array();

		foreach ( Schedule::WEEKDAYS as $weekday ) {
			$choices[ $weekday ] = $labels[ $weekday ] ?? $weekday;
		}

		return $choices;
	}

	/**
	 * Returns the ordinals as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function ordinal_choices(): array {
		$labels = array(
			'first'  => __( 'First', 'autoscribe' ),
			'second' => __( 'Second', 'autoscribe' ),
			'third'  => __( 'Third', 'autoscribe' ),
			'fourth' => __( 'Fourth', 'autoscribe' ),
			'last'   => __( 'Last', 'autoscribe' ),
		);

		$choices = array();

		foreach ( Schedule::ORDINALS as $ordinal ) {
			$choices[ $ordinal ] = $labels[ $ordinal ] ?? $ordinal;
		}

		return $choices;
	}

	/**
	 * Returns the image modes from section 6 as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function image_mode_choices(): array {
		return array(
			'required' => __( 'Required — never publish without it', 'autoscribe' ),
			'fallback' => __( 'Fallback — use the fallback image', 'autoscribe' ),
			'optional' => __( 'Optional — publish without one', 'autoscribe' ),
			'none'     => __( 'None — skip image generation', 'autoscribe' ),
		);
	}

	/**
	 * Returns the publication modes as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function status_mode_choices(): array {
		return array(
			'review' => __( 'Hold as a draft for review', 'autoscribe' ),
			'auto'   => __( 'Publish immediately', 'autoscribe' ),
		);
	}

	/**
	 * Returns the permitted target post types as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function post_type_choices(): array {
		return array(
			'post' => __( 'Post', 'autoscribe' ),
			'page' => __( 'Page', 'autoscribe' ),
		);
	}

	/**
	 * Returns the tag modes from section 7.3 as select options.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, string>
	 */
	public static function tag_mode_choices(): array {
		return array(
			'none'  => __( 'No tags', 'autoscribe' ),
			'fixed' => __( 'Fixed list', 'autoscribe' ),
			'ai'    => __( 'Suggested by the model', 'autoscribe' ),
		);
	}
}
