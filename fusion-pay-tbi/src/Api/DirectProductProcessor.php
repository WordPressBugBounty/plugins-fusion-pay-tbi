<?php

namespace FusionPayTbi\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles direct product submission using native WooCommerce add-to-cart semantics.
 *
 * The endpoint accepts product_id/add-to-cart, quantity, variation_id and optional
 * variation/cart_item_data payloads, adds the item to the active WooCommerce cart,
 * and then delegates to the standard cart processor so TBI is built from the
 * authoritative WooCommerce cart state.
 */
class DirectProductProcessor {

    public static function handle() {
        DirectCartProcessor::validateAjaxRequest();
        DirectCartProcessor::ensureCartLoaded();

        if ( ! \WC()->cart ) {
            \wp_send_json_error( array( 'message' => 'Cart is unavailable' ) );
            return;
        }

        $product_id   = isset( $_POST['product_id'] ) ? \absint( $_POST['product_id'] ) : 0;
        $product_id   = $product_id ? $product_id : ( isset( $_POST['add-to-cart'] ) ? \absint( $_POST['add-to-cart'] ) : 0 );
        $variation_id = isset( $_POST['variation_id'] ) ? \absint( $_POST['variation_id'] ) : 0;
        $quantity     = isset( $_POST['quantity'] ) ? max( 1, \absint( $_POST['quantity'] ) ) : 1;

        if ( ! $product_id ) {
            \wp_send_json_error( array( 'message' => 'Missing product_id' ) );
            return;
        }

        $product = \wc_get_product( $variation_id ?: $product_id );
        if ( ! $product || ! $product->exists() ) {
            \wp_send_json_error( array( 'message' => 'Product not found' ) );
            return;
        }

        // Sanitize variation and cart_item_data from POST before processing
        $variation_raw  = isset( $_POST['variation'] ) ? \wp_unslash( $_POST['variation'] ) : array();
        $cart_data_raw  = isset( $_POST['cart_item_data'] ) ? \wp_unslash( $_POST['cart_item_data'] ) : array();
        
        $variation      = self::normalizeVariationData( $variation_raw );
        $cart_item_data = self::normalizeCartItemData( $cart_data_raw );

        $passed_validation = \apply_filters(
            'woocommerce_add_to_cart_validation',
            true,
            $product_id,
            $quantity,
            $variation_id,
            $variation,
            $cart_item_data
        );

        if ( ! $passed_validation ) {
            \wp_send_json_error( array( 'message' => self::collectNoticeMessage( 'Unable to add product to the cart' ) ) );
            return;
        }

        $cart_item_key = \WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation, $cart_item_data );

        if ( ! $cart_item_key ) {
            \wp_send_json_error( array( 'message' => self::collectNoticeMessage( 'Unable to add product to the cart' ) ) );
            return;
        }

        CartProcessor::handle();
    }

    private static function normalizeVariationData( $variation ) {
        if ( is_string( $variation ) ) {
            $variation = json_decode( \wp_unslash( $variation ), true );
        }

        if ( ! is_array( $variation ) ) {
            return array();
        }

        $normalized = array();

        foreach ( $variation as $key => $value ) {
            // Sanitize keys to prevent injection
            $safe_key = \sanitize_key( (string) $key );
            
            if ( is_array( $value ) && isset( $value['attribute'], $value['value'] ) ) {
                $safe_attr = \sanitize_key( (string) $value['attribute'] );
                $safe_val  = \sanitize_text_field( (string) $value['value'] );
                $normalized[ $safe_attr ] = $safe_val;
                continue;
            }

            if ( is_scalar( $value ) ) {
                $safe_val = \sanitize_text_field( (string) $value );
                $normalized[ $safe_key ] = $safe_val;
            }
        }

        return $normalized;
    }

    private static function normalizeCartItemData( $cart_item_data ) {
        if ( is_string( $cart_item_data ) ) {
            $cart_item_data = json_decode( \wp_unslash( $cart_item_data ), true );
        }

        if ( ! is_array( $cart_item_data ) ) {
            return array();
        }

        // Sanitize cart item data to prevent injection attacks
        $sanitized = array();
        foreach ( $cart_item_data as $key => $value ) {
            $safe_key = \sanitize_key( (string) $key );
            if ( is_scalar( $value ) ) {
                $sanitized[ $safe_key ] = \sanitize_text_field( (string) $value );
            } elseif ( is_array( $value ) ) {
                // Recursively sanitize nested arrays
                $sanitized[ $safe_key ] = self::normalizeCartItemData( $value );
            }
        }

        return $sanitized;
    }

    private static function collectNoticeMessage( $fallback ) {
        if ( ! \function_exists( 'wc_get_notices' ) ) {
            return $fallback;
        }

        $notices = \wc_get_notices( 'error' );
        if ( empty( $notices ) ) {
            return $fallback;
        }

        $message = \wp_strip_all_tags( $notices[0]['notice'] );
        \wc_clear_notices();

        return $message ?: $fallback;
    }
}
