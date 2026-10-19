<?php

namespace FusionPayTbi\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Support for WooCommerce Product Add-Ons (WCPA) plugin.
 * Provides price override from POST data.
 */
class WcpaSupport {

    /**
     * Check if WCPA price is provided and return it.
     *
     * @param float $default_price
     * @return float
     */
    public static function applyPrice( $default_price ) {
        if ( isset( $_POST['wcpa_price'] ) && is_numeric( $_POST['wcpa_price'] ) ) {
            return floatval( $_POST['wcpa_price'] );
        }
        return $default_price;
    }

    /**
     * Get total from WCPA if available, otherwise use cart total.
     *
     * @param float $cart_total
     * @return float
     */
    public static function getCalculatorTotal( $cart_total ) {
        if ( isset( $_POST['wcpa_price'] ) && is_numeric( $_POST['wcpa_price'] ) ) {
            return floatval( $_POST['wcpa_price'] );
        }
        return $cart_total;
    }
}
