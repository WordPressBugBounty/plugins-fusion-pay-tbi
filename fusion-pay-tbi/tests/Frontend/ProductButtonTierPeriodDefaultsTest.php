<?php

namespace FusionPayTbi\Tests\Frontend;

use FusionPayTbi\Frontend\ProductButton;
use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Tests\TestCase;

/**
 * Regression test for the "Button period" tier-default fix on the BG-legacy
 * 3-tier fallback array in ProductButton::render() (src/Frontend/ProductButton.php
 * ~lines 106-121), reached when 'fusipatb_tier_count' is unset (fresh install,
 * dynamic RO tiers never configured).
 *
 * Before the fix, tier3 in this array defaulted its period to
 * $config->getMaxMonths() (same as tier1/tier2) instead of
 * $config->getMaxMonthsHigh(). After the fix all three resolve to 48/48/60
 * for BulgariaConfig (getMaxMonths()=48, getMaxMonthsHigh()=60), matching
 * AdminPage's and CartButton's fallback.
 *
 * The three periods are surfaced unconditionally as data-t1/data-t2/data-t3
 * attributes (built directly from the $tiers_data array), independent of
 * which tier the price-based label calculation actually lands on -- so a
 * single render() call with a price above the visible-amount threshold is
 * enough to observe all three defaults at once.
 */
final class ProductButtonTierPeriodDefaultsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        CountryRegistry::reset();
        $GLOBALS['__fusipatb_test_is_product'] = true;

        // Gates in ProductButton::render() that must be satisfied to reach the
        // tier defaulting code and the final echo.
        update_option( 'fusipatb_checkout_status', 1 );

        // A simple, non-grouped, non-variable, non-bundle product priced well
        // above BulgariaConfig::getMinAmount() (100) so the button isn't
        // suppressed by the visible-amount/loan-max gates.
        $GLOBALS['product'] = new \FusipatbTestWcProduct( 'simple', 150.0 );
    }

    protected function tearDown(): void {
        CountryRegistry::reset();
        unset( $GLOBALS['product'] );
        unset( $GLOBALS['__fusipatb_test_is_product'] );
        parent::tearDown();
    }

    public function test_tier_periods_default_to_max_months_and_max_months_high_when_options_unset(): void {
        // fusipatb_tier1 / fusipatb_tier2 / fusipatb_tier3 and
        // fusipatb_tier_count are all intentionally left unset.

        ob_start();
        ProductButton::render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'data-t1="48"', $output, 'Tier 1 period must default to getMaxMonths() (48).' );
        $this->assertStringContainsString( 'data-t2="48"', $output, 'Tier 2 period must default to getMaxMonths() (48).' );
        $this->assertStringContainsString( 'data-t3="60"', $output, 'Tier 3 period must default to getMaxMonthsHigh() (60), not getMaxMonths() (48).' );
    }

    public function test_tier_periods_use_saved_options_when_already_set(): void {
        update_option( 'fusipatb_tier1', 6 );
        update_option( 'fusipatb_tier2', 12 );
        update_option( 'fusipatb_tier3', 24 );

        ob_start();
        ProductButton::render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'data-t1="6"', $output );
        $this->assertStringContainsString( 'data-t2="12"', $output );
        $this->assertStringContainsString( 'data-t3="24"', $output );
    }
}
