<?php

namespace FusionPayTbi\Api;

use FusionPayTbi\Helpers\SecurityHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles direct cart processing using WooCommerce cart state as the source of truth.
 *
 * If the current WooCommerce cart is empty, the endpoint can accept an items payload
 * and populate the cart via WC()->cart->add_to_cart() using validated product data.
 * After that it delegates to the standard CartProcessor, which builds the TBI payload
 * from the active WooCommerce cart/session instead of trusting browser-supplied totals.
 */
class DirectCartProcessor {

    public static function handle() {
        self::validateAjaxRequest();
        self::ensureCartLoaded();

        if ( ! \WC()->cart ) {
            \wp_send_json_error( array( 'message' => 'Cart is unavailable' ) );
            return;
        }

        if ( \WC()->cart->is_empty() ) {
            $items = self::parseRequestItems();

            if ( empty( $items ) ) {
                \wp_send_json_error( array( 'message' => 'Cart is empty' ) );
                return;
            }

            self::populateCartFromItems( $items );

            if ( \WC()->cart->is_empty() ) {
                \wp_send_json_error( array( 'message' => 'No valid items were added to the cart' ) );
                return;
            }
        }

        unset( $_POST['items'] );

        CartProcessor::handle();
    }

    public static function validateAjaxRequest() {
        if ( ! \defined( 'DOING_AJAX' ) || ! \constant( 'DOING_AJAX' ) ) {
            \wp_send_json_error( array( 'message' => 'Invalid request' ) );
        }

        // Verify nonce for security
        $nonce = isset( $_POST['_wpnonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['_wpnonce'] ) ) : '';
        $action = isset( $_POST['action'] ) ? \sanitize_text_field( \wp_unslash( $_POST['action'] ) ) : '';
        
        // Determine which nonce to verify based on the action
        $expected_action = '';
        if ( strpos( $action, 'fusipatb_process_product_direct' ) !== false ) {
            $expected_action = 'fusipatb_process_product_direct';
        } elseif ( strpos( $action, 'fusipatb_process_cart_direct' ) !== false ) {
            $expected_action = 'fusipatb_process_cart_direct';
        }

        if ( ! $expected_action || ! $nonce || ! \wp_verify_nonce( $nonce, $expected_action ) ) {
            \wp_send_json_error( array( 'message' => 'Security verification failed' ), 403 );
        }

        if ( ! SecurityHelper::isLocalOriginAllowed() ) {
            \wp_send_json_error( array( 'message' => 'Access denied' ), 403 );
        }
    }

    public static function ensureCartLoaded() {
        if ( \function_exists( 'wc_load_cart' ) && ( ! \WC()->cart || ! \WC()->session ) ) {
            \wc_load_cart();
        }
    }

    private static function parseRequestItems() {
        if ( ! isset( $_POST['items'] ) ) {
            return array();
        }

        $items_input = \wp_unslash( $_POST['items'] );

        if ( is_string( $items_input ) ) {
            $items_input = json_decode( $items_input, true );
        }

        return is_array( $items_input ) ? $items_input : array();
    }

    private static function populateCartFromItems( $items ) {
        foreach ( $items as $item ) {
            if ( is_object( $item ) ) {
                $item = (array) $item;
            }

            if ( ! is_array( $item ) ) {
                continue;
            }

            $product_id   = isset( $item['product_id'] ) ? \absint( $item['product_id'] ) : 0;
            $variation_id = isset( $item['variation_id'] ) ? \absint( $item['variation_id'] ) : 0;
            $quantity     = isset( $item['quantity'] ) ? max( 1, \absint( $item['quantity'] ) ) : 1;

            if ( ! $product_id ) {
                continue;
            }

            $variation      = self::normalizeVariationData( isset( $item['variation'] ) ? $item['variation'] : array() );
            $cart_item_data = self::normalizeCartItemData( isset( $item['cart_item_data'] ) ? $item['cart_item_data'] : array() );

            $added = \WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation, $cart_item_data );

            if ( ! $added && \function_exists( 'wc_add_notice' ) ) {
                \wc_clear_notices();
            }
        }
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

        $sanitized = array();
        foreach ( $cart_item_data as $key => $value ) {
            $safe_key = \sanitize_key( (string) $key );
            if ( is_scalar( $value ) ) {
                $sanitized[ $safe_key ] = \sanitize_text_field( (string) $value );
            } elseif ( is_array( $value ) ) {
                $sanitized[ $safe_key ] = self::normalizeCartItemData( $value );
            }
        }

        return $sanitized;
    }
}

