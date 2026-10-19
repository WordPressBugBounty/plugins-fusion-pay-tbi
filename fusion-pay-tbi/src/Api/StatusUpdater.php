<?php

namespace FusionPayTbi\Api;

use FusionPayTbi\Helpers\OrderLookup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StatusUpdater {

    /**
     * Update the tbi checkout status meta on an existing order.
     *
     * @param object $data Decoded JSON from the API request.
     * @return array{order_id: mixed, key: int}
     */
    public static function update( $data ) {
        ob_start();

        $order = OrderLookup::find( $data->order_id );

        if ( $order ) {
            try {
                $order->update_meta_data( '_fusipatb_checkout_status', $data->status );
                $order->save();
            } catch ( \Exception $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'TBI: Order meta update failed: ' . $e->getMessage() );
                }
            }
        }

        ob_end_clean();

        return array(
            'order_id' => $data->order_id,
            'key'      => 0,
        );
    }
}
