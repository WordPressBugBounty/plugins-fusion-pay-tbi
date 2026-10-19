<?php

namespace FusionPayTbi\Tests\Orders;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Orders\StatusColumn;
use FusionPayTbi\Tests\TestCase;

/**
 * Characterizes the order-meta fallback-read behaviour added for the
 * tbi_* -> fusipatb_* data-storage-key rename (order meta key
 * _tbi_checkout_status -> _fusipatb_checkout_status).
 *
 * New orders are written under the new key only (PaymentGateway/OrderCreator/
 * StatusUpdater). Historical orders were written under the legacy key and are
 * NOT bulk-migrated (HPOS vs legacy post storage makes that risky) — instead
 * every read checks the new key first and falls back to the legacy key only
 * when the new key is empty/missing, so old orders keep displaying correctly
 * forever with no migration job (see StatusColumn::output()/renderLegacy()).
 *
 * Status values used below ('Paid', 'Draft') are real Bulgaria status-map
 * keys (src/Config/Countries/BulgariaConfig.php::getStatusMap(), the default
 * country when 'woocommerce_default_country' is unset) so the rendered label
 * text is deterministic and distinguishable — an unrecognized status code
 * would silently fall back to the "Draft" label via outputStatus()'s default
 * branch, which would make these assertions meaningless.
 */
final class StatusColumnTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        // Real singleton; reset so each test resolves a fresh BulgariaConfig
        // (default country when 'woocommerce_default_country' is unset).
        CountryRegistry::reset();
    }

    protected function tearDown(): void {
        CountryRegistry::reset();
        parent::tearDown();
    }

    private function captureColumnOutput( callable $render ): string {
        ob_start();
        $render();
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // HPOS path: StatusColumn::renderHpos() -> output() -> $order->get_meta()
    // -------------------------------------------------------------------------

    public function test_hpos_uses_the_new_meta_key_when_present(): void {
        $GLOBALS['__fusipatb_test_wc_orders'][123] = new \WC_Order( array(
            '_fusipatb_checkout_status' => 'Paid',
            '_tbi_checkout_status'      => 'Draft',
        ) );

        $html = $this->captureColumnOutput( function () {
            StatusColumn::renderHpos( 'tbi_checkout_status', 123 );
        } );

        $this->assertStringContainsString( 'Paid', $html );
        $this->assertStringNotContainsString( 'Draft', $html );
    }

    public function test_hpos_falls_back_to_the_legacy_meta_key_when_new_key_is_empty(): void {
        $GLOBALS['__fusipatb_test_wc_orders'][124] = new \WC_Order( array(
            '_fusipatb_checkout_status' => '',
            '_tbi_checkout_status'      => 'Draft',
        ) );

        $html = $this->captureColumnOutput( function () {
            StatusColumn::renderHpos( 'tbi_checkout_status', 124 );
        } );

        $this->assertStringContainsString( 'Draft', $html );
    }

    public function test_hpos_falls_back_to_the_legacy_meta_key_when_new_key_is_missing(): void {
        // Historical order: only the legacy key was ever written.
        $GLOBALS['__fusipatb_test_wc_orders'][125] = new \WC_Order( array(
            '_tbi_checkout_status' => 'Paid',
        ) );

        $html = $this->captureColumnOutput( function () {
            StatusColumn::renderHpos( 'tbi_checkout_status', 125 );
        } );

        $this->assertStringContainsString( 'Paid', $html );
    }

    public function test_hpos_renders_nothing_when_neither_key_has_a_value(): void {
        $GLOBALS['__fusipatb_test_wc_orders'][126] = new \WC_Order( array() );

        $html = $this->captureColumnOutput( function () {
            StatusColumn::renderHpos( 'tbi_checkout_status', 126 );
        } );

        $this->assertSame( '', $html );
    }

    public function test_hpos_ignores_other_columns(): void {
        $GLOBALS['__fusipatb_test_wc_orders'][127] = new \WC_Order( array(
            '_fusipatb_checkout_status' => 'Paid',
        ) );

        $html = $this->captureColumnOutput( function () {
            StatusColumn::renderHpos( 'order_total', 127 );
        } );

        $this->assertSame( '', $html );
    }

    // -------------------------------------------------------------------------
    // Legacy (non-HPOS) path: StatusColumn::renderLegacy() -> get_post_meta()
    // fallback when $order->get_meta() itself is empty on both keys.
    // -------------------------------------------------------------------------

    public function test_legacy_prefers_the_new_meta_key_from_get_post_meta_over_the_old_one(): void {
        $GLOBALS['__fusipatb_test_wc_orders'][200] = new \WC_Order( array() );
        $GLOBALS['__fusipatb_test_post_meta'][200] = array(
            '_fusipatb_checkout_status' => 'Paid',
            '_tbi_checkout_status'      => 'Draft',
        );

        $html = $this->captureColumnOutput( function () {
            StatusColumn::renderLegacy( 'tbi_checkout_status', 200 );
        } );

        $this->assertStringContainsString( 'Paid', $html );
        $this->assertStringNotContainsString( 'Draft', $html );
    }

    public function test_legacy_falls_back_to_the_old_post_meta_key_for_a_historical_order(): void {
        $GLOBALS['__fusipatb_test_wc_orders'][201] = new \WC_Order( array() );
        $GLOBALS['__fusipatb_test_post_meta'][201] = array(
            '_tbi_checkout_status' => 'Paid',
        );

        $html = $this->captureColumnOutput( function () {
            StatusColumn::renderLegacy( 'tbi_checkout_status', 201 );
        } );

        $this->assertStringContainsString( 'Paid', $html );
    }
}
