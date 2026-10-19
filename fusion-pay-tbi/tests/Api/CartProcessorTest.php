<?php

namespace FusionPayTbi\Tests\Api;

use FusionPayTbi\Api\CartProcessor;
use FusionPayTbi\Tests\TestCase;
use WpAjaxResponseException;

/**
 * Characterizes the nonce gate added to CartProcessor::handle() for the
 * CKT-3830 Plugin Check security fixes. The nonce action string, error
 * message, and 403 status all come directly from the source (see
 * src/Api/CartProcessor.php lines 24-29).
 *
 * Scope boundary: these tests only prove a valid nonce lets execution reach
 * the *next* gate (SecurityHelper::isLocalOriginAllowed()) — they deliberately
 * do not exercise the full happy path (WC cart, CountryRegistry, Cryptor,
 * wp_remote_post), which is unchanged by this diff and out of scope for this
 * minimal WP stub harness.
 */
final class CartProcessorTest extends TestCase {

    public function test_rejects_request_with_no_nonce_field(): void {
        // $_POST['_wpnonce'] intentionally absent.
        try {
            CartProcessor::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
            $this->assertSame( 403, $e->status );
        }
    }

    public function test_rejects_request_with_nonce_that_fails_verification(): void {
        $_POST['_wpnonce'] = 'this-does-not-verify';

        try {
            CartProcessor::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
            $this->assertSame( 403, $e->status );
        }
    }

    public function test_rejects_request_with_empty_string_nonce(): void {
        // isset() is true but the nonce guard also checks `! $nonce`.
        $_POST['_wpnonce'] = '';

        try {
            CartProcessor::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
            $this->assertSame( 403, $e->status );
        }
    }

    public function test_valid_nonce_passes_the_nonce_gate_and_reaches_the_origin_check(): void {
        $_POST['_wpnonce'] = 'valid-nonce-for-fusipatb_process_cart';
        // No HTTP_ORIGIN set -> SecurityHelper::isLocalOriginAllowed() returns false,
        // which is the *next* gate CartProcessor::handle() runs after the nonce check.
        // Reaching "Access denied" (not "Security verification failed") proves the
        // nonce check let this request through.

        try {
            CartProcessor::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( array( 'message' => 'Access denied' ), $e->data );
            $this->assertSame( 403, $e->status );
        }
    }
}
