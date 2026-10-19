<?php

namespace FusionPayTbi\Orders;

use FusionPayTbi\Config\CountryRegistry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StatusColumn {

    public static function addColumn( $columns ) {
        $columns['tbi_checkout_status'] = __( 'tbi checkout status', 'fusion-pay-tbi' );
        return $columns;
    }

    /**
     * Render for HPOS (High-Performance Order Storage).
     *
     * @param string $column
     * @param int    $order_id
     */
    public static function renderHpos( $column, $order_id ) {
        $order = wc_get_order( $order_id );
        self::output( $column, $order );
    }

    /**
     * Render for legacy post-based orders.
     *
     * @param string $column
     * @param int    $post_id
     */
    public static function renderLegacy( $column, $post_id = null ) {
        global $post;
        $id    = $post_id ? $post_id : ( $post ? $post->ID : 0 );
        $order = wc_get_order( $id );
        if ( ! $order ) {
            return;
        }
        // HPOS: try update_meta_data path first; legacy fallback via get_post_meta.
        $status = self::readCheckoutStatusMeta( $order );
        if ( ( $status === null || $status === '' ) && function_exists( 'get_post_meta' ) ) {
            $status = get_post_meta( $id, '_fusipatb_checkout_status', true );
            if ( ( $status === null || $status === '' ) ) {
                $status = get_post_meta( $id, '_tbi_checkout_status', true );
            }
        }
        self::outputStatus( $column, $status );
    }

    private static function output( $column, $order ) {
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return;
        }
        if ( $column !== 'tbi_checkout_status' ) {
            return;
        }
        $status = self::readCheckoutStatusMeta( $order );
        self::outputStatus( $column, $status );
    }

    /**
     * Reads the tbi checkout status meta off an order, preferring the new
     * _fusipatb_checkout_status key and falling back to the legacy
     * _tbi_checkout_status key only when the new key is empty/missing.
     *
     * New orders are written under the new key only (see
     * Gateway/PaymentGateway.php, Api/OrderCreator.php, Api/StatusUpdater.php).
     * Historical orders were written under the legacy key and are NOT
     * bulk-migrated (HPOS vs legacy post storage makes that risky) -- this
     * fallback-read is what keeps them displaying correctly forever without a
     * migration job.
     *
     * @param \WC_Order $order
     * @return mixed
     */
    private static function readCheckoutStatusMeta( $order ) {
        $status = $order->get_meta( '_fusipatb_checkout_status' );
        if ( $status === null || $status === '' ) {
            $status = $order->get_meta( '_tbi_checkout_status' );
        }
        return $status;
    }

    private static function outputStatus( $column, $status ) {
        if ( $column !== 'tbi_checkout_status' ) {
            return;
        }
        if ( $status === null || $status === '' ) {
            return;
        }

        $config     = CountryRegistry::getInstance()->getConfig();
        $status_map = $config->getStatusMap();

        if ( isset( $status_map[ $status ] ) ) {
            $entry = $status_map[ $status ];
            echo '<mark class="order-status ' . esc_attr( $entry['class'] ) . '"><span>' . esc_html( $entry['label'] ) . '</span></mark>';
        } else {
            // Default fallback
            echo '<mark class="order-status status-pending"><span>' . esc_html( __( 'Draft', 'fusion-pay-tbi' ) ) . '</span></mark>';
        }
    }
}


