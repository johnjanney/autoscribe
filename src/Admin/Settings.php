<?php
/**
 * Typed accessor over the plugin's settings option.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the site-wide settings.
 *
 * Section 3.2 asks for a single option holding every setting. That is not what
 * the plugin does, and the brief contradicts itself on the point: section 8.1
 * requires API keys to live in their own encrypted store. Pricing and the global
 * budget cap were likewise given their own options in earlier phases, because
 * both are read on paths that never touch the rest of the settings. This class
 * owns what remains, and the README records the split.
 *
 * @since 0.7.0
 */
final class Settings {

	/**
	 * Option name.
	 *
	 * @since 0.7.0
	 * @var string
	 */
	public const OPTION = 'autoscribe_settings';

	/**
	 * Default retention period for run rows, in days.
	 *
	 * @since 0.7.0
	 * @var int
	 */
	public const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Returns every setting, with defaults filled in.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Returns the shipped defaults.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'notification_email' => (string) get_option( 'admin_email' ),
			'force_review'       => false,
			'retention_days'     => self::DEFAULT_RETENTION_DAYS,
			'default_models'     => array(),
		);
	}

	/**
	 * Returns the address run notifications are sent to.
	 *
	 * @since 0.7.0
	 *
	 * @return string
	 */
	public static function notification_email(): string {
		$value = (string) ( self::all()['notification_email'] ?? '' );

		return is_email( $value ) ? $value : (string) get_option( 'admin_email' );
	}

	/**
	 * Whether every prompt is forced to hold its output for human review.
	 *
	 * Section 10 calls this the safety catch, and requires it to beat the
	 * per-prompt setting in every case. It exists for the moment a provider
	 * changes behaviour or a prompt starts producing garbage, so it has to be
	 * one switch that stops all publishing without editing any prompt.
	 *
	 * @since 0.7.0
	 *
	 * @return bool
	 */
	public static function force_review(): bool {
		return (bool) ( self::all()['force_review'] ?? false );
	}

	/**
	 * Returns how many days of run history are kept.
	 *
	 * Zero disables pruning.
	 *
	 * @since 0.7.0
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		return max( 0, (int) ( self::all()['retention_days'] ?? self::DEFAULT_RETENTION_DAYS ) );
	}

	/**
	 * Returns the default model ID configured for a provider.
	 *
	 * @since 0.7.0
	 *
	 * @param string $slug Provider slug.
	 * @return string
	 */
	public static function default_model( string $slug ): string {
		$models = self::all()['default_models'] ?? array();

		if ( ! is_array( $models ) ) {
			return '';
		}

		return (string) ( $models[ $slug ] ?? '' );
	}

	/**
	 * Replaces the stored settings.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 * @return void
	 */
	public static function save( array $settings ): void {
		update_option( self::OPTION, array_merge( self::all(), $settings ) );
	}

	/**
	 * Converts a raw submission into storable settings.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $raw Raw submitted values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$email = sanitize_email( (string) ( $raw['notification_email'] ?? '' ) );

		$models = array();

		if ( isset( $raw['default_models'] ) && is_array( $raw['default_models'] ) ) {
			foreach ( $raw['default_models'] as $slug => $model ) {
				$models[ sanitize_key( (string) $slug ) ] = sanitize_text_field( (string) $model );
			}
		}

		return array(
			'notification_email' => is_email( $email ) ? $email : (string) get_option( 'admin_email' ),
			'force_review'       => ! empty( $raw['force_review'] ),
			'retention_days'     => max( 0, min( 3650, (int) ( $raw['retention_days'] ?? self::DEFAULT_RETENTION_DAYS ) ) ),
			'default_models'     => $models,
		);
	}
}
