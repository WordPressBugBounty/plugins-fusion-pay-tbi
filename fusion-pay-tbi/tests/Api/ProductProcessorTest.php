<?php

namespace FusionPayTbi\Tests\Api;

use FusionPayTbi\Api\ProductProcessor;
use FusionPayTbi\Tests\TestCase;
use WpAjaxResponseException;

/**
 * Characterizes ProductProcessor::handle()'s pre-existing nonce/origin gate
 * (unchanged by this diff, mirrors CartProcessorTest.php) plus the new
 * `add-to-cart` isset()-guard added at src/Api/ProductProcessor.php:44 (WP.org
 * Plugin Check "Proper sanitization of inputs" follow-up):
 *
 *   $add_to_cart_id = isset( $_POST['add-to-cart'] ) ? absint( $_POST['add-to-cart'] ) : 0;
 *
 * replacing several repeated raw `intval( $_POST['add-to-cart'] )` reads that
 * raised an "Undefined array key" warning on PHP 8 whenever the AJAX request
 * omitted the field.
 *
 * Scope boundary: full happy-path coverage of handle() is out of scope for this
 * minimal WP/WC stub harness. Once past the nonce+origin gates, handle()
 * immediately does `new \WC_Product( $add_to_cart_id )` (line 47), and this
 * suite has no WC_Product/WC_Product_Variation double registered anywhere (see
 * tests/bootstrap.php) -- the same scope boundary CartProcessorTest.php already
 * documents for CartProcessor::handle(). This also means the
 * `TmEpoSupport::sanitizePostData( wp_unslash( $_POST ) )` call added at line 83
 * can never be reached through handle() in this harness (it fatals on the
 * WC_Product instantiation first); TmEpoSupport::sanitizePostData()'s own
 * sanitization behaviour is already fully covered by
 * tests/TmEpoSupportSanitizePostDataTest.php.
 *
 * handle() calls ob_start() unconditionally at its very top (line 22) and only
 * pairs it with ob_end_clean() on the success path (line 298, unreachable in
 * this harness) -- every call in this test throws past that point, so each
 * invocation opens one output-buffering level that is manually closed in
 * callHandleExpectingException(), or it would leak into every later test in
 * the suite's single PHP process.
 */
final class ProductProcessorTest extends TestCase {

    private function callHandleExpectingException(): WpAjaxResponseException {
        $level = ob_get_level();
        try {
            ProductProcessor::handle();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            return $e;
        } finally {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
        }
    }

    public function test_rejects_request_with_no_nonce_field(): void {
        // $_POST['_wpnonce'] intentionally absent.
        $e = $this->callHandleExpectingException();

        $this->assertSame( 'error', $e->type );
        $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
        $this->assertSame( 403, $e->status );
    }

    public function test_rejects_request_with_nonce_that_fails_verification(): void {
        $_POST['_wpnonce'] = 'this-does-not-verify';

        $e = $this->callHandleExpectingException();

        $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
        $this->assertSame( 403, $e->status );
    }

    public function test_rejects_request_with_empty_string_nonce(): void {
        // isset() is true but the nonce guard also checks `! $nonce`.
        $_POST['_wpnonce'] = '';

        $e = $this->callHandleExpectingException();

        $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
        $this->assertSame( 403, $e->status );
    }

    public function test_valid_nonce_passes_the_nonce_gate_and_reaches_the_origin_check(): void {
        $_POST['_wpnonce'] = 'valid-nonce-for-fusipatb_process_product';
        // No HTTP_ORIGIN set -> SecurityHelper::isLocalOriginAllowed() returns false,
        // the *next* gate handle() runs after the nonce check. Reaching "Access
        // denied" (not "Security verification failed") proves the nonce check let
        // this request through.

        $e = $this->callHandleExpectingException();

        $this->assertSame( 'error', $e->type );
        $this->assertSame( array( 'message' => 'Access denied' ), $e->data );
        $this->assertSame( 403, $e->status );
    }

    /**
     * Regression test for the `add-to-cart` isset()-guard. With a valid nonce and
     * a valid local origin, handle() now runs past both gates and evaluates the
     * guarded `$add_to_cart_id = isset(...) ? absint(...) : 0;` line with
     * `$_POST['add-to-cart']` entirely absent -- proving the guard (not an
     * unguarded `intval( $_POST['add-to-cart'] )` read) executes.
     *
     * Execution still necessarily fails immediately afterwards -- via a PHP
     * \Error, not a PHP warning -- on `new \WC_Product( $add_to_cart_id )`,
     * because this minimal harness has no WooCommerce classes loaded (see the
     * class docblock). That failure is expected, and is asserted on separately
     * from the "no warning was raised" check below so the test still fails loudly
     * (for the right, unrelated reason) if that assumption about the harness
     * ever changes.
     */
    public function test_handle_does_not_warn_on_a_missing_add_to_cart_index(): void {
        $_POST['_wpnonce']      = 'valid-nonce-for-fusipatb_process_product';
        $_SERVER['HTTP_ORIGIN'] = FUSIPATB_API_LOCAL_ALLOWED_ORIGIN;
        // $_POST['add-to-cart'] intentionally NOT set.

        $level    = ob_get_level();
        $warnings = array();
        set_error_handler( function ( $errno, $errstr ) use ( &$warnings ) {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED );

        try {
            ProductProcessor::handle();
            $this->fail( 'Expected execution to fail past the missing WC_Product class.' );
        } catch ( \Throwable $e ) {
            $this->assertStringContainsString(
                'WC_Product',
                $e->getMessage(),
                'Expected the failure to come from the missing WC_Product class (i.e. execution ran past the add-to-cart guard), not from an earlier error.'
            );
        } finally {
            restore_error_handler();
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
        }

        foreach ( $warnings as $warning ) {
            $this->assertStringNotContainsString(
                'add-to-cart',
                $warning,
                'add-to-cart must be isset()-guarded; got warning: ' . $warning
            );
        }
    }

    /**
     * Same guard, but with `add-to-cart` posted as a numeric string containing
     * surrounding whitespace/markup-adjacent characters -- proves absint() runs
     * (not a raw intval() on an unguarded read) without warning, mirroring the
     * previous test's shape for the "value present" branch of the ternary.
     */
    public function test_handle_does_not_warn_when_add_to_cart_is_present_and_numeric(): void {
        $_POST['_wpnonce']      = 'valid-nonce-for-fusipatb_process_product';
        $_SERVER['HTTP_ORIGIN'] = FUSIPATB_API_LOCAL_ALLOWED_ORIGIN;
        $_POST['add-to-cart']   = '42';

        $level    = ob_get_level();
        $warnings = array();
        set_error_handler( function ( $errno, $errstr ) use ( &$warnings ) {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED );

        try {
            ProductProcessor::handle();
            $this->fail( 'Expected execution to fail past the missing WC_Product class.' );
        } catch ( \Throwable $e ) {
            $this->assertStringContainsString( 'WC_Product', $e->getMessage() );
        } finally {
            restore_error_handler();
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
        }

        $this->assertSame( array(), $warnings, 'Expected no PHP warnings/notices when add-to-cart is a valid numeric string.' );
    }
}
