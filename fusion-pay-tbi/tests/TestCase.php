<?php

namespace FusionPayTbi\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Shared setup for the fusion-pay-tbi suite: resets superglobals and the fake
 * option/WC-session stores before every test so tests never leak state into
 * each other regardless of run order.
 */
abstract class TestCase extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();

        $_POST   = array();
        $_GET    = array();
        $_SERVER = array();

        // Real WP global set by wp-admin/admin.php; reset so no test leaks a
        // screen value into the next one (see StatusPingConsentNoticeTest).
        $GLOBALS['pagenow'] = '';

        $GLOBALS['__fusipatb_test_options']    = array();
        $GLOBALS['__fusipatb_test_transients'] = array();
        $GLOBALS['__fusipatb_test_wc']         = new \FusipatbTestWc();
        $GLOBALS['__fusipatb_test_wc_orders']  = array();
        $GLOBALS['__fusipatb_test_post_meta']  = array();

        // Reset the controllable stubs added for the CKT-3830 round-2 fixes so
        // no test can leak a forced nonce/HTTP result into the next one.
        unset( $GLOBALS['__fusipatb_test_check_admin_referer_result'] );
        unset( $GLOBALS['__fusipatb_test_wp_remote_post_response'] );
        $GLOBALS['__fusipatb_test_wp_remote_post_calls'] = array();

        // Reset the controllable stubs added for the WP.org review follow-up
        // fixes (WooCommerce-inactive admin notice, Cryptor openssl guard) so
        // no test can leak a forced result into the next one.
        unset( $GLOBALS['__fusipatb_test_network_active_plugins'] );
        unset( $GLOBALS['__fusipatb_test_current_user_can_result'] );
        unset( $GLOBALS['__fusipatb_test_extension_loaded_override'] );
        $GLOBALS['__fusipatb_test_add_action_calls'] = array();
    }
}
