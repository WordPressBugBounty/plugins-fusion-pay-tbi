<?php

namespace FusionPayTbi\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SequentialOrderNumbers {

    /**
     * Find an order by the Algoritmika Sequential Order Numbers meta.
     *
     * @param mixed $order_number
     * @return \WC_Order|false
     */
    public static function findOrder( $order_number ) {
        global $wpdb;

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

        return false;
    }
}
