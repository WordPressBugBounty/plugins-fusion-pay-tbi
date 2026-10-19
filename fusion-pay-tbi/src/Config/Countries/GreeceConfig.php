<?php

namespace FusionPayTbi\Config\Countries;

use FusionPayTbi\Config\AbstractCountryConfig;
use FusionPayTbi\Config\FeatureFlags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Greece country config stub. Values are placeholders – fill in when GR goes live.
 */
class GreeceConfig extends AbstractCountryConfig {

    public function getCountryCode() {
        return 'GR';
    }

    public function getCurrency() {
        return 'EUR';
    }

    public function getPlatformUrl() {
        return 'https://gr.tbibank.support'; // placeholder
    }

    public function getCalcUrlPath() {
        return '/calculator/'; // placeholder
    }

    public function getApiSettingsPath() {
        return '/api/PluginsSettings'; // placeholder
    }

    public function getApiStatusPath() {
        return '/api/PluginsSettings/status'; // placeholder
    }

    public function getMinAmount() {
        return 100;
    }

    public function getMaxAmount() {
        return 30000;
    }

    public function getMinMonths() {
        return 3;
    }

    public function getMaxMonths() {
        return 48;
    }

    public function getMinMonthsHigh() {
        return 12;
    }

    public function getMaxMonthsHigh() {
        return 60;
    }

    public function getShippingName() {
        return 'Delivery'; // placeholder
    }

    public function getCouponName() {
        return 'Discount'; // placeholder
    }

    public function getRenderButtonPath( $is_test = null ) {
        // Placeholder – update when GR goes live.
        if ( $is_test === null ) {
            $is_test = defined( 'FUSIPATB_TEST_ENV' ) && FUSIPATB_TEST_ENV;
        }
        if ( $is_test ) {
            return '/admin-0071/merchant_settings/render-button';
        }
        return '/admin-30cffb5df303275a2c4ce4fa7d19c8ed/merchant_settings/render-button';
    }

    public function getBankLogo() {
        return 'logo/tbi-bank.svg';
    }

    public function getDefaultGatewayTitle() {
        return __( 'In installments with tbi bank', 'fusion-pay-tbi' );
    }

    public function getStatusMap() {
        return array(
            'Draft' => array( 'label' => __( 'Draft', 'fusion-pay-tbi' ), 'class' => 'status-pending' ),
        );
    }

    public function getFeatureFlags() {
        // All disabled until GR is configured
        return array(
            FeatureFlags::ADVERTISING              => false,
            FeatureFlags::DUAL_CURRENCY            => false,
            FeatureFlags::RENDER_BUTTON_API        => false,
            FeatureFlags::INSTALLMENT_AJAX         => false,
            FeatureFlags::SESSION_DATA_AJAX        => false,
            FeatureFlags::IP_WHITELIST             => false,
            FeatureFlags::WC_BUNDLES_COMPAT        => false,
            FeatureFlags::SEQUENTIAL_ORDERS        => false,
            FeatureFlags::WCPA_SUPPORT             => false,
            FeatureFlags::WAPF_SUPPORT             => false,
            FeatureFlags::ANALYSIS_FEES            => false,
            FeatureFlags::DYNAMIC_TIERS            => false,
            FeatureFlags::UAT_SUPPORT              => false,
            FeatureFlags::EXTENDED_BUTTON_VARIANTS => false,
        );
    }
}



