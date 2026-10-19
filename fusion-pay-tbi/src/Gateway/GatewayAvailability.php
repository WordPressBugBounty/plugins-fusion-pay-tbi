<?php

namespace FusionPayTbi\Gateway;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GatewayAvailability {

    /**
     * Filter available payment gateways; remove tbi if not eligible.
     *
     * @param array $available_gateways
     * @return array
     */
    public static function filter( $available_gateways ) {
        $order_total    = 0;
        $order_currency = null;

        if ( is_wc_endpoint_url( 'order-pay' ) ) {
            $order_id = isset( $_GET['key'] ) ? wc_get_order_id_by_order_key( sanitize_text_field( $_GET['key'] ) ) : 0;
            $order    = wc_get_order( $order_id );
            if ( $order ) {
                $order_total    = $order->get_total();
                $order_currency = $order->get_currency();
            }
        } else {
            if ( isset( WC()->cart->total ) ) {
                $order_total = WC()->cart->total;
            }
        }

        if ( ! get_option( 'fusipatb_checkout_status' ) ) {
            unset( $available_gateways['tbi_bank'] );
            return $available_gateways;
        }

        $registry      = CountryRegistry::getInstance();
        $config        = $registry->getConfig();
        $dual_currency = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );

        $admin_currency = get_option( 'fusipatb_admin_currency' );
        $currency       = $order_currency
            ? $order_currency
            : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $config->getCurrency() );

        if ( $dual_currency && $currency === 'EUR' ) {
            $loan_min = floatval( get_option( 'fusipatb_min_amount_eur' ) );
            $loan_max = floatval( get_option( 'fusipatb_max_amount_eur' ) );
            if ( $loan_min <= 0 ) {
                $loan_min = floatval( get_option( 'fusipatb_min_amount' ) );
                if ( $loan_min <= 0 ) {
                    $loan_min = floatval( $config->getMinAmount() );
                }
                if ( $admin_currency < 3 ) {
                    $loan_min = CurrencyHelper::bgnToEur( $loan_min );
                }
            }
            if ( $loan_max <= 0 ) {
                $loan_max = floatval( get_option( 'fusipatb_max_amount' ) );
                if ( $loan_max <= 0 ) {
                    $loan_max = floatval( $config->getMaxAmount() );
                }
                if ( $admin_currency < 3 ) {
                    $loan_max = CurrencyHelper::bgnToEur( $loan_max );
                }
            }
            $bnpl_min = floatval( get_option( 'fusipatb_bnpl_min_amount_eur' ) );
            $bnpl_max = floatval( get_option( 'fusipatb_bnpl_max_amount_eur' ) );
            if ( $bnpl_min <= 0 ) {
                $bnpl_min = floatval( get_option( 'fusipatb_bnpl_min_amount' ) );
                if ( $admin_currency < 3 ) {
                    $bnpl_min = $bnpl_min > 0 ? CurrencyHelper::bgnToEur( $bnpl_min ) : 0;
                }
            }
            if ( $bnpl_max <= 0 ) {
                $bnpl_max = floatval( get_option( 'fusipatb_bnpl_max_amount' ) );
                if ( $admin_currency < 3 ) {
                    $bnpl_max = $bnpl_max > 0 ? CurrencyHelper::bgnToEur( $bnpl_max ) : 0;
                }
            }
        } else {
            $loan_min = floatval( get_option( 'fusipatb_min_amount' ) );
            $loan_max = floatval( get_option( 'fusipatb_max_amount' ) );
            if ( $loan_min <= 0 ) {
                $loan_min = floatval( $config->getMinAmount() );
            }
            if ( $loan_max <= 0 ) {
                $loan_max = floatval( $config->getMaxAmount() );
            }
            $bnpl_min = floatval( get_option( 'fusipatb_bnpl_min_amount' ) );
            $bnpl_max = floatval( get_option( 'fusipatb_bnpl_max_amount' ) );
        }

        $bnpl_eligible = $config->getCountryCode() !== 'RO'
            && $bnpl_min > 0
            && $order_total >= $bnpl_min
            && ( $bnpl_max <= 0 || $order_total <= $bnpl_max );

        $loan_eligible = $order_total >= $loan_min
            && ( $loan_max <= 0 || $order_total <= $loan_max );

        if ( ! $bnpl_eligible && ! $loan_eligible ) {
            unset( $available_gateways['tbi_bank'] );
        }

        return $available_gateways;
    }
}


