<?php
/**
 * Shared HTTP transport for provider adapters.
 *
 * @package AutoScribe
 */

namespace AutoScribe\Providers;

use AutoScribe\Diagnostics\Debug_Log;
use WP_Error;

use const AutoScribe\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_remote_request() with the plugin's transport policy.
 *
 * Centralises the timeout and user-agent requirements from section 8.2, and
 * turns every provider failure into a WP_Error carrying a code the caller can
 * branch on. Nothing here throws: an adapter that hits a revoked key must
 * surface a message, never a fatal.
 *
 * @since 0.2.0
 */
final class Http {

	/**
	 * Timeout for generation calls, in seconds.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	public const TIMEOUT_GENERATION = 120;

	/**
	 * Timeout for everything else, in seconds.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	public const TIMEOUT_DEFAULT = 30;

	/**
	 * Largest provider response body the plugin will read, in bytes.
	 *
	 * A response is read wholly into memory before it is decoded, so without a
	 * ceiling a faulty or compromised endpoint can exhaust PHP's memory limit and
	 * take the request down with a fatal rather than a handled error. Eight
	 * megabytes is far above any legitimate JSON article payload — the largest a
	 * 10,000-word request can produce is a few hundred kilobytes — and far below
	 * a default memory limit.
	 *
	 * @since 1.0.1
	 * @var int
	 */
	public const MAX_RESPONSE_BYTES = 8388608;

	/**
	 * Sends a JSON POST and returns the decoded body.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $url     Absolute endpoint URL.
	 * @param array<string, string> $headers Request headers.
	 * @param array<string, mixed>  $body    Body to JSON-encode.
	 * @param int                   $timeout Timeout in seconds.
	 * @return array<string, mixed>|WP_Error Decoded body, or an error.
	 */
	public static function post_json( string $url, array $headers, array $body, int $timeout ): array|WP_Error {
		$headers['content-type'] = 'application/json';

		$encoded = wp_json_encode( $body );
		$started = microtime( true );

		$response = wp_remote_post(
			$url,
			array(
				'headers'             => $headers,
				'body'                => $encoded,
				'timeout'             => $timeout,
				'user-agent'          => self::user_agent(),
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
			)
		);

		self::record( 'POST', $url, $response, $started, is_string( $encoded ) ? $encoded : '' );

		return self::interpret( $response );
	}

