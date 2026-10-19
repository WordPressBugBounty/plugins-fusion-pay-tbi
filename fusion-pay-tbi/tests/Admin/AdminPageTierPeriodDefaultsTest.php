<?php

namespace FusionPayTbi\Tests\Admin;

use FusionPayTbi\Admin\AdminPage;
use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Tests\TestCase;

/**
 * Regression test for the "Button period" tier-default fix on the fresh-install
 * path (option not yet saved) in AdminPage::render() (src/Admin/AdminPage.php
 * ~lines 65-67).
 *
 * Before the fix, tier1/tier2/tier3 defaulted to
 * $config->getMinMonths()/getMinMonths()/getMaxMonths() (3/3/48 for
 * BulgariaConfig). That was wrong and inconsistent with the last-tier "High"
 * bounds clamping already used elsewhere (SettingsSaver::collectTierPeriods()/
 * saveFormFields()): the last tier should clamp with getMin/MaxMonthsHigh(),
 * the others with getMin/MaxMonths().
 *
 * After the fix, tier1/tier2/tier3 default to
 * $config->getMaxMonths()/getMaxMonths()/getMaxMonthsHigh() -- 48/48/60 for
 * BulgariaConfig (the active config here since 'woocommerce_default_country'
 * is unset, which CountryDetector::detect() resolves to 'BG').
 *
 * Scope boundary: AdminPage::render() is exercised end-to-end (it is not
 * refactored into a smaller testable unit); the assertions only look at the
 * three "Button period" input values it renders, not the rest of the settings
 * page markup.
 */
final class AdminPageTierPeriodDefaultsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        // Real singleton; reset so each test starts from a fresh BulgariaConfig
        // (default country when 'woocommerce_default_country' is unset).
        CountryRegistry::reset();
    }

    protected function tearDown(): void {
        CountryRegistry::reset();
        parent::tearDown();
    }

    public function test_tier_periods_default_to_max_months_and_max_months_high_when_options_unset(): void {
        // fusipatb_tier1 / fusipatb_tier2 / fusipatb_tier3 are intentionally
        // left unset -- this is the fresh-install scenario the fix targets.
        // fusipatb_tier_count is also unset (0), so render() takes the legacy
        // 3-tier ($tier1/$tier2/$tier3) branch rather than the dynamic-tiers one.

        ob_start();
        AdminPage::render();
        $output = ob_get_clean();

        // BulgariaConfig::getMaxMonths() === 48
        $this->assertStringContainsString(
            'name="button_tier1" class="tbi_field_half" value="48"',
            $output,
            'Tier 1 period must default to getMaxMonths() (48), not getMinMonths() (3).'
        );
        // BulgariaConfig::getMaxMonths() === 48
        $this->assertStringContainsString(
            'name="button_tier2" class="tbi_field_half" value="48"',
            $output,
            'Tier 2 period must default to getMaxMonths() (48), not getMinMonths() (3).'
        );
        // BulgariaConfig::getMaxMonthsHigh() === 60
        $this->assertStringContainsString(
            'name="button_tier3" class="tbi_field_half" value="60"',
            $output,
            'Tier 3 period must default to getMaxMonthsHigh() (60), not getMaxMonths() (48).'
        );
    }

    public function test_tier_periods_use_saved_options_when_already_set(): void {
        // Once the merchant has saved settings, the stored values must win over
        // any default -- this locks in that the fix only changes the *default*,
        // not the precedence of an already-set option.
        update_option( 'fusipatb_tier1', 6 );
        update_option( 'fusipatb_tier2', 12 );
        update_option( 'fusipatb_tier3', 24 );

        ob_start();
        AdminPage::render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'name="button_tier1" class="tbi_field_half" value="6"', $output );
        $this->assertStringContainsString( 'name="button_tier2" class="tbi_field_half" value="12"', $output );
        $this->assertStringContainsString( 'name="button_tier3" class="tbi_field_half" value="24"', $output );
    }

    public function test_status_ping_consent_checkbox_has_short_label_and_separate_description(): void {
        // Regression/characterization test: the fusipatb_status_ping_consent
        // checkbox block was changed from one combined <label> string into a
        // short <label> plus a separate <p class="description"> helper text.
        ob_start();
        AdminPage::render();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            '<label for="tbi_status_ping_consent">Share plugin status with tbi bank</label>',
            $output,
            'The status-ping consent checkbox must keep the short label copy.'
        );

        $this->assertMatchesRegularExpression(
            '/<p class="description">Allow Fusion Pay to notify tbi bank when this plugin is activated or deactivated\. This helps us support your integration and monitor its status\.<\/p>/',
            $output,
            'The status-ping consent helper text must be in its own <p class="description"> element.'
        );
    }
}
