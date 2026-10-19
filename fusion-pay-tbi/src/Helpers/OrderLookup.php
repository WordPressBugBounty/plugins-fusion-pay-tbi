<?php

namespace FusionPayTbi\Helpers;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OrderLookup {

    /**
     * Find an order by order number stored in _order_number meta.
     *
     * @param mixed $order_number
     * @return \WC_Order|null
     */
    public static function getByOrderNumber( $order_number ) {
        $orders = wc_get_orders( array(
            'meta_key'   => '_order_number',
            'meta_value' => $order_number,
            'limit'      => 1,
        ) );
        return ! empty( $orders ) ? reset( $orders ) : null;
    }

    /**
     * Find an order by WC order ID or by Sequential Order Numbers plugin meta.
     *
     * @param mixed $order_number
     * @return \WC_Order|false
     */
    public static function getByNumberCustom( $order_number ) {
        global $wpdb;

        // Try native WC order ID first
        $order = wc_get_order( $order_number );
        if ( $order ) {
            return $order;
        }

        // Lookup for Sequential Order Numbers plugin (Algoritmika)
        if ( CountryRegistry::getInstance()->hasFeature( FeatureFlags::SEQUENTIAL_ORDERS ) ) {
            $order_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta
                 WHERE meta_key IN ('_alg_wc_custom_order_number','_alg_wc_full_custom_order_number')
                 AND meta_value = %s
                 LIMIT 1",
                $order_number
            ) );
            if ( $order_id ) {
                return wc_get_order( $order_id );
            }
        }

        return false;
    }

    /**
     * Find an order using all available lookup strategies.
     *
     * @param mixed $order_id_or_number
     * @return \WC_Order|false
     */
    public static function find( $order_id_or_number ) {
        clean_post_cache( $order_id_or_number );

        $order = wc_get_order( $order_id_or_number );
        if ( $order ) {
            return $order;
        }

        $order = self::getByOrderNumber( $order_id_or_number );
        if ( $order ) {
            return $order;
        }

        return self::getByNumberCustom( $order_id_or_number );
    }
}
