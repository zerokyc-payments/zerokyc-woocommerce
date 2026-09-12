<?php
/**
 * @package zerokyc-pay
 */

use ZeroKYC\Exception\NetworkException;

class HttpClientTest extends WP_UnitTestCase {

	public function test_maps_wp_response_to_sdk_response(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array( 'Content-Type' => 'application/json', 'Idempotent-Replay' => 'true' ),
					'body'     => '{"ok":true}',
					'response' => array( 'code' => 201, 'message' => 'Created' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$client = new ZKP_Http_Client();
		$response = $client->request( 'POST', 'https://api.example.test/v1/invoices', array( 'Authorization' => 'Bearer x' ), '{}', 5.0 );

		$this->assertSame( 201, $response->status );
		$this->assertSame( '{"ok":true}', $response->body );
		$this->assertSame( array( 'ok' => true ), $response->json() );
		$this->assertSame( 'application/json', $response->header( 'content-type' ) );
		$this->assertSame( 'true', $response->header( 'IDEMPOTENT-REPLAY' ) );
	}

	public function test_wp_error_becomes_network_exception(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_timeout', 'cURL error 28: Operation timed out.' );
			}
		);

		$client = new ZKP_Http_Client();

		$this->expectException( NetworkException::class );
		$this->expectExceptionMessage( 'http_request_timeout' );

		$client->request( 'GET', 'https://api.example.test/v1/ping' );
	}

	public function test_http_error_status_is_returned_not_thrown(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '{"error":{"code":"auth_failed","message":"bad key"}}',
					'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$client  = new ZKP_Http_Client();
		$response = $client->request( 'GET', 'https://api.example.test/v1/ping' );

		$this->assertSame( 401, $response->status );
		$this->assertSame( 'bad key', $response->json()['error']['message'] );
	}

	public function test_default_timeout_is_fifteen_seconds(): void {
		$captured = null;
		add_filter(
			'pre_http_request',
			static function ( $short, array $args ) use ( &$captured ) {
				$captured = $args;
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		( new ZKP_Http_Client() )->request( 'GET', 'https://api.example.test/v1/ping' );

		$this->assertSame( 15.0, (float) $captured['timeout'] );
		$this->assertSame( 0, (int) $captured['redirection'] );
	}
}
