<?php

namespace FusionPayTbi\Helpers;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Multi-currency utilities. Active only when dual_currency feature is enabled.
 */
class CurrencyHelper {

    const EUR_RATE = 1.95583;

    /**
     * Get the currency settings API URL based on current environment.
     *
     * @param string|null $force_env 'uat', 'prod', or null for auto-detect.
     * @return string
     */
    public static function getApiUrl( $force_env = null ) {
        $config = CountryRegistry::getInstance()->getConfig();

        if ( $force_env === 'prod' ) {
            return rtrim( $config->getPlatformUrl(), '/' ) . $config->getCurrencySettingsPath( false );
        }

        if ( $force_env === 'uat' ) {
            return rtrim( FUSIPATB_PLATFORM_URL, '/' ) . $config->getCurrencySettingsPath( true );
        }

        return rtrim( FUSIPATB_PLATFORM_URL, '/' ) . $config->getCurrencySettingsPath();
    }

    /**
     * Normalize a currency string to supported values.
     *
     * @param mixed $currency
     * @return string|null
     */
    private static function normalizeCurrency( $currency ) {
        if ( ! is_scalar( $currency ) ) {
            return null;
        }

        $currency = strtoupper( trim( (string) $currency ) );

        if ( in_array( $currency, array( 'BGN', 'EUR', 'RON', 'LEU' ), true ) ) {
            return $currency;
        }

        return null;
    }

    /**
     * Resolve current frontend currency and the source used.
     *
     * @return array{currency:string, source:string}
     */
    public static function getCurrentCurrencyInfo() {
        $candidates = array();

        $filtered = apply_filters( 'fusion_pay_tbi_current_currency', null );
        $candidates[] = array( 'value' => $filtered, 'source' => 'filter:fusion_pay_tbi_current_currency' );

        $request_keys = array( 'currency', 'wmc-currency', 'wcmlc', 'woocs' );
        foreach ( $request_keys as $key ) {
            if ( isset( $_GET[ $key ] ) ) {
                $candidates[] = array( 'value' => wp_unslash( $_GET[ $key ] ), 'source' => 'query:' . $key );
            }
            if ( isset( $_POST[ $key ] ) ) {
                $candidates[] = array( 'value' => wp_unslash( $_POST[ $key ] ), 'source' => 'post:' . $key );
            }
        }

        $cookie_keys = array(
            'woocs_current_currency',
            'wcml_client_currency',
            'wmc_current_currency',
            'aelia_cs_selected_currency',
            'woocommerce_currency',
            'currency',
        );
        foreach ( $cookie_keys as $key ) {
            if ( isset( $_COOKIE[ $key ] ) ) {
                $candidates[] = array( 'value' => sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) ), 'source' => 'cookie:' . $key );
            }
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_keys = array(
                'client_currency',
                'currency',
                'woocs_current_currency',
                'wmc_current_currency',
                'aelia_cs_selected_currency',
            );
            foreach ( $session_keys as $key ) {
                $candidates[] = array( 'value' => WC()->session->get( $key ), 'source' => 'session:' . $key );
            }
        }

        global $WOOCS;
        if ( isset( $WOOCS ) && is_object( $WOOCS ) && isset( $WOOCS->current_currency ) ) {
            $candidates[] = array( 'value' => $WOOCS->current_currency, 'source' => 'global:WOOCS' );
        }

        $candidates[] = array( 'value' => apply_filters( 'wcml_client_currency', null ), 'source' => 'filter:wcml_client_currency' );
        $candidates[] = array( 'value' => apply_filters( 'wmc_current_currency', null ), 'source' => 'filter:wmc_current_currency' );
        $candidates[] = array( 'value' => apply_filters( 'wc_aelia_cs_selected_currency', null ), 'source' => 'filter:wc_aelia_cs_selected_currency' );

        if ( function_exists( 'get_woocommerce_currency' ) ) {
            $candidates[] = array( 'value' => get_woocommerce_currency(), 'source' => 'woocommerce:get_woocommerce_currency' );
        }

        $candidates[] = array( 'value' => get_option( 'woocommerce_currency', 'BGN' ), 'source' => 'option:woocommerce_currency' );

        foreach ( $candidates as $candidate ) {
            $normalized = self::normalizeCurrency( $candidate['value'] );
            if ( null !== $normalized ) {
                return array(
                    'currency' => $normalized,
                    'source'   => $candidate['source'],
                );
            }
        }

        return array(
            'currency' => 'BGN',
            'source'   => 'fallback:BGN',
        );
    }

    /**
     * Get the current frontend currency (BGN or EUR).
     * Falls back to WooCommerce store currency.
     *
     * @return string
     */
    public static function getCurrentCurrency() {
        $info = self::getCurrentCurrencyInfo();
        return $info['currency'];
    }

    /**
     * @param float $bgn
     * @return float
     */
    public static function bgnToEur( $bgn ) {
        return round( $bgn / self::EUR_RATE, 2 );
    }

    /**
     * @param float $eur
     * @return float
     */
    public static function eurToBgn( $eur ) {
        return round( $eur * self::EUR_RATE, 2 );
    }

    /**
     * Fetch currency type configuration from the tbi API.
     * Returns cached result if available.
     *
     * @return array{admin_currency: int, store_currency: int}
     */
    public static function fetchCurrencyType() {
        $api_url = self::getApiUrl();

        $reseller_code = get_option( 'fusipatb_reseller_code' );
        $reseller_key  = get_option( 'fusipatb_reseller_key' );

        if ( empty( $reseller_code ) || empty( $reseller_key ) ) {
            return array( 'admin_currency' => 2, 'store_currency' => 1 );
        }

        $url      = $api_url . '?' . http_build_query( array( 'reseller_code' => $reseller_code, 'reseller_key' => $reseller_key ) );
        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return array( 'admin_currency' => 2, 'store_currency' => 1 );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $data || ! isset( $data['message']['currency_type'] ) ) {
            return array( 'admin_currency' => 2, 'store_currency' => 1 );
        }

        $result = array(
            'admin_currency' => intval( $data['message']['currency_type'] ),
            'store_currency' => 1,
        );
        if ( isset( $data['message']['store']['store_currency'] ) ) {
            $result['store_currency'] = intval( $data['message']['store']['store_currency'] );
        }
        update_option( 'fusipatb_admin_currency', $result['admin_currency'] );
        update_option( 'fusipatb_store_currency', $result['store_currency'] );

        return $result;
    }
}

// Global helper function used throughout the codebase.
if ( ! function_exists( 'fusipatb_get_current_currency' ) ) {
    function fusipatb_get_current_currency() {
        return \FusionPayTbi\Helpers\CurrencyHelper::getCurrentCurrency();
    }
}



