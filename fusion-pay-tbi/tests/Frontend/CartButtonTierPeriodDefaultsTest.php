<?php

namespace FusionPayTbi\Tests\Frontend;

use FusionPayTbi\Frontend\CartButton;
use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Tests\TestCase;

/**
 * Regression test for the "Button period" tier-default fix, which appears
 * twice in src/Frontend/CartButton.php in near-identical BG-legacy 3-tier
 * fallback arrays: CartButton::render() (~lines 84-86) and
 * CartButton::getBlocksData() (~lines 472-474). Both are covered here since
 * they are independently reachable public entry points.
 *
 * Unlike AdminPage/ProductButton, the resolved tier period is not exposed
 * directly as a data attribute here -- it only becomes observable once the
 * cart total exceeds the (unset -> 0) BNPL max, at which point CartButton
 * walks the tiers array and the matching tier's period becomes the
 * "{installments}" value substituted into the button label. So each tier's
 * default is exercised by choosing a cart total that lands in that tier's
 * bucket (tier1's cap and tier2's cap are pushed out via
 * fusipatb_tier1_limit/fusipatb_tier2_limit so the price falls through to the
 * next tier). fusipatb_euro_multiplier is set so the label template used in
 * this branch contains the "{installments}" placeholder at all (the eur=0
 * template for this branch never uses it).
 */
final class CartButtonTierPeriodDefaultsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        CountryRegistry::reset();

        update_option( 'fusipatb_checkout_status', 1 );
        // Makes the resolved installments count observable in the label (see
        // class docblock) without changing the currency (still BGN) or any of
        // the tier-limit comparisons under test.
        update_option( 'fusipatb_euro_multiplier', 1.95583 );
    }

    protected function tearDown(): void {
        CountryRegistry::reset();
        $GLOBALS['__fusipatb_test_wc'] = new \FusipatbTestWc();
        parent::tearDown();
    }

    /**
     * @param float $price Both the cart's displayed total (get_total('edit'))
     *                      and the single cart item's price -- kept equal so
     *                      the tier-bucket amount and the visible-amount/
     *                      cart_total gate use the same figure.
     */
    private function setUpCart( float $price ): void {
        $item = array(
            'data'         => new \FusipatbTestWcProduct( 'simple', $price ),
            'quantity'     => 1,
            'product_id'   => 1,
            'variation_id' => 0,
        );

        $cart                       = new \FusipatbTestWcCart( 0.0, 0.0, 0.0, 0.0, array( $item ) );
        $cart->cart_contents_total  = $price;
        $cart->cart_contents_tax    = 0.0;
        $cart->total                = $price;

        $GLOBALS['__fusipatb_test_wc']->cart = $cart;
    }

    // -----------------------------------------------------------------
    // render()
    // -----------------------------------------------------------------

    public function test_render_tier1_period_defaults_to_max_months(): void {
        // fusipatb_tier1_limit unset (0 => "no cap") means the very first
        // tier bucket always matches once price > 0.
        $this->setUpCart( 150.0 );

        ob_start();
        CartButton::render();
        $output = ob_get_clean();

        $this->assertStringContainsString( '48 installments of', $output, 'Tier 1 period must default to getMaxMonths() (48).' );
    }

    public function test_render_tier2_period_defaults_to_max_months(): void {
        // Push the price past tier1's cap so the loop falls through to tier2,
        // whose limit is left unset (0 => "no cap", so it matches next).
        update_option( 'fusipatb_tier1_limit', 100 );
        $this->setUpCart( 150.0 );

        ob_start();
        CartButton::render();
        $output = ob_get_clean();

        $this->assertStringContainsString( '48 installments of', $output, 'Tier 2 period must default to getMaxMonths() (48).' );
    }

    public function test_render_tier3_period_defaults_to_max_months_high(): void {
        // Push the price past both tier1's and tier2's caps so the loop falls
        // through to tier3, whose limit is left unset (0 => "no cap").
        update_option( 'fusipatb_tier1_limit', 100 );
        update_option( 'fusipatb_tier2_limit', 200 );
        $this->setUpCart( 300.0 );

        ob_start();
        CartButton::render();
        $output = ob_get_clean();

        $this->assertStringContainsString( '60 installments of', $output, 'Tier 3 period must default to getMaxMonthsHigh() (60), not getMaxMonths() (48).' );
    }

    // -----------------------------------------------------------------
    // getBlocksData()
    // -----------------------------------------------------------------

    public function test_blocks_data_tier1_period_defaults_to_max_months(): void {
        $this->setUpCart( 150.0 );

        $result = CartButton::getBlocksData();

        $this->assertTrue( $result['enabled'] );
        $this->assertStringContainsString( '48 installments of', $result['label'], 'Tier 1 period must default to getMaxMonths() (48).' );
    }

    public function test_blocks_data_tier2_period_defaults_to_max_months(): void {
        update_option( 'fusipatb_tier1_limit', 100 );
        $this->setUpCart( 150.0 );

        $result = CartButton::getBlocksData();

        $this->assertTrue( $result['enabled'] );
        $this->assertStringContainsString( '48 installments of', $result['label'], 'Tier 2 period must default to getMaxMonths() (48).' );
    }

    public function test_blocks_data_tier3_period_defaults_to_max_months_high(): void {
        update_option( 'fusipatb_tier1_limit', 100 );
        update_option( 'fusipatb_tier2_limit', 200 );
        $this->setUpCart( 300.0 );

        $result = CartButton::getBlocksData();

        $this->assertTrue( $result['enabled'] );
        $this->assertStringContainsString( '60 installments of', $result['label'], 'Tier 3 period must default to getMaxMonthsHigh() (60), not getMaxMonths() (48).' );
    }
}
