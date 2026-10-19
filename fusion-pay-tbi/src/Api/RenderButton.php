<?php

namespace FusionPayTbi\Api;

use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RenderButton {

    /**
     * Get the render-button API URL based on current environment.
     *
     * @param string|null $force_env 'uat', 'prod', or null for auto-detect.
     * @return string
     */
    public static function getApiUrl( $force_env = null ) {
        $config = \FusionPayTbi\Config\CountryRegistry::getInstance()->getConfig();

        if ( $force_env === 'prod' ) {
            return rtrim( $config->getPlatformUrl(), '/' ) . $config->getRenderButtonPath( false );
        }

        if ( $force_env === 'uat' ) {
            return rtrim( FUSIPATB_PLATFORM_URL, '/' ) . $config->getRenderButtonPath( true );
        }

        return rtrim( FUSIPATB_PLATFORM_URL, '/' ) . $config->getRenderButtonPath();
    }

    /**
     * Fetch button text from the render-button API.
     *
     * @param float       $amount
     * @param string      $currency
     * @param string|null $force_env
     * @return array{ok: bool, data?: array, message?: string, api_url?: string}
     */
    public static function fetchText( $amount, $currency, $force_env = null ) {
        $config        = \FusionPayTbi\Config\CountryRegistry::getInstance()->getConfig();
        $reseller_code = get_option( 'fusipatb_reseller_code' );
        $reseller_key  = get_option( 'fusipatb_reseller_key' );

        if ( empty( $reseller_code ) || empty( $reseller_key ) ) {
            return array( 'ok' => false, 'message' => 'Missing reseller credentials' );
        }

        $api_url = self::getApiUrl( $force_env );
        $body    = $config->getRenderButtonRequestBody( $reseller_code, $reseller_key, $amount, $currency );

        $response = wp_remote_post( $api_url, array(
            'body'    => $body,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'ok' => false, 'message' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            // Retry with prod when UAT returns 403 (credential mismatch)
            if ( $code === 403 && $force_env === null && defined( 'FUSIPATB_TEST_ENV' ) && FUSIPATB_TEST_ENV === true ) {
                return self::fetchText( $amount, $currency, 'prod' );
            }
            return array( 'ok' => false, 'message' => 'HTTP ' . $code, 'api_url' => $api_url );
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return array( 'ok' => false, 'message' => 'Invalid JSON response' );
        }

        if ( ! isset( $data['error'] ) ) {
            return array( 'ok' => false, 'message' => 'Unexpected response' );
        }

        if ( (int) $data['error'] !== 0 ) {
            return array( 'ok' => false, 'message' => isset( $data['message'] ) ? $data['message'] : 'API error', 'api_url' => $api_url );
        }

        if ( empty( $data['data']['button_text'] ) ) {
            return array( 'ok' => false, 'message' => 'Missing button_text' );
        }

        return array( 'ok' => true, 'data' => $data['data'], 'api_url' => $api_url );
    }

    /**
     * Replace template placeholders with actual installment values.
     *
     * @param string $template
     * @param array  $api_data
     * @param string $currency
     * @return string
     */
    public static function fillPlaceholders( $template, $api_data, $currency ) {
        if ( ! is_string( $template ) || $template === '' || ! is_array( $api_data ) ) {
            return $template;
        }

        $installments        = isset( $api_data['installments'] ) ? $api_data['installments'] : ( isset( $api_data['default_installments'] ) ? $api_data['default_installments'] : null );
        $installment_amount  = isset( $api_data['installment_amount'] ) ? $api_data['installment_amount'] : ( isset( $api_data['default_installment_amount'] ) ? $api_data['default_installment_amount'] : null );
        $installment_float   = is_numeric( $installment_amount ) ? (float) $installment_amount : null;

        $replacements = array();
        if ( $installments !== null ) {
            $replacements['{installments}'] = (string) $installments;
        }
        if ( $installment_float !== null ) {
            if ( strtoupper( (string) $currency ) === 'EUR' ) {
                // API returns EUR; {installment} must be BGN, {eur_installment} must be EUR
                $bgn_value = CurrencyHelper::eurToBgn( $installment_float );
                $replacements['{installment_amount}'] = number_format( $bgn_value, 2, '.', '' );
                $replacements['{installment}']        = number_format( $bgn_value, 2, '.', '' );
                $replacements['{eur_installments}']   = number_format( $installment_float, 2, '.', '' );
                $replacements['{eur_installment}']    = number_format( $installment_float, 2, '.', '' );
            } else {
                // API returns BGN; {installment} = BGN (raw), {eur_installment} = EUR (converted)
                $eur_value = CurrencyHelper::bgnToEur( $installment_float );
                $replacements['{installment_amount}'] = number_format( $installment_float, 2, '.', '' );
                $replacements['{installment}']        = number_format( $installment_float, 2, '.', '' );
                $replacements['{eur_installments}']   = number_format( $eur_value, 2, '.', '' );
                $replacements['{eur_installment}']    = number_format( $eur_value, 2, '.', '' );
            }
        }

        return empty( $replacements ) ? $template : strtr( $template, $replacements );
    }
}