	/**
	 * Sends a GET and returns the decoded body.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $url     Absolute endpoint URL.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeout Timeout in seconds.
	 * @return array<string, mixed>|WP_Error Decoded body, or an error.
	 */
	public static function get_json( string $url, array $headers, int $timeout ): array|WP_Error {
		$started = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'headers'             => $headers,
				'timeout'             => $timeout,
				'user-agent'          => self::user_agent(),
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
			)
		);

		self::record( 'GET', $url, $response, $started, '' );

		return self::interpret( $response );
	}

	/**
	 * Hands a completed exchange to the debug log, when capture is on.
	 *
	 * This sits beside interpret() rather than inside it because the two want
	 * different things from the same response. interpret() reduces it to the one
	 * sentence a run log entry can hold, which is the right size for somebody
	 * reading a list of runs and the wrong size for somebody working out why one
	 * of them keeps failing. The provider's own words survive here instead of
	 * being read once and discarded.
	 *
	 * Headers are not passed in, and are the only part of a request that carries
	 * the key: all four providers authenticate in a header, and none of them puts
	 * the key in the URL. The request body is kept only where the exchange failed,
	 * because a rejected request is the case where the question matters as much as
	 * the answer, and a successful one would only be the prompt written back.
	 *
	 * @since 1.16.0
	 *
	 * @param string                        $method       HTTP method.
	 * @param string                        $url          Endpoint called.
	 * @param array<string, mixed>|WP_Error $response     Raw transport result.
	 * @param float                         $started      microtime() when the call began.
	 * @param string                        $request_body Encoded request body, empty for a GET.
	 * @return void
	 */
	private static function record( string $method, string $url, array|WP_Error $response, float $started, string $request_body ): void {
		if ( ! Debug_Log::enabled() ) {
			return;
		}

		$facts = array(
			'method' => $method,
			'ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);

		if ( is_wp_error( $response ) ) {
			Debug_Log::record(
				Debug_Log::CHANNEL_TRANSPORT,
				$url,
				$response->get_error_code() . ': ' . $response->get_error_message() . self::sent( $request_body ),
				$facts
			);

			return;
		}

		$status          = (int) wp_remote_retrieve_response_code( $response );
		$facts['status'] = $status;

		$body = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status > 299 ) {
			$body .= self::sent( $request_body );
		}

		Debug_Log::record( Debug_Log::CHANNEL_HTTP, $url, $body, $facts );
	}

	/**
	 * Formats the request body as a labelled block below a response.
	 *
	 * @since 1.16.0
	 *
	 * @param string $request_body Encoded request body.
	 * @return string
	 */
	private static function sent( string $request_body ): string {
		if ( '' === $request_body ) {
			return '';
		}

		return "\n\n--- request sent (headers omitted) ---\n" . $request_body;
	}

	/**
	 * Returns the user agent identifying this plugin, per section 8.2.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		return 'AutoScribe/' . VERSION . ' (+' . home_url( '/' ) . ')';
	}

	/**
	 * Converts a raw wp_remote_* return value into decoded data or a WP_Error.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>|WP_Error $response Raw transport result.
	 * @return array<string, mixed>|WP_Error Decoded body, or an error.
	 */
	private static function interpret( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'autoscribe_transport_error',
				sprintf(
					/* translators: %s: underlying transport error message. */
					__( 'Could not reach the provider: %s', 'autoscribe' ),
					$response->get_error_message()
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $status < 200 || $status > 299 ) {
			return self::error_for_status( $status, is_array( $decoded ) ? $decoded : array() );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'autoscribe_invalid_response',
				__( 'The provider returned a response that was not valid JSON.', 'autoscribe' )
			);
		}

		return $decoded;
	}

	/**
	 * Maps an HTTP status onto an actionable WP_Error.
	 *
	 * Distinguishing these matters for retry policy: an authentication failure
	 * must never be retried, while an overload should be.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $status  HTTP status code.
	 * @param array<string, mixed> $decoded Decoded error body, when available.
	 * @return WP_Error
	 */
	private static function error_for_status( int $status, array $decoded ): WP_Error {
		$detail = self::extract_message( $decoded );

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error(
				'autoscribe_provider_auth',
				sprintf(
					/* translators: %s: message returned by the provider. */
					__( 'The provider rejected the API key. Check the key and try again. Provider said: %s', 'autoscribe' ),
					$detail
				),
				array( 'status' => $status )
			);
		}

		if ( 404 === $status ) {
			return new WP_Error(
				'autoscribe_provider_model_not_found',
				sprintf(
					/* translators: %s: message returned by the provider. */
					__( 'The provider does not recognise that model ID. Check the model field. Provider said: %s', 'autoscribe' ),
					$detail
				),
				array( 'status' => $status )
			);
		}

		if ( 429 === $status ) {
			return new WP_Error(
				'autoscribe_provider_rate_limited',
				sprintf(
					/* translators: %s: message returned by the provider. */
					__( 'The provider rate-limited this request. Provider said: %s', 'autoscribe' ),
					$detail
				),
				array( 'status' => $status )
			);
		}

		if ( $status >= 500 ) {
			return new WP_Error(
				'autoscribe_provider_unavailable',
				sprintf(
					/* translators: %s: message returned by the provider. */
					__( 'The provider is temporarily unavailable. Provider said: %s', 'autoscribe' ),
					$detail
				),
				array( 'status' => $status )
			);
		}

		return new WP_Error(
			'autoscribe_provider_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: message returned by the provider. */
				__( 'The provider returned HTTP %1$d. Provider said: %2$s', 'autoscribe' ),
				$status,
				$detail
			),
			array( 'status' => $status )
		);
	}

	/**
	 * Digs a human-readable message out of a provider error body.
	 *
	 * The four providers nest their message differently, so every known shape is
	 * probed before falling back to a generic string.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $decoded Decoded error body.
	 * @return string
	 */
	private static function extract_message( array $decoded ): string {
		if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
			return $decoded['error']['message'];
		}

		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			return $decoded['error'];
		}

		if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			return $decoded['message'];
		}

		return __( 'no further detail supplied.', 'autoscribe' );
	}
}
