<?php

namespace FusionPayTbi\Config;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared defaults for all country configs.
 * Subclasses must implement the abstract methods.
 */
abstract class AbstractCountryConfig implements CountryConfigInterface {

    public function getCdnUrl() {
        return 'https://cdn.tbibank.support/';
    }

    public function getBankLogoWhite() {
        return 'logo/tbi-bank-white.svg';
    }

    public function getBankLogoLight() {
        return 'logo/tbi-bank-logo2.svg';
    }

    public function getLoadingSvg() {
        return 'integrations/loading.svg';
    }

    public function getSecondaryCurrency() {
        return null;
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

    public function getCurrencySettingsPath( $is_test = null ) {
        if ( $is_test === null ) {
            $is_test = defined( 'FUSIPATB_TEST_ENV' ) && FUSIPATB_TEST_ENV;
        }
        if ( $is_test ) {
            return '/admin-0071/global_settings/currency';
        }
        return '/admin-30cffb5df303275a2c4ce4fa7d19c8ed/global_settings/currency';
    }

    public function getAllowedIp() {
        return null;
    }

    /**
     * Build request body for render-button API.
     * Override in country-specific config if API format differs by country.
     *
     * @param string $reseller_code
     * @param string $reseller_key
     * @param float  $amount
     * @param string $currency
     * @return array
     */
    public function getRenderButtonRequestBody( $reseller_code, $reseller_key, $amount, $currency ) {
        return array(
            'reseller_code' => $reseller_code,
            'reseller_key'  => $reseller_key,
            'amount'        => number_format( (float) $amount, 2, '.', '' ),
            'currency'      => $currency,
        );
    }
}



