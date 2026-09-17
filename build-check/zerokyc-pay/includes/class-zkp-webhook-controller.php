<?php
/**
 * Public REST endpoint receiving signed ZeroKYC webhook deliveries.
 *
 * Route: POST /wp-json/zkp/v1/webhook (permission: the HMAC signature itself —
 * no WordPress auth is involved by design).
 *
 * @package zerokyc-pay
 */

defined( 'ABSPATH' ) || exit;

use ZeroKYC\Exception\WebhookVerificationException;
use ZeroKYC\Webhook\WebhookVerifier;

final class ZKP_Webhook_Controller {

	public const NAMESPACE_V1 = 'zkp/v1';
	public const ROUTE        = '/webhook';

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$settings = ZKP_Gateway::settings();
		$secret   = trim( (string) ( $settings['webhook_secret'] ?? '' ) );

		if ( '' === $secret ) {
			ZKP_Logger::alert( 'webhook received but no webhook_secret is configured' );
			return new WP_REST_Response(
				array( 'error' => 'webhook_secret_not_configured' ),
				503
			);
		}

		$raw_body  = (string) $request->get_body();
		$signature = (string) ( $request->get_header( 'X-Zkp-Signature' ) ?? '' );

		try {
			$event = ( new WebhookVerifier( $secret ) )->verify( $raw_body, $signature );
		} catch ( WebhookVerificationException $e ) {
			// Garbage must not be retried forever; genuine failures are
			// re-delivered by the platform after the reason is fixed.
			ZKP_Logger::warning( sprintf( 'webhook rejected (%s)', $e->reason ) );
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}

		if ( '' === $event->id ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		if ( ! ZKP_Event_Store::claim( $event->id, $event->type, (string) $event->invoiceId ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- SDK DTO property
			ZKP_Logger::debug( sprintf( 'webhook %s: duplicate delivery, skipped', $event->id ) );
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		ZKP_Order_Service::handle_event( $event );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
