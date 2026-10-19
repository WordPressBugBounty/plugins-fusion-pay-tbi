<?php

namespace FusionPayTbi\Config\Countries;

use FusionPayTbi\Config\AbstractCountryConfig;
use FusionPayTbi\Config\FeatureFlags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BulgariaConfig extends AbstractCountryConfig {

    public function getCountryCode() {
        return 'BG';
    }

    public function getCurrency() {
        return 'BGN';
    }

    public function getSecondaryCurrency() {
        return 'EUR';
    }

    public function getPlatformUrl() {
        // Support FUSIPATB_PLATFORM_URL constant override (for UAT/custom deployments).
        if ( defined( 'FUSIPATB_PLATFORM_URL' ) && is_string( FUSIPATB_PLATFORM_URL ) && FUSIPATB_PLATFORM_URL !== '' ) {
            return FUSIPATB_PLATFORM_URL;
        }
        if ( defined( 'FUSIPATB_FORCE_UAT' ) && FUSIPATB_FORCE_UAT ) {
            return 'https://tbi-uat.online';
        }
        return 'https://beta.tbibank.support';
    }

    public function getRenderButtonPath( $is_test = null ) {
        if ( $is_test === null ) {
            $is_test = defined( 'FUSIPATB_TEST_ENV' ) && FUSIPATB_TEST_ENV;
        }
        if ( $is_test ) {
            return '/admin-0071/merchant_settings/render-button';
        }
        return '/admin-30cffb5df303275a2c4ce4fa7d19c8ed/merchant_settings/render-button';
    }

    public function getCalcUrlPath() {
        return '/application/calculcator/';
    }

    public function getApiSettingsPath() {
        return '/api/WooSettings2';
    }

    public function getApiStatusPath() {
        return '/api/PluginStatusUpdate';
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
        return 'Доставка';
    }

    public function getCouponName() {
        return 'Отстъпка';
    }

    public function getBankLogo() {
        return 'logo/tbi-bank.svg';
    }

    public function getDefaultGatewayTitle() {
        return __( 'In installments with tbi bank', 'fusion-pay-tbi' );
    }

    public function getStatusMap() {
        return array(
            'Draft'             => array( 'label' => __( 'Draft', 'fusion-pay-tbi' ),                    'class' => 'status-pending' ),
            'MP Delayed'        => array( 'label' => __( 'Awaits confirmation', 'fusion-pay-tbi' ),       'class' => 'status-pending' ),
            'Sent MP'           => array( 'label' => __( 'Sent in Merchant Portal', 'fusion-pay-tbi' ),   'class' => 'status-pending' ),
            'Sent BNPL'         => array( 'label' => __( 'Sent BNPL', 'fusion-pay-tbi' ),                 'class' => 'status-pending' ),
            'InProgress'        => array( 'label' => __( 'In Progress', 'fusion-pay-tbi' ),               'class' => 'status-pending' ),
            'Canceled'          => array( 'label' => __( 'Canceled', 'fusion-pay-tbi' ),                  'class' => 'status-failed' ),
            'Rejected'          => array( 'label' => __( 'Rejected', 'fusion-pay-tbi' ),                  'class' => 'status-failed' ),
            'Aproved and Signed' => array( 'label' => __( 'Contract signed', 'fusion-pay-tbi' ),          'class' => 'status-completed' ),
            'Paid'              => array( 'label' => __( 'Paid', 'fusion-pay-tbi' ),                      'class' => 'status-completed' ),
            'Annuled'           => array( 'label' => __( 'Annuled', 'fusion-pay-tbi' ),                   'class' => 'status-failed' ),
        );
    }

    public function getFeatureFlags() {
        return array(
            FeatureFlags::ADVERTISING              => true,
            FeatureFlags::DUAL_CURRENCY            => true,
            FeatureFlags::RENDER_BUTTON_API        => true,
            FeatureFlags::INSTALLMENT_AJAX         => true,
            FeatureFlags::SESSION_DATA_AJAX        => true,
            FeatureFlags::IP_WHITELIST             => false,
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



