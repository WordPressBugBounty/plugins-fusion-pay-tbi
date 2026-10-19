<?php

namespace FusionPayTbi\Config;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Contract that every country config class must implement.
 */
interface CountryConfigInterface {

    /** ISO 3166-1 alpha-2 country code, e.g. 'BG', 'RO', 'GR'. */
    public function getCountryCode();

    /** Primary store currency, e.g. 'BGN', 'RON', 'EUR'. */
    public function getCurrency();

    /** Secondary currency or null if not applicable. */
    public function getSecondaryCurrency();

    /** Base platform URL (no trailing slash). */
    public function getPlatformUrl();

    /** URL path for the calculator, e.g. '/application/calculcator/' */
    public function getCalcUrlPath();

    /** URL path for the WooSettings API endpoint. */
    public function getApiSettingsPath();

    /** URL path for the Plugin Status API endpoint. */
    public function getApiStatusPath();

    /** Minimum loan amount in primary currency. */
    public function getMinAmount();

    /** Maximum loan amount in primary currency. */
    public function getMaxAmount();

    /** Minimum installment months (standard). */
    public function getMinMonths();

    /** Maximum installment months (standard). */
    public function getMaxMonths();

    /** Minimum months for high tier. */
    public function getMinMonthsHigh();

    /** Maximum months for high tier. */
    public function getMaxMonthsHigh();

    /** Shipping line item name in local language. */
    public function getShippingName();

    /** Coupon/discount line item name in local language. */
    public function getCouponName();

    /** Allowed IP for REST gateway, or null to skip IP check. */
    public function getAllowedIp();

    /** CDN URL for bank assets (no trailing slash). */
    public function getCdnUrl();

    /** Relative path of the bank logo on CDN. */
    public function getBankLogo();

    /** Relative path of the white bank logo on CDN. */
    public function getBankLogoWhite();

    /** Relative path of the light bank logo on CDN. */
    public function getBankLogoLight();

    /** Relative path of the loading SVG on CDN. */
    public function getLoadingSvg();

    /** Default gateway title shown to customers at checkout. */
    public function getDefaultGatewayTitle();

    /**
     * Status map: keys are raw status values from the API,
     * values are arrays with 'label' and 'class' keys for display.
     * BG uses text keys ('Draft', 'Paid', ...), RO uses numeric ('0','1','2','3').
     *
     * @return array
     */
    public function getStatusMap();

    /**
     * Feature flags enabled for this country.
     * Keys are FeatureFlags constants, values are boolean.
     *
     * @return array
     */
    public function getFeatureFlags();

    /**
     * URL path for the render-button API endpoint.
     * Pass true for UAT, false for PROD, or null to auto-detect via FUSIPATB_TEST_ENV.
     *
     * @param bool|null $is_test
     * @return string
     */
    public function getRenderButtonPath( $is_test = null );

    /**
     * URL path for the currency settings API endpoint.
     * Pass true for UAT, false for PROD, or null to auto-detect via FUSIPATB_TEST_ENV.
     *
     * @param bool|null $is_test
     * @return string
     */
    public function getCurrencySettingsPath( $is_test = null );
}



