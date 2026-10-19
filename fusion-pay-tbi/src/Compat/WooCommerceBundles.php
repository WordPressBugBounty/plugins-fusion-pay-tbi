<?php

namespace FusionPayTbi\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooCommerceBundles {

    /**
     * Check if a cart item should be skipped (it's a bundled child item).
     *
     * @param array $cart_item
     * @return bool
     */
    public static function shouldSkipItem( array $cart_item ) {
        return isset( $cart_item['bundled_by'] );
    }
}
