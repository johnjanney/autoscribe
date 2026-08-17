<?php
/**
 * API key storage.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes provider API keys.
 *
 * Section 8.1 defines two methods and prefers the first: a constant in
 * wp-config.php, which never enters the database, and failing that an option
 * encrypted with sodium_crypto_secretbox using a key derived from the AUTH_KEY
 * and SECURE_AUTH_KEY salts.
 *
 * The brief does not address what happens when those salts are rotated, which
 * WordPress does on "log out everywhere" and some hosts do during migrations.
 * When that happens sodium_crypto_secretbox_open() returns false, which is
 * indistinguishable from a corrupt ciphertext or a wrong key. A short
 * fingerprint of the salts is therefore stored alongside each ciphertext so
 * this class can report a stale key distinctly and tell the user to re-enter
 * it, rather than surfacing an unexplained connection failure.
 *
 * @since 0.2.0
 */
final class Key_Store {

	/**
	 * Option holding the encrypted keys.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const OPTION = 'autoscribe_keys';

	/**
	 * Status returned when the key comes from a wp-config.php constant.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const SOURCE_CONSTANT = 'constant';

	/**
	 * Status returned when the key comes from the encrypted option.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const SOURCE_STORED = 'stored';

	/**
	 * Status returned when a stored key predates a salt rotation.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const SOURCE_STALE = 'stale';

	/**
	 * Status returned when no key is configured.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const SOURCE_MISSING = 'missing';

	/**
	 * Returns the constant name a provider's key would be read from.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return string
	 */
	public static function constant_name( string $slug ): string {
		return 'AUTOSCRIBE_' . strtoupper( str_replace( '-', '_', $slug ) ) . '_KEY';
	}

	/**
	 * Returns where a provider's key is coming from.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return string One of the SOURCE_* constants.
	 */
	public static function source( string $slug ): string {
		$constant = self::constant_name( $slug );

		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return self::SOURCE_CONSTANT;
		}

		$stored = self::stored_record( $slug );

		if ( null === $stored ) {
			return self::SOURCE_MISSING;
		}

		if ( ( $stored['fingerprint'] ?? '' ) !== self::salt_fingerprint() ) {
			return self::SOURCE_STALE;
		}

		return self::SOURCE_STORED;
	}

	/**
	 * Returns a provider's API key.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return string|WP_Error The key, or an error explaining why it is unavailable.
	 */
	public static function get( string $slug ): string|WP_Error {
		$source = self::source( $slug );

		if ( self::SOURCE_CONSTANT === $source ) {
			return (string) constant( self::constant_name( $slug ) );
		}

		if ( self::SOURCE_MISSING === $source ) {
			return new WP_Error(
				'autoscribe_key_missing',
				sprintf(
					/* translators: %s: provider slug. */
					__( 'No API key is configured for %s.', 'autoscribe' ),
					$slug
				)
			);
		}

		if ( self::SOURCE_STALE === $source ) {
			return new WP_Error(
				'autoscribe_key_stale',
				sprintf(
					/* translators: %s: provider slug. */
					__( 'The stored %s key was encrypted with different WordPress security salts and can no longer be read. Enter the key again.', 'autoscribe' ),
					$slug
				)
			);
		}

		$record     = self::stored_record( $slug );
		$nonce_hex  = (string) ( $record['nonce'] ?? '' );
		$cipher_hex = (string) ( $record['cipher'] ?? '' );

		if ( ! self::is_hex( $nonce_hex ) || ! self::is_hex( $cipher_hex ) ) {
			return new WP_Error(
				'autoscribe_key_corrupt',
				__( 'The stored API key could not be read. Enter the key again.', 'autoscribe' )
			);
		}

		$nonce  = hex2bin( $nonce_hex );
		$cipher = hex2bin( $cipher_hex );

		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::encryption_key() );

		if ( false === $plain ) {
			return new WP_Error(
				'autoscribe_key_corrupt',
				__( 'The stored API key could not be decrypted. Enter the key again.', 'autoscribe' )
			);
		}

		return $plain;
	}

	/**
	 * Stores a provider's API key, encrypted.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug    Provider slug.
	 * @param string $api_key Key to store.
	 * @return bool|WP_Error True on success, error when libsodium is unavailable.
	 */
	public static function set( string $slug, string $api_key ): bool|WP_Error {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return new WP_Error(
				'autoscribe_sodium_missing',
				__( 'This site has no libsodium support, so API keys cannot be stored securely. Use a wp-config.php constant instead.', 'autoscribe' )
			);
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$keys          = self::all_records();
		$keys[ $slug ] = array(
			'fingerprint' => self::salt_fingerprint(),
			'nonce'       => bin2hex( $nonce ),
			'cipher'      => bin2hex( sodium_crypto_secretbox( $api_key, $nonce, self::encryption_key() ) ),
		);

		update_option( self::OPTION, $keys, false );

		return true;
	}

	/**
	 * Removes a stored key.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return void
	 */
	public static function forget( string $slug ): void {
		$keys = self::all_records();

		unset( $keys[ $slug ] );

		update_option( self::OPTION, $keys, false );
	}

	/**
	 * Whether a string is valid, even-length hexadecimal.
	 *
	 * Checked before hex2bin() so malformed stored data produces a clear error
	 * instead of a PHP warning.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value Candidate hexadecimal string.
	 * @return bool
	 */
	private static function is_hex( string $value ): bool {
		return '' !== $value && 0 === strlen( $value ) % 2 && ctype_xdigit( $value );
	}

	/**
	 * Returns every stored record.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function all_records(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Returns one stored record.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return array<string, string>|null
	 */
	private static function stored_record( string $slug ): ?array {
		$records = self::all_records();

		return isset( $records[ $slug ] ) && is_array( $records[ $slug ] ) ? $records[ $slug ] : null;
	}

	/**
	 * Derives the symmetric encryption key from the WordPress salts.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private static function encryption_key(): string {
		return hash_hkdf( 'sha256', self::salt_material(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'autoscribe-key-store' );
	}

	/**
	 * Returns a short, non-reversible fingerprint of the current salts.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private static function salt_fingerprint(): string {
		return substr( hash( 'sha256', 'fingerprint|' . self::salt_material() ), 0, 16 );
	}

	/**
	 * Returns the raw salt material the key and fingerprint derive from.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private static function salt_material(): string {
		$auth   = defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : '';
		$secure = defined( 'SECURE_AUTH_KEY' ) ? (string) constant( 'SECURE_AUTH_KEY' ) : '';

		return $auth . '|' . $secure;
	}
}
