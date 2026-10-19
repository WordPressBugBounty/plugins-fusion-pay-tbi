<?php

namespace FusionPayTbi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PriceHelper {

    /**
     * Parse the sale price out of WooCommerce's price HTML.
     *
     * @param string $html_price
     * @return float|false
     */
    public static function getSalePriceFromHtml( $html_price ) {
        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html_price );
        libxml_clear_errors();

        $elements = $dom->getElementsByTagName( 'ins' );
        if ( $elements->length > 0 ) {
            $element = $elements->item( $elements->length - 1 );
            return floatval( self::normalizePriceFormat( $element->nodeValue ) );
        }
        return false;
    }

    /**
     * Normalize a price string with mixed decimal/thousands separators.
     *
     * @param string $price
     * @return string
     */
    public static function normalizePriceFormat( $price ) {
        if ( strpos( $price, ' – ' ) !== false ) {
            list( $price ) = explode( ' – ', $price );
        }
        $price           = preg_replace( '/[^0-9,\.]/', '', $price );
        $last_comma_pos  = strrpos( $price, ',' );
        $last_period_pos = strrpos( $price, '.' );

        if ( $last_period_pos !== false && strlen( $price ) - $last_period_pos === 1 ) {
            return preg_replace( '/\.$/', '', $price );
        }

        if (
            ( $last_comma_pos !== false && $last_period_pos !== false &&
              $last_comma_pos > $last_period_pos && strlen( $price ) - $last_comma_pos - 1 === 3 ) ||
            ( $last_period_pos !== false && $last_comma_pos !== false &&
              $last_period_pos > $last_comma_pos && strlen( $price ) - $last_period_pos - 1 === 3 )
        ) {
            $price = substr_replace( $price, '.', -3, 1 );
        }

        $price = str_replace( array( ',', '.' ), '', $price );
        $price = substr( $price, 0, -2 ) . '.' . substr( $price, -2 );

        return $price;
    }

    /**
     * Check if a product is under a sitewide discount (alg_wc_global_shop_discount plugin).
     *
     * @param int   $product_id
     * @param float $product_price
     * @return float Original price (possibly adjusted back before discount).
     */
    public static function getPriceBeforeSitewideDiscount( $product_id, $product_price ) {
        if ( 'yes' !== get_option( 'alg_wc_global_shop_discount_plugin_enabled', 'yes' ) ) {
            return $product_price;
        }

        $product        = wc_get_product( $product_id );
        $discount_groups = array();
        if ( function_exists( 'alg_wc_gsd_get_product_discount_groups' ) ) {
            $discount_groups = alg_wc_gsd_get_product_discount_groups( $product );
        }

        if ( $product && function_exists( 'alg_wc_gsd_is_discount_product' ) && alg_wc_gsd_is_discount_product( $product ) ) {
            foreach ( $discount_groups as $group_id ) {
                $coefficient      = get_option( 'alg_wc_global_shop_discount_coefficient', array() );
                $coefficient_type = get_option( 'alg_wc_global_shop_discount_coefficient_type', array() );

                if ( isset( $coefficient[ $group_id ] ) && isset( $coefficient_type[ $group_id ] ) ) {
                    $discount_value = $coefficient[ $group_id ];
                    $discount_type  = $coefficient_type[ $group_id ];

                    if ( $discount_type === 'percent' ) {
                        return $product_price / ( 1 - ( -1 ) * $discount_value / 100 );
                    } elseif ( $discount_type === 'fixed' ) {
                        return $product_price + ( -1 ) * $discount_value;
                    }
                }
            }
        }

        return $product_price;
    }
}
