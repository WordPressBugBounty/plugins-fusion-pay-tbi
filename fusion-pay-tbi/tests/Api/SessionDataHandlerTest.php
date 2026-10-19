<?php

namespace FusionPayTbi\Tests\Api;

use FusionPayTbi\Api\SessionDataHandler;
use FusionPayTbi\Tests\TestCase;
use WpAjaxResponseException;

/**
 * Characterizes the nonce gate added to SessionDataHandler::handle() for the
 * CKT-3830 Plugin Check security fixes (nonce action "fusipatb_set_session_data",
 * src/Api/SessionDataHandler.php lines 12-16), plus the pre-existing session-write
 * behaviour it gates access to.
 */
final class SessionDataHandlerTest extends TestCase {

    public function test_handle_rejects_request_with_no_nonce_field(): void {
        try {
            SessionDataHandler::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
            $this->assertSame( 403, $e->status );
        }

        // The WooCommerce session must not have been touched by a rejected request.
        $this->assertNull( WC()->session->get( 'fusipatb_scheme_id' ) );
        $this->assertNull( WC()->session->get( 'fusipatb_downpayment' ) );
    }

    public function test_handle_rejects_request_with_invalid_nonce(): void {
        $_POST['_wpnonce']  = 'not-the-right-nonce';
        $_POST['scheme_id'] = '42';

        try {
            SessionDataHandler::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
            $this->assertSame( 403, $e->status );
        }

        $this->assertNull( WC()->session->get( 'fusipatb_scheme_id' ), 'A rejected request must not write to the session.' );
    }

    public function test_handle_valid_nonce_stores_posted_scheme_id_and_downpayment(): void {
        $_POST['_wpnonce']    = 'valid-nonce-for-fusipatb_set_session_data';
        $_POST['scheme_id']   = '42';
        $_POST['downpayment'] = '150.50';

        try {
            SessionDataHandler::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'success', $e->type );
            $this->assertSame( array( 'message' => 'Session updated' ), $e->data );
        }

        $this->assertSame( '42', WC()->session->get( 'fusipatb_scheme_id' ) );
        $this->assertSame( '150.50', WC()->session->get( 'fusipatb_downpayment' ) );
    }

    public function test_handle_valid_nonce_without_scheme_or_downpayment_still_succeeds(): void {
        $_POST['_wpnonce'] = 'valid-nonce-for-fusipatb_set_session_data';

        try {
            SessionDataHandler::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'success', $e->type );
            $this->assertSame( array( 'message' => 'Session updated' ), $e->data );
        }

        $this->assertNull( WC()->session->get( 'fusipatb_scheme_id' ) );
        $this->assertNull( WC()->session->get( 'fusipatb_downpayment' ) );
    }
}
