<?php

namespace FusionPayTbi;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CountryDetector {

    /**
     * Detect the active country code using:
     * 1. Explicit constant override via wp-config.php
     * 2. WooCommerce store country setting
     * 3. Fallback to 'BG'
     *
     * @return string ISO country code in uppercase.
     */
    public static function detect() {
        // 1. Explicit override
        if ( defined( 'FUSION_PAY_TBI_COUNTRY' ) ) {
            return strtoupper( FUSION_PAY_TBI_COUNTRY );
        }

        // 2. Auto-detect from WooCommerce store country
        $wc_country = get_option( 'woocommerce_default_country', '' );
        $parts      = explode( ':', $wc_country );
        $country    = strtoupper( $parts[0] );

        // 3. Validate against supported countries
        $supported = array( 'BG', 'RO', 'GR' );
        return in_array( $country, $supported, true ) ? $country : 'BG';
    }
}
