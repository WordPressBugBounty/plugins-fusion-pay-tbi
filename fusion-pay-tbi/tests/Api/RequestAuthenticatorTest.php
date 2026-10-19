<?php

namespace FusionPayTbi\Tests\Api;

use FusionPayTbi\Api\RequestAuthenticator;
use FusionPayTbi\Tests\TestCase;

/**
 * Characterizes the signed-request permission_callback added to the
 * /api/tbi/v1/tbi-api-gateway REST route (CKT-3830 WooCoo review: Origin
 * header, IP allowlist and AES-CTR encryption alone do not authenticate the
 * calling system). RequestAuthenticator::authenticate() verifies an
 * HMAC-SHA256 signature over timestamp+nonce+body, derived from the existing
 * shared `fusipatb_encryption_key`, rejects requests outside a clock-skew window,
 * and rejects a nonce that has already been consumed (replay protection).
 */
final class RequestAuthenticatorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        update_option( 'fusipatb_encryption_key', 'sup3r-shared-secret' );
    }

    private function signingKey(): string {
        return hash_hmac( 'sha256', 'fusipatb-api-gateway-signature', 'sup3r-shared-secret' );
    }

    private function signedRequest( array $overrides = array() ): \WP_REST_Request {
        $timestamp = $overrides['timestamp'] ?? (string) time();
        $nonce     = $overrides['nonce'] ?? 'nonce-' . bin2hex( random_bytes( 8 ) );
        $body      = $overrides['body'] ?? '{"command":"create_order"}';
        $signature = $overrides['signature']
            ?? hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $this->signingKey() );

        return new \WP_REST_Request(
            array(
                'X-Tbi-Timestamp' => $timestamp,
                'X-Tbi-Nonce'     => $nonce,
                'X-Tbi-Signature' => $signature,
            ),
            $body
        );
    }

    public function test_authenticate_accepts_a_correctly_signed_fresh_request(): void {
        $result = RequestAuthenticator::authenticate( $this->signedRequest() );

        $this->assertTrue( $result );
    }

    public function test_authenticate_rejects_missing_timestamp_header(): void {
        $req = new \WP_REST_Request(
            array(
                'X-Tbi-Nonce'     => 'nonce-1',
                'X-Tbi-Signature' => 'irrelevant',
            ),
            '{}'
        );

        $result = RequestAuthenticator::authenticate( $req );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'fusipatb_auth_missing_timestamp', $result->get_error_code() );
    }

    public function test_authenticate_rejects_missing_nonce_header(): void {
        $req = new \WP_REST_Request(
            array(
                'X-Tbi-Timestamp' => (string) time(),
                'X-Tbi-Signature' => 'irrelevant',
            ),
            '{}'
        );

        $result = RequestAuthenticator::authenticate( $req );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'fusipatb_auth_missing_nonce', $result->get_error_code() );
    }

    public function test_authenticate_rejects_missing_signature_header(): void {
        $req = new \WP_REST_Request(
            array(
                'X-Tbi-Timestamp' => (string) time(),
                'X-Tbi-Nonce'     => 'nonce-12345',
            ),
            '{}'
        );

        $result = RequestAuthenticator::authenticate( $req );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'fusipatb_auth_missing_signature', $result->get_error_code() );
    }

    public function test_authenticate_rejects_a_timestamp_too_far_in_the_past(): void {
        $result = RequestAuthenticator::authenticate(
            $this->signedRequest( array( 'timestamp' => (string) ( time() - 301 ) ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'fusipatb_auth_stale_timestamp', $result->get_error_code() );
    }

    public function test_authenticate_rejects_a_timestamp_too_far_in_the_future(): void {
        $result = RequestAuthenticator::authenticate(
            $this->signedRequest( array( 'timestamp' => (string) ( time() + 301 ) ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'fusipatb_auth_stale_timestamp', $result->get_error_code() );
    }

    public function test_authenticate_rejects_a_tampered_body_even_with_a_valid_looking_signature(): void {
        $req = $this->signedRequest();
        // Simulate the ciphertext/body being altered after signing (the CTR
        // mode has no integrity check of its own) -- a fresh request built
        // with a mismatched body and the original signature.
        $tampered = new \WP_REST_Request(
            array(
                'X-Tbi-Timestamp' => $req->get_header( 'X-Tbi-Timestamp' ),
                'X-Tbi-Nonce'     => $req->get_header( 'X-Tbi-Nonce' ),
                'X-Tbi-Signature' => $req->get_header( 'X-Tbi-Signature' ),
            ),
            '{"command":"update_status","order_id":999999}'
        );

        $result = RequestAuthenticator::authenticate( $tampered );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'fusipatb_auth_invalid_signature', $result->get_error_code() );
    }

    public function test_authenticate_rejects_an_incorrect_signature(): void {
        $result = RequestAuthenticator::authenticate(
            $this->signedRequest( array( 'signature' => 'not-the-right-signature' ) )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'fusipatb_auth_invalid_signature', $result->get_error_code() );
    }

    public function test_authenticate_rejects_a_replayed_nonce_on_the_second_use(): void {
        $req = $this->signedRequest();

        $first  = RequestAuthenticator::authenticate( $req );
        $second = RequestAuthenticator::authenticate( $req );

        $this->assertTrue( $first );
        $this->assertInstanceOf( \WP_Error::class, $second );
        $this->assertSame( 'fusipatb_auth_replayed', $second->get_error_code() );
    }

    public function test_authenticate_rejects_when_no_shared_secret_is_configured(): void {
        delete_option( 'fusipatb_encryption_key' );

        $result = RequestAuthenticator::authenticate( $this->signedRequest() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'fusipatb_auth_not_configured', $result->get_error_code() );
    }
}
