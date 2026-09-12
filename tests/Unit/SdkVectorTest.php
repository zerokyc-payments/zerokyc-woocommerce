<?php
/**
 * SDK-level invariants the plugin relies on, checked without WordPress.
 *
 * @package zerokyc-pay
 */

use ZeroKYC\Idempotency\IdempotencyKey;
use ZeroKYC\Support\Money;
use ZeroKYC\Webhook\WebhookVerifier;

class SdkVectorTest extends WP_UnitTestCase {

	public function test_documented_webhook_verification_vector(): void {
		$verifier = new WebhookVerifier( 'whsec_zkp_test_vector_2026' );
		$event    = $verifier->verify(
			'{"id":"evt_test_001","type":"payment.confirmed","invoice_id":"inv_test_001"}',
			't=1788788073,v1=ade537fa13aec79a6d1648bd7f197872066c161676c389243ab5c6b13fea7f52',
			now: 1788788073,
		);

		$this->assertSame( 'evt_test_001', $event->id );
		$this->assertTrue( $event->isPaymentConfirmed() );
	}

	public function test_sign_then_verify_roundtrip(): void {
		$verifier  = new WebhookVerifier( 'whsec_roundtrip' );
		$body      = '{"id":"evt_rt","type":"payment.underpaid","invoice_id":"inv_rt"}';
		$signature = $verifier->sign( $body, 1700000000 );

		$event = $verifier->verify( $body, $signature, now: 1700000000 );
		$this->assertSame( 'payment.underpaid', $event->type );
	}

	public function test_idempotency_key_shape_and_limit(): void {
		$this->assertSame( 'zerokyc:woocommerce:order:1042:1', IdempotencyKey::make( 'woocommerce', 'order', '1042:1' ) );

		$too_long = str_repeat( 'a', 200 );
		$this->expectException( InvalidArgumentException::class );
		IdempotencyKey::make( 'x', 'y', $too_long );
	}

	public function test_money_comparison_semantics(): void {
		$this->assertTrue( Money::greaterOrEqual( '20.000001', '20.0' ) );
		$this->assertTrue( Money::greaterOrEqual( '20', '20.0' ) );
		$this->assertFalse( Money::greaterOrEqual( '19.999999', '20.0' ) );
		$this->assertTrue( Money::isValid( '0.01' ) );
		$this->assertFalse( Money::isValid( '-1' ) );
	}
}
