<?php

namespace FusionPayTbi\Api;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Compat\TmEpoSupport;
use FusionPayTbi\Helpers\PriceHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OrderCreator {

    /**
     * Create a WooCommerce order from the tbi bank request.
     *
     * @param object $data Decoded JSON payload.
     * @return array{order_id: int, key: string}
     */
    public static function create( $data ) {
        ob_start();

        $config   = CountryRegistry::getInstance()->getConfig();
        $registry = CountryRegistry::getInstance();

        // Ensure WC session exists (BG requirement to prevent plugin crashes)
        if ( ! WC()->session ) {
            $session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
            WC()->session  = new $session_class();
            WC()->session->init();
            if ( ! headers_sent() ) {
                WC()->session->set_customer_session_cookie( true );
            }
        }
        if ( ! WC()->cart ) {
            WC()->initialize_cart();
        }

        $order       = wc_create_order();
        $is_delivery = false;
        $is_coupon   = false;
        $coupon_id   = 0;

        foreach ( $data->cart as $item ) {
            $product_id   = isset( $item->product_id ) ? intval( $item->product_id ) : ( isset( $item->id ) ? intval( $item->id ) : 0 );
            $variation_id = isset( $item->variation_id ) ? intval( $item->variation_id ) : 0;

            // Skip negative-price items (coupons/discounts) – handled separately
            if ( $item->price < 0 ) {
                $is_coupon = true;
                $coupon_id = $product_id;
                continue;
            }

            // Skip delivery item (product_id=348, variation_id=348)
            if ( $variation_id === 348 && $product_id === 348 ) {
                $is_delivery = true;
                continue;
            }

            if ( $variation_id != 0 ) {
                $product = new \WC_Product_Variation( $variation_id );
            } else {
                $product = new \WC_Product( $product_id );
            }

            // BG: restore price before sitewide discount so WC tax recalculation is accurate
            $price = PriceHelper::getPriceBeforeSitewideDiscount( $product_id, $item->price );
            $product->set_price( $price );
            $item_id = $order->add_product( $product, $item->quantity );

            $order_item = $order->get_item( $item_id );
            if ( $order_item instanceof \WC_Order_Item_Product ) {
                TmEpoSupport::applyOrderItemMeta( $order_item, $item );

                if ( ! empty( $item->wapf_options ) && is_array( $item->wapf_options ) ) {
                    foreach ( $item->wapf_options as $option ) {
                        if ( ! is_object( $option ) ) {
                            continue;
                        }

                        $label = isset( $option->label ) ? (string) $option->label : '';
                        $value = isset( $option->value ) ? (string) $option->value : '';
                        if ( $label !== '' && $value !== '' ) {
                            $order_item->add_meta_data( $label, $value );
                        }
                    }
                }

                $order_item->save();
            }
        }

        // Shipping item
        $shipping = new \WC_Order_Item_Shipping();
        if ( $is_delivery ) {
            $shipping->set_method_title( __( 'Fixed delivery', 'fusion-pay-tbi' ) );

            // BG reseller "2501" tax logic
            if ( get_option( 'fusipatb_reseller_code' ) === '2501' ) {
                $tax_rates = \WC_Tax::find_rates( array(
                    'country'   => $config->getCountryCode(),
                    'state'     => '',
                    'postcode'  => '',
                    'city'      => '',
                    'tax_class' => '',
                    'shipping'  => true,
                ) );
                $rate_percent  = ! empty( $tax_rates ) ? floatval( reset( $tax_rates )['rate'] ) : 0.0;
                $gross         = floatval( get_option( 'fusipatb_delivery_fee' ) );
                $vat_multiplier = 1 + $rate_percent / 100;
                $shipping->set_total( round( $gross / $vat_multiplier, 2 ) );
            } else {
                $shipping->set_total( get_option( 'fusipatb_delivery_fee' ) );
            }
        } else {
            $shipping->set_method_title( __( 'Free delivery', 'fusion-pay-tbi' ) );
            $shipping->set_total( 0 );
        }
        $order->add_item( $shipping );

        $order->calculate_taxes();
        $order->calculate_totals();

        // Apply coupon
        if ( $is_coupon ) {
            $coupon_post = get_post( $coupon_id );
            $coupon_code = $coupon_post ? $coupon_post->post_title : null;
            if ( $coupon_code ) {
                $order->apply_coupon( $coupon_code );
            }
        }

        // Addresses - bug fix: use config country code, not hardcoded 'BG'
        $country_code     = $config->getCountryCode();
        $delivery_address = array(
            'first_name' => isset( $data->first_name )           ? $data->first_name           : '',
            'last_name'  => isset( $data->last_name )            ? $data->last_name            : '',
            'email'      => isset( $data->email )                ? $data->email                : '',
            'phone'      => isset( $data->phone )                ? $data->phone                : '',
            'address_1'  => isset( $data->delivery_address )     ? $data->delivery_address     : '',
            'address_2'  => '',
            'city'       => isset( $data->delivery_city )        ? $data->delivery_city        : '',
            'state'      => '',
            'postcode'   => isset( $data->delivery_postal_code ) ? (string) $data->delivery_postal_code : '',
            'country'    => $country_code,
        );

        if ( isset( $data->billing_address ) ) {
            $billing_address = array(
                'first_name' => isset( $data->first_name )          ? $data->first_name          : '',
                'last_name'  => isset( $data->last_name )           ? $data->last_name           : '',
                'email'      => isset( $data->email )               ? $data->email               : '',
                'phone'      => isset( $data->phone )               ? $data->phone               : '',
                'address_1'  => $data->billing_address,
                'address_2'  => '',
                'city'       => isset( $data->billing_city )        ? $data->billing_city        : '',
                'state'      => '',
                'postcode'   => isset( $data->billing_postal_code ) ? (string) $data->billing_postal_code : '',
                'country'    => $country_code,
            );
        } else {
            $billing_address = $delivery_address;
        }

        $order->set_address( $billing_address, 'billing' );
        $order->set_address( $delivery_address, 'shipping' );
        $order->set_payment_method( 'tbi_bank' );
        $order->set_payment_method_title( __( 'Pay in installments with tbi bank', 'fusion-pay-tbi' ) );
        $order->set_status( get_option( 'fusipatb_default_status' ), 'Order is created programmatically' );
        $order->calculate_totals();

        $order->update_meta_data( '_fusipatb_checkout_status', $data->status );
        $order->save();

        ob_end_clean();

        return array(
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        );
    }
}


