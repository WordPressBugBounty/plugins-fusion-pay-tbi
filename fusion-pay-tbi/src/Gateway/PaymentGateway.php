<?php

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Compat\TmEpoSupport;
use FusionPayTbi\Helpers\Cryptor;
use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initialize gateway class within the plugins_loaded context so WC_Payment_Gateway is available.
 */
function fusipatb_init_gateway_class() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    class Fusipatb_PaymentGateway extends WC_Payment_Gateway {

        public function __construct() {
            $config = CountryRegistry::getInstance()->getConfig();

            $this->id                 = 'tbi_bank';
            $this->icon               = FUSIPATB_CDN_URL . FUSIPATB_BANK_LOGO;
            $this->has_fields         = false;
            $this->method_title       = __( 'Pay in installments with tbi bank', 'fusion-pay-tbi' );
            $this->method_description = __( 'Allows payment in installments with tbi bank', 'fusion-pay-tbi' );
            $this->supports           = array( 'products' );

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option( 'title' ) ?: $config->getDefaultGatewayTitle();
            $this->description = $this->get_option( 'description' ) ?: __( 'Pay in installments with tbi bank. <a href="#" id="tbi_check_plans">Check options</a>', 'fusion-pay-tbi' );
            $this->enabled     = $this->get_option( 'enabled' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $config = CountryRegistry::getInstance()->getConfig();

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'fusion-pay-tbi' ),
                    'label'   => __( 'Enable tbi checkout payment Gateway', 'fusion-pay-tbi' ),
                    'type'    => 'checkbox',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => __( 'Title', 'fusion-pay-tbi' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'fusion-pay-tbi' ),
                    'default'     => $config->getDefaultGatewayTitle(),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'fusion-pay-tbi' ),
                    'type'        => 'textarea',
                    'description' => __( 'This controls the description which the user sees during checkout.', 'fusion-pay-tbi' ),
                    'default'     => __( 'Pay in installments with tbi bank. <a href="#" id="tbi_check_plans">Check options</a>', 'fusion-pay-tbi' ),
                ),
            );
        }

        public function payment_fields() {
            if ( $this->description ) {
                echo wp_kses_post( wpautop( $this->description ) );
                wp_nonce_field( 'tbi_gateway_action', 'tbi_gateway_nonce' );
            }
        }

        public function validate_fields() {
            return true;
        }

        public function is_available() {
            if ( ! parent::is_available() ) {
                return false;
            }
            $reseller_code = get_option( 'fusipatb_reseller_code' );
            $reseller_key  = get_option( 'fusipatb_reseller_key' );
            if ( empty( $reseller_code ) || empty( $reseller_key ) ) {
                return false;
            }
            return true;
        }

        public function process_payment( $order_id ) {
            $not_blocks = isset( $_POST['order_button_text'] ) && sanitize_text_field( wp_unslash( $_POST['order_button_text'] ) ) === '';
            if ( $not_blocks ) {
                $nonce = isset( $_POST['tbi_gateway_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tbi_gateway_nonce'] ) ) : '';
                if ( ! wp_verify_nonce( $nonce, 'tbi_gateway_action' ) ) {
                    wc_add_notice( __( 'Error: Nonce verification failed', 'fusion-pay-tbi' ), 'error' );
                    return;
                }
            }

            $config        = CountryRegistry::getInstance()->getConfig();
            $order         = wc_get_order( $order_id );
            $items         = array();
            $items_count   = 0;
            $tbi_sale_promooff = false;
            $tax_check     = false;
            $tax_multiplier = 1;
            $tbi_sale_promooff_option = get_option( 'fusipatb_sale_promooff' );

            foreach ( WC()->cart->get_cart() as $item ) {
                $is_bundle_child      = isset( $item['bundled_by'] );
                $is_bundle_container  = ! empty( $item['bundled_items'] ) && is_array( $item['bundled_items'] );
                $line_total_incl_tax  = (float) ( isset( $item['line_total'] ) ? $item['line_total'] : 0 ) + (float) ( isset( $item['line_tax'] ) ? $item['line_tax'] : 0 );

                // Keep priced bundle children, skip only zero-priced rows.
                if ( ( $is_bundle_child || $is_bundle_container ) && $line_total_incl_tax <= 0 ) {
                    continue;
                }

                $product_obj = isset( $item['variation_id'] ) && $item['variation_id'] != 0
                    ? new WC_Product_Variation( $item['variation_id'] )
                    : new WC_Product( $item['product_id'] );

                if ( $tbi_sale_promooff_option == 1 && $product_obj->is_on_sale() ) {
                    $tbi_sale_promooff = true;
                }

                $img = wp_get_attachment_image_src( get_post_thumbnail_id( $item['product_id'] ), 'single-post-thumbnail' );

                $category = '1';
                if ( get_option( 'fusipatb_active_promo' ) !== '[]' && ! $tbi_sale_promooff ) {
                    $promo_array = json_decode( get_option( 'fusipatb_active_promo' ) );
                    if ( is_array( $promo_array ) ) {
                        foreach ( $promo_array as $scheme ) {
                            $prods = explode( ',', get_option( 'fusipatb_promo_products_' . $scheme->id ) );
                            $cats  = explode( ',', get_option( 'fusipatb_promo_categories_' . $scheme->id ) );
                            if ( in_array( $product_obj->get_id(), $prods ) ) {
                                $category = $scheme->category_id;
                                break;
                            }
                            if ( ! empty( $cats ) ) {
                                $product_cats = get_the_terms( $product_obj->get_id(), 'product_cat' );
                                if ( $product_cats ) {
                                    $cat_ids = array();
                                    foreach ( $product_cats as $c ) {
                                        $cat_ids[] = $c->term_taxonomy_id;
                                    }
                                    foreach ( $cats as $check_cat ) {
                                        if ( in_array( $check_cat, $cat_ids ) ) {
                                            $category = $scheme->category_id;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                $product_cart = $item['data'];
                $single_price = wc_get_price_including_tax( $product_cart );
                if ( $line_total_incl_tax > 0 && ! empty( $item['quantity'] ) ) {
                    $single_price = $line_total_incl_tax / (float) $item['quantity'];
                }
                $tm_epo_payload = TmEpoSupport::extractPayloadFromCartItem( $item );

                if ( ! $tax_check ) {
                    $price_incl   = wc_get_price_including_tax( $product_cart );
                    $price_excl   = wc_get_price_excluding_tax( $product_cart );
                    $tax_multiplier = $price_excl > 0 ? ( $price_incl - $price_excl > 0 ? 1 + ( $price_incl - $price_excl ) / $price_excl : 1 ) : 1;
                    $tax_check    = true;
                }

                $items[ $items_count ] = array(
                    'name'         => $product_obj->get_name(),
                    'description'  => '',
                    'qty'          => $item['quantity'],
                    'price'        => $single_price,
                    'sku'          => $product_obj->get_sku(),
                    'id'           => $item['product_id'],
                    'variation_id' => $item['variation_id'],
                    'category'     => $category,
                    'imagelink'    => isset( $img[0] ) ? urlencode( $img[0] ) : '',
                    'tm_epo_data'  => $tm_epo_payload['tm_epo_data'],
                    'tm_epo_original_price' => $tm_epo_payload['tm_epo_original_price'],
                    'tm_epo_options_prices' => $tm_epo_payload['tm_epo_options_prices'],
                );
                $items_count++;
            }

            $shipping_total    = $order->get_shipping_total();
            $shipping_tax      = $order->get_shipping_tax();
            $total_shipping    = $shipping_total + $shipping_tax;

            if ( $total_shipping > 0 ) {
                $items[] = array(
                    'name'         => FUSIPATB_SHIPPING_NAME,
                    'description'  => '',
                    'qty'          => 1,
                    'price'        => $total_shipping,
                    'sku'          => '348',
                    'id'           => '348',
                    'variation_id' => '348',
                    'category'     => '348',
                    'imagelink'    => '',
                );
            }

            $total_discount = $order->get_discount_total() * $tax_multiplier;
            if ( $total_discount > 0 ) {
                $items[] = array(
                    'name'         => FUSIPATB_COUPON_NAME,
                    'description'  => '',
                    'qty'          => 1,
                    'price'        => -$total_discount,
                    'sku'          => '999',
                    'id'           => '999',
                    'variation_id' => '999',
                    'category'     => '999',
                    'imagelink'    => '',
                );
            }

            $delivery_address = array(
                'city'       => sanitize_text_field( wp_unslash( isset( $_POST['billing_city'] ) ? $_POST['billing_city'] : '' ) ),
                'streetname' => sanitize_text_field( wp_unslash( ( isset( $_POST['billing_address_1'] ) ? $_POST['billing_address_1'] : '' ) . ' ' . ( isset( $_POST['billing_address_2'] ) ? $_POST['billing_address_2'] : '' ) ) ),
                'postalcode' => sanitize_text_field( wp_unslash( isset( $_POST['billing_postcode'] ) ? $_POST['billing_postcode'] : '' ) ),
            );

            if ( isset( $_POST['ship_to_different_address'] ) ) {
                $delivery_address = array(
                    'city'       => sanitize_text_field( wp_unslash( isset( $_POST['shipping_city'] ) ? $_POST['shipping_city'] : '' ) ),
                    'streetname' => sanitize_text_field( wp_unslash( ( isset( $_POST['shipping_address_1'] ) ? $_POST['shipping_address_1'] : '' ) . ' ' . ( isset( $_POST['shipping_address_2'] ) ? $_POST['shipping_address_2'] : '' ) ) ),
                    'postalcode' => sanitize_text_field( wp_unslash( isset( $_POST['shipping_postcode'] ) ? $_POST['shipping_postcode'] : '' ) ),
                );
            }

            $scheme_id   = WC()->session->get( 'fusipatb_scheme_id' );
            $downpayment = WC()->session->get( 'fusipatb_downpayment' );

            $application_array = array(
                'orderid'         => $order->get_order_number(),
                'wc_key'          => $order->get_order_key(),
                'firstname'       => sanitize_text_field( wp_unslash( isset( $_POST['billing_first_name'] ) ? $_POST['billing_first_name'] : '' ) ),
                'lastname'        => sanitize_text_field( wp_unslash( isset( $_POST['billing_last_name'] ) ? $_POST['billing_last_name'] : '' ) ),
                'email'           => sanitize_text_field( wp_unslash( isset( $_POST['billing_email'] ) ? $_POST['billing_email'] : '' ) ),
                'phone'           => sanitize_text_field( wp_unslash( isset( $_POST['billing_phone'] ) ? $_POST['billing_phone'] : '' ) ),
                'deliveryaddress' => $delivery_address,
                'items'           => $items,
            );

            if ( $scheme_id ) {
                $application_array['scheme_id'] = $scheme_id;
            }
            if ( $downpayment ) {
                $application_array['downpayment'] = $downpayment;
            }
            if ( $tbi_sale_promooff ) {
                $application_array['promoOff'] = 1;
            }

            if ( get_option( 'fusipatb_popup_checkout' ) == 1 ) {
                $order_received_url = wc_get_checkout_url();
                if ( strpos( $order_received_url, '?' ) !== false ) {
                    $order_received_url .= '&order-received=' . $order_id . '&key=' . $order->get_order_key();
                } else {
                    $order_received_url .= '/order-received/' . $order_id . '/?key=' . $order->get_order_key();
                }
                $application_array['successRedirectURL'] = urlencode( $order_received_url );
            }

            $posted_currency = isset( $_POST['currency'] ) && is_scalar( $_POST['currency'] ) ? strtoupper( trim( (string) wp_unslash( $_POST['currency'] ) ) ) : '';
            if ( preg_match( '/^[A-Z]{3}$/', $posted_currency ) ) {
                $currency = $posted_currency;
            } else {
                $currency = function_exists( 'fusipatb_get_current_currency' ) ? fusipatb_get_current_currency() : CurrencyHelper::getCurrentCurrency();
            }
            $application_array['currency'] = $currency;

            $application_data = json_encode( $application_array, JSON_UNESCAPED_UNICODE );
            $cryptor          = new Cryptor();
            $encrypted        = $cryptor->encryptString( $application_data, get_option( 'fusipatb_encryption_key' ) );

            $body_array = array(
                'reseller_code' => get_option( 'fusipatb_reseller_code' ),
                'reseller_key'  => get_option( 'fusipatb_reseller_key' ),
                'data'          => $encrypted,
            );

            $tbi_url  = FUSIPATB_DEFAULT_ERROR_PAGE . '?store=' . esc_url( site_url() );
            $body     = json_encode( $body_array, JSON_UNESCAPED_UNICODE );
            $args     = array(
                'body'    => $body,
                'timeout' => 30,
                'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            );

            $response = wp_remote_post( FUSIPATB_API_REGISTER_APPLICATION, $args );

            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $decoded = json_decode( wp_remote_retrieve_body( $response ) );
                if ( isset( $decoded->url ) ) {
                    $tbi_url = $decoded->url;
                }
            } else {
                $response_code = is_wp_error( $response ) ? 'wp_error' : (string) wp_remote_retrieve_response_code( $response );
                $response_body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
                $response_body = is_string( $response_body ) ? substr( $response_body, 0, 1200 ) : '';
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf( 'FusionPayTbi checkout RegisterApplication failed: code=%s order_id=%d total=%.2f currency=%s body=%s', $response_code, (int) $order_id, (float) $order->get_total(), (string) $currency, $response_body ) );
                }
            }

            $order->set_status( get_option( 'fusipatb_default_status' ), 'Order is created programmatically' );
            $order->update_meta_data( '_fusipatb_checkout_status', 'Draft' );
            $order->save();

            WC()->cart->empty_cart();

            if ( get_option( 'fusipatb_popup_checkout' ) == 1 ) {
                return array(
                    'result'   => 'success',
                    'redirect' => $tbi_url,
                );
            }

            return array(
                'result'   => 'success',
                'url'      => $tbi_url,
                'order_id' => $order->get_order_number(),
                'key'      => $order->get_order_key(),
            );
        }
    }

    // Backward-compat alias for one release cycle (WC stores gateway class name in DB).
    if ( ! class_exists( 'WC_tbi_GatewayFusion' ) ) {
        class_alias( 'Fusipatb_PaymentGateway', 'WC_tbi_GatewayFusion' );
    }
}



