<?php
/**
 * SDK HTTP transport backed by the WordPress HTTP API.
 *
 * WordPress.org plugin guidelines require plugin code to use wp_remote_*()
 * instead of raw cURL; the vendored SDK accepts any HttpClientInterface.
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

use ZeroKYC\Exception\NetworkException;
use ZeroKYC\Http\HttpClientInterface;
use ZeroKYC\Http\Response;

final class ZKP_Http_Client implements HttpClientInterface {

	/**
	 * @inheritDoc
	 */
	public function request(
		string $method,
		string $url,
		array $headers = array(),
		?string $body = null,
		?float $timeout = null,
	): Response {
		$wp_headers = array();
		foreach ( $headers as $name => $value ) {
			$wp_headers[ (string) $name ] = (string) $value;
		}

		$result = wp_remote_request(
			$url,
			array(
				'method'      => strtoupper( $method ),
				'headers'     => $wp_headers,
				'body'        => $body,
				'timeout'     => (float) ( $timeout ?? 15.0 ),
				'redirection' => 0, // signed request/response semantics; never follow
				'blocking'    => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			// Not user-facing output: the message only reaches logs and caught
			// exceptions, so HTML escaping would corrupt it.
			throw new NetworkException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal error string, never rendered
				sprintf( 'wp_remote failure (%s): %s', $result->get_error_code(), $result->get_error_message() )
			);
		}

		/** @var array<string,mixed> $result */
		return new Response(
			(int) wp_remote_retrieve_response_code( $result ),
			(string) wp_remote_retrieve_body( $result ),
			self::flatten_headers( (array) wp_remote_retrieve_headers( $result ) ),
		);
	}

	/**
	 * Wp_Http returns header objects (case-insensitive dictionaries) on some
	 * transports and plain arrays on others; normalize to a string map.
	 *
	 * @param array<string,mixed>|object $raw
	 * @return array<string,string>
	 */
	private static function flatten_headers( $raw ): array {
		$flat = array();
		foreach ( (array) $raw as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$flat[ (string) $name ] = (string) $value;
		}
		return $flat;
	}
}
