<?php

namespace FusionPayTbi\Api;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class InstallmentCalculator {

    /**
     * Handle the AJAX request to calculate installments.
     */
    public static function handleAjax() {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'fusipatb_calculate_installments_ajax' ) ) {
            wp_send_json_error( array( 'message' => 'Security verification failed' ), 403 );
            return;
        }

        $amount = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
        $currency = isset( $_POST['currency'] ) && is_scalar( $_POST['currency'] ) ? strtoupper( trim( (string) wp_unslash( $_POST['currency'] ) ) ) : null;

        if ( $amount <= 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid amount provided' ) );
            return;
        }

        $result = self::calculate( $amount, $currency );

        if ( $result && $result['success'] ) {
            wp_send_json_success( $result['data'] );
        } else {
            $error = array( 'message' => 'Failed to calculate installments' );
            if ( $result && ! empty( $result['reason'] ) ) {
                $error['reason'] = $result['reason'];
            }
            if ( $result && ! empty( $result['api_response'] ) ) {
                $error['api_response'] = $result['api_response'];
            }
            wp_send_json_error( $error );
        }
    }

    /**
     * Calculate installments by calling the tbi API.
     *
     * @param float $amount
     * @return array{success: bool, data: array}|null
     */
    public static function calculate( $amount, $currency_override = null ) {
        $reseller_code = get_option( 'fusipatb_reseller_code' );
        $reseller_key  = get_option( 'fusipatb_reseller_key' );

        if ( empty( $reseller_code ) || empty( $reseller_key ) ) {
            return null;
        }

        $config   = CountryRegistry::getInstance()->getConfig();
        $currency = preg_match( '/^[A-Z]{3}$/', (string) $currency_override )
            ? (string) $currency_override
            : ( function_exists( 'fusipatb_get_current_currency' ) ? fusipatb_get_current_currency() : $config->getCurrency() );

        $calc_url = FUSIPATB_CALC_URL;
        $api_url  = defined( 'FUSIPATB_PLATFORM_URL' ) ? FUSIPATB_PLATFORM_URL : $config->getPlatformUrl();
        $render_url = self::getRenderButtonUrl();

        $request_data = array(
            'reseller_code' => $reseller_code,
            'reseller_key'  => $reseller_key,
            'amount'        => number_format( (float) $amount, 2, '.', '' ),
            'currency'      => $currency,
        );

        $response = wp_remote_post( $render_url, array(
            'body'    => $request_data,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'reason' => 'wp_error', 'api_response' => $response->get_error_message() );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code !== 200 ) {
            return array( 'success' => false, 'reason' => 'http_' . $http_code, 'api_response' => wp_remote_retrieve_body( $response ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! $data || ! isset( $data['error'] ) ) {
            return array( 'success' => false, 'reason' => 'invalid_json', 'api_response' => $body );
        }

        if ( (int) $data['error'] !== 0 ) {
            return array( 'success' => false, 'reason' => 'api_error_' . $data['error'], 'api_response' => $body );
        }

        if ( empty( $data['data'] ) ) {
            return array( 'success' => false, 'reason' => 'empty_data', 'api_response' => $body );
        }

        return array(
            'success' => true,
            'data'    => $data['data'],
        );
    }

    private static function getRenderButtonUrl() {
        $config = CountryRegistry::getInstance()->getConfig();
        return rtrim( FUSIPATB_PLATFORM_URL, '/' ) . $config->getRenderButtonPath();
    }
}

// Canonical global function used in public_view.php.
if ( ! function_exists( 'fusipatb_calculate_installments' ) ) {
    function fusipatb_calculate_installments( $amount ) {
        return \FusionPayTbi\Api\InstallmentCalculator::calculate( $amount );
    }
}




