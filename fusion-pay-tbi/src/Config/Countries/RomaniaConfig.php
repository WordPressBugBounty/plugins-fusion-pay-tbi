<?php

namespace FusionPayTbi\Config\Countries;

use FusionPayTbi\Config\AbstractCountryConfig;
use FusionPayTbi\Config\FeatureFlags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RomaniaConfig extends AbstractCountryConfig {

    public function getCountryCode() {
        return 'RO';
    }

    public function getCurrency() {
        return 'RON';
    }

    public function getPlatformUrl() {
        // Support FUSIPATB_PLATFORM_URL constant override (for UAT/custom deployments).
        if ( defined( 'FUSIPATB_PLATFORM_URL' ) && is_string( FUSIPATB_PLATFORM_URL ) && FUSIPATB_PLATFORM_URL !== '' ) {
            return FUSIPATB_PLATFORM_URL;
        }
        if ( defined( 'FUSIPATB_FORCE_UAT' ) && FUSIPATB_FORCE_UAT ) {
            return 'https://ro.tbi-uat.online';
        }
        return 'https://ro.tbibank.support';
    }

    public function getCalcUrlPath() {
        return '/calculator/';
    }

    public function getApiSettingsPath() {
        return '/api/PluginsSettings';
    }

    public function getApiStatusPath() {
        return '/api/PluginsSettings/status';
    }

    public function getMinAmount() {
        return 100;
    }

    public function getMaxAmount() {
        return 65000;
    }

    public function getMinMonths() {
        return 4;
    }

    public function getMaxMonths() {
        return 60;
    }

    public function getMinMonthsHigh() {
        return 4;
    }

    public function getMaxMonthsHigh() {
        return 60;
    }

    public function getShippingName() {
        return 'Livrare';
    }

    public function getCouponName() {
        return 'Reducere';
    }

    public function getRenderButtonPath( $is_test = null ) {
        if ( $is_test === null ) {
            $is_test = defined( 'FUSIPATB_TEST_ENV' ) && FUSIPATB_TEST_ENV;
        }
        if ( $is_test ) {
            return '/admin-0071/merchant_settings/render-button';
        }
        return '/admin-0071/merchant_settings/render-button';
    }

    public function getAllowedIp() {
        return '185.123.190.236';
    }

    public function getBankLogo() {
        return 'logo/tbi-bank-small.svg';
    }

    public function getDefaultGatewayTitle() {
        return 'In rate online, fara card de cumparaturi';
    }

    public function getStatusMap() {
        return array(
            '0' => array( 'label' => __( 'Canceled', 'fusion-pay-tbi' ),  'class' => 'status-failed' ),
            '1' => array( 'label' => __( 'Approved', 'fusion-pay-tbi' ),  'class' => 'status-completed' ),
            '2' => array( 'label' => __( 'Draft', 'fusion-pay-tbi' ),     'class' => 'status-pending' ),
            '3' => array( 'label' => __( 'Rejected', 'fusion-pay-tbi' ),  'class' => 'status-failed' ),
        );
    }

    public function getFeatureFlags() {
        return array(
            FeatureFlags::ADVERTISING              => true,
            FeatureFlags::DUAL_CURRENCY            => false,
            FeatureFlags::RENDER_BUTTON_API        => true,
            FeatureFlags::INSTALLMENT_AJAX         => true,
            FeatureFlags::SESSION_DATA_AJAX        => true,
            FeatureFlags::IP_WHITELIST             => true,
            FeatureFlags::WC_BUNDLES_COMPAT        => true,
            FeatureFlags::SEQUENTIAL_ORDERS        => true,
            FeatureFlags::WCPA_SUPPORT             => true,
            FeatureFlags::WAPF_SUPPORT             => true,
            FeatureFlags::ANALYSIS_FEES            => true,
            FeatureFlags::DYNAMIC_TIERS            => true,
            FeatureFlags::UAT_SUPPORT              => true,
            FeatureFlags::EXTENDED_BUTTON_VARIANTS => true,
        );
    }
}



