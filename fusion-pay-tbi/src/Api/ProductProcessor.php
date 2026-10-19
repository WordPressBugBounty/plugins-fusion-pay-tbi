<?php

namespace FusionPayTbi\Api;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\SecurityHelper;
use FusionPayTbi\Helpers\CurrencyHelper;
use FusionPayTbi\Helpers\Cryptor;
use FusionPayTbi\Compat\WcpaSupport;
use FusionPayTbi\Compat\WapfSupport;
use FusionPayTbi\Compat\TmEpoSupport;
use FusionPayTbi\Api\InstallmentCalculator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProductProcessor {

    public static function handle() {
        ob_start();

        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            wp_send_json_error( array( 'message' => 'Invalid request' ) );
            return;
        }

        // Verify nonce for security
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'fusipatb_process_product' ) ) {
            wp_send_json_error( array( 'message' => 'Security verification failed' ), 403 );
            return;
        }

        if ( ! SecurityHelper::isLocalOriginAllowed() ) {
            wp_send_json_error( array( 'message' => 'Access denied' ), 403 );
            return;
        }

        $registry = CountryRegistry::getInstance();
        $config   = $registry->getConfig();

        $add_to_cart_id = isset( $_POST['add-to-cart'] ) ? absint( $_POST['add-to-cart'] ) : 0;

        if ( ! isset( $_POST['variation_id'] ) ) {
            $single_product      = new \WC_Product( $add_to_cart_id );
            $single_variation_id = 0;
        } else {
            $single_product      = new \WC_Product_Variation( intval( $_POST['variation_id'] ) );
            $single_variation_id = intval( $_POST['variation_id'] );
        }

        $single_quantity       = isset( $_POST['quantity'] ) ? intval( $_POST['quantity'] ) : 1;
        $single_image          = wp_get_attachment_image_src( get_post_thumbnail_id( $add_to_cart_id ), 'single-post-thumbnail' );
        $single_product_image  = isset( $single_image[0] ) ? urlencode( $single_image[0] ) : '';

        $items        = array();
        $items_count  = 0;
        $tbi_sale_promooff = false;
        $tbi_sale_promooff_option = get_option( 'fusipatb_sale_promooff' );

        if ( $tbi_sale_promooff_option == 1 && $single_product->is_on_sale() ) {
            $tbi_sale_promooff = true;
        }

        $category = self::resolveCategory( $single_product, $tbi_sale_promooff );

        $product_final_price = wc_get_price_including_tax( $single_product );
        $currency_info       = self::resolveCurrencyInfo();
        $currency            = $currency_info['currency'];
        $tm_epo_data         = array();
        $tm_epo_original_price = $product_final_price;
        $tm_epo_options_price  = 0.0;
        $tm_epo_summary        = '';

        // TM EPO backend-normalized option data and price resolution.
        if ( TmEpoSupport::isActive() ) {
            $tm_epo_payload = TmEpoSupport::buildCartItemData(
                $add_to_cart_id,
                $single_product,
                $single_quantity,
                TmEpoSupport::sanitizePostData( wp_unslash( $_POST ) )
            );

            if ( ! empty( $tm_epo_payload ) ) {
                $tm_epo_data          = $tm_epo_payload['tmcartepo'];
                $tm_epo_summary       = $tm_epo_payload['summary'];
                $tm_epo_options_price = $tm_epo_payload['options_price'];

                if ( ! empty( $tm_epo_payload['original_price'] ) ) {
                    $tm_epo_original_price = $tm_epo_payload['original_price'];
                }
                if ( ! empty( $tm_epo_payload['product_price_with_options'] ) ) {
                    $product_final_price = $tm_epo_payload['product_price_with_options'];
                }
            } else {
                $product_final_price = TmEpoSupport::applyPrice( $product_final_price );
            }
        }

        // WCPA price override (takes priority over TmEpo if both somehow present)
        if ( $registry->hasFeature( FeatureFlags::WCPA_SUPPORT ) ) {
            $product_final_price = WcpaSupport::applyPrice( $product_final_price );
        }

        $final_product_name = $single_product->get_name();

        // Extra name from POST (JS-provided) or backend-normalized TM EPO data.
        $extra_name = '';
        if ( ! empty( $_POST['extra_name'] ) ) {
            $extra_name = sanitize_text_field( wp_unslash( $_POST['extra_name'] ) );
        } elseif ( $tm_epo_summary !== '' ) {
            $extra_name = $tm_epo_summary;
        } elseif ( TmEpoSupport::isActive() ) {
            $extra_name = TmEpoSupport::extractOptionNames();
        }
        if ( $extra_name !== '' ) {
            $final_product_name .= ' (' . $extra_name . ')';
        }
        // extra_price from JS is 0 when TMCP is active (price already in cpf_product_price)
        if ( ! TmEpoSupport::isActive() && isset( $_POST['extra_price'] ) ) {
            $extra_price = floatval( sanitize_text_field( wp_unslash( $_POST['extra_price'] ) ) );
            if ( $extra_price > 0 ) {
                $product_final_price += $extra_price;
            }
        }

        // WAPF processing
        if ( $registry->hasFeature( FeatureFlags::WAPF_SUPPORT ) ) {
            $wapf = WapfSupport::processFields( $product_final_price, $final_product_name );
            $product_final_price = $wapf['price'];
            $final_product_name  = $wapf['name'];
            $wapf_options        = $wapf['options'];
        } else {
            $wapf_options = array();
        }

        // WooCommerce Product Bundles may have parent/base price 0 and rely on
        // a live bundle total computed in the product form. We cannot use
        // $single_product->is_type('bundle') here because new \WC_Product($id)
        // bypasses WooCommerce's factory and the type is not reliably set.
        // bundle_total is only appended by our JS for bundle_form products, so
        // checking its presence is sufficient.
        if ( isset( $_POST['bundle_total'] ) ) {
            $bundle_total = floatval( sanitize_text_field( wp_unslash( $_POST['bundle_total'] ) ) );
            if ( $bundle_total > 0 ) {
                $product_final_price = $bundle_total;
            }
        }

        $items[ $items_count ] = array(
            'name'         => $final_product_name,
            'description'  => '',
            'qty'          => $single_quantity,
            'price'        => $product_final_price,
            'sku'          => $single_product->get_sku(),
            'id'           => $add_to_cart_id,
            'variation_id' => $single_variation_id,
            'category'     => $category,
            'imagelink'    => $single_product_image,
            'tm_epo_data'  => $tm_epo_data,
            'tm_epo_original_price' => $tm_epo_original_price,
            'tm_epo_options_prices' => $tm_epo_options_price,
            'wapf_options' => $wapf_options,
        );

        // Use the fully-resolved price (includes TMCP/WCPA/WAPF overrides) so
        // the delivery threshold check is accurate.
        $cart_total = $single_quantity * floatval( $product_final_price );

        // WooCommerce Bundles (woobt)
        if ( isset( $_POST['woobt_ids'] ) ) {
            $woobt_ids    = sanitize_text_field( wp_unslash( $_POST['woobt_ids'] ) );
            $added_prods  = explode( ',', $woobt_ids );
            $product_meta = get_post_meta( $add_to_cart_id, 'woobt_ids', true );

            foreach ( $added_prods as $added_product ) {
                $items_count++;
                $parts   = explode( '/', $added_product );
                $prod_id = $parts[0];

                $extra_product = new \WC_Product( intval( $prod_id ) );

                // Find qty (last non-empty part)
                $reversed = array_reverse( $parts );
                $extra_qty = '';
                foreach ( $reversed as $v ) {
                    if ( $v !== '' ) {
                        $extra_qty = $v;
                        break;
                    }
                }

                // Discount ratio from bundle meta
                $discount_ratio = 1.0;
                if ( is_array( $product_meta ) ) {
                    foreach ( $product_meta as $bundle ) {
                        if ( isset( $bundle['id'] ) && $bundle['id'] === $prod_id ) {
                            $discount_ratio = (float) rtrim( $bundle['price'], '%' ) / 100;
                            break;
                        }
                    }
                }

                $price_raw       = wc_get_price_including_tax( $extra_product );
                $discounted_price = $price_raw * $discount_ratio;

                $img = wp_get_attachment_image_src( get_post_thumbnail_id( $prod_id ), 'single-post-thumbnail' );

                $items[ $items_count ] = array(
                    'name'        => $extra_product->get_name(),
                    'description' => '',
                    'qty'         => $extra_qty,
                    'price'       => $discounted_price,
                    'sku'         => $extra_product->get_sku(),
                    'id'          => $prod_id,
                    'variation_id' => 0,
                    'category'    => $category,
                    'imagelink'   => isset( $img[0] ) ? urlencode( $img[0] ) : '',
                );

                $cart_total += intval( $extra_qty ) * floatval( $discounted_price );
            }
        }

        // WCPA calculator total (may override cart total for multi-item scenarios)
        $calculator_total = WcpaSupport::getCalculatorTotal( $cart_total );

        // Delivery
        $delivery_limit = floatval( get_option( 'fusipatb_delivery_limit' ) );
        if ( $delivery_limit > 0 && $cart_total < $delivery_limit ) {
            $fee = floatval( get_option( 'fusipatb_delivery_fee' ) ) + $cart_total * floatval( get_option( 'fusipatb_delivery_fee_prc' ) ) / 100;
            $items[ $items_count + 1 ] = array(
                'name'         => FUSIPATB_SHIPPING_NAME,
                'description'  => '',
                'qty'          => 1,
                'price'        => $fee,
                'sku'          => '348',
                'id'           => '348',
                'variation_id' => '348',
                'category'     => '348',
                'imagelink'    => '',
            );
            $calculator_total += $fee;
        }

        $application_array = array(
            'orderid'         => '0',
            'firstname'       => '',
            'lastname'        => '',
            'email'           => '',
            'phone'           => '',
            'deliveryaddress' => array( 'city' => '', 'streetname' => '', 'postalcode' => '' ),
            'items'           => $items,
        );

        if ( $tbi_sale_promooff ) {
            $application_array['promoOff'] = 1;
        }

        $application_array['currency'] = $currency;

        $tbi_url = FUSIPATB_DEFAULT_ERROR_PAGE . '?store=' . esc_url( site_url() );

        // Romania always uses the calculator endpoint directly.
        // For other countries, respect the product_button admin setting.
        if ( $config->getCountryCode() === 'RO' ) {
            $tbi_url = self::buildRoCalculatorUrl( $cart_total, $currency, 'product' );
        } elseif ( intval( get_option( 'fusipatb_product_button', 1 ) ) === 2 ) {
            $tbi_url = self::buildCalculatorUrl( $calculator_total, $currency );
        } else {
            $application_data = json_encode( $application_array, JSON_UNESCAPED_UNICODE );
            $cryptor          = new Cryptor();
            $encrypted        = $cryptor->encryptString( $application_data, get_option( 'fusipatb_encryption_key' ) );

            $body_array = array(
                'reseller_code' => get_option( 'fusipatb_reseller_code' ),
                'reseller_key'  => get_option( 'fusipatb_reseller_key' ),
                'data'          => $encrypted,
            );

            $response = wp_remote_post( FUSIPATB_API_REGISTER_APPLICATION, array(
                'body'    => json_encode( $body_array, JSON_UNESCAPED_UNICODE ),
                'timeout' => 30,
                'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            ) );


            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $decoded = json_decode( wp_remote_retrieve_body( $response ) );
                if ( isset( $decoded->url ) ) {
                    $tbi_url = $decoded->url;
                }
            }
        }

        ob_end_clean();

        $debug_amount = 0.0;
        foreach ( $application_array['items'] as $debug_item ) {
            $debug_qty   = isset( $debug_item['qty'] ) ? (float) $debug_item['qty'] : 1;
            $debug_price = isset( $debug_item['price'] ) ? (float) $debug_item['price'] : 0.0;
            $debug_amount += $debug_qty * $debug_price;
        }

        $debug_payload = array(
            'endpoint' => $config->getCountryCode() === 'RO' ? 'calculator' : 'application',
            'currency' => $application_array['currency'],
            'currency_source' => $currency_info['source'],
            'amount'   => round( $debug_amount, 2 ),
            'items'    => array_map( function( $item ) {
                return array(
                    'name'  => isset( $item['name'] ) ? $item['name'] : '',
                    'qty'   => isset( $item['qty'] ) ? $item['qty'] : 1,
                    'price' => isset( $item['price'] ) ? $item['price'] : 0,
                );
            }, $application_array['items'] ),
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'FusionPayTbi iframe payload: ' . wp_json_encode( $debug_payload ) );
        }

        wp_send_json_success( array(
            'url'   => $tbi_url,
            'debug' => $debug_payload,
        ) );
    }

    /**
     * Resolve the promo category for a product.
     *
     * @param \WC_Product $product
     * @param bool        $promooff
     * @return string
     */
    private static function resolveCategory( $product, $promooff ) {
        $category = '1';

        if ( get_option( 'fusipatb_active_promo' ) === '[]' || $promooff ) {
            return $category;
        }

        $promo_array = json_decode( get_option( 'fusipatb_active_promo' ) );
        if ( ! is_array( $promo_array ) ) {
            return $category;
        }

        foreach ( $promo_array as $promo_scheme ) {
            $products_list   = explode( ',', get_option( 'fusipatb_promo_products_' . $promo_scheme->id ) );
            $categories_list = explode( ',', get_option( 'fusipatb_promo_categories_' . $promo_scheme->id ) );

            if ( in_array( $product->get_id(), $products_list ) ) {
                return $promo_scheme->category_id;
            }

            if ( ! empty( $categories_list ) ) {
                $product_cats = get_the_terms( $product->get_id(), 'product_cat' );
                if ( $product_cats ) {
                    $cat_ids = array();
                    foreach ( $product_cats as $cat ) {
                        $cat_ids[] = $cat->term_taxonomy_id;
                    }
                    foreach ( $categories_list as $check_cat ) {
                        if ( in_array( $check_cat, $cat_ids ) ) {
                            return $promo_scheme->category_id;
                        }
                    }
                }
            }
        }

        return $category;
    }

    private static function resolveCurrencyInfo() {
        $currency_info   = CurrencyHelper::getCurrentCurrencyInfo();
        $posted_currency = isset( $_POST['currency'] ) && is_scalar( $_POST['currency'] ) ? strtoupper( trim( (string) wp_unslash( $_POST['currency'] ) ) ) : '';

        if ( preg_match( '/^[A-Z]{3}$/', $posted_currency ) ) {
            $currency_info['currency'] = $posted_currency;
            $currency_info['source']   = 'post:currency';
        }

        return $currency_info;
    }

    /**
     * Build calculator URL with the same modal configuration returned by the render-button API.
     *
     * @param float  $amount
     * @param string $currency
     * @return string
     */
    private static function buildCalculatorUrl( $amount, $currency ) {
        $query_params = array(
            'code'     => get_option( 'fusipatb_reseller_code' ),
            'key'      => get_option( 'fusipatb_reseller_key' ),
            'amount'   => $amount,
            'currency' => $currency
        );

        $button_data = InstallmentCalculator::calculate( $amount );
        if ( $button_data && ! empty( $button_data['success'] ) && ! empty( $button_data['data'] ) && is_array( $button_data['data'] ) ) {
            $api_data = $button_data['data'];

            if ( isset( $api_data['scheme_id'] ) && $api_data['scheme_id'] !== null && $api_data['scheme_id'] !== '' ) {
                $query_params['modal_section'] = 'selected';
                $query_params['scheme_id']     = $api_data['scheme_id'];
            } elseif ( isset( $api_data['modal_section'] ) && $api_data['modal_section'] !== null && $api_data['modal_section'] !== '' ) {
                $query_params['modal_section'] = $api_data['modal_section'];
            }

            foreach ( array( 'mode', 'default_installments', 'default_installment_amount' ) as $key ) {
                if ( isset( $api_data[ $key ] ) && $api_data[ $key ] !== null && $api_data[ $key ] !== '' ) {
                    $query_params[ $key ] = $api_data[ $key ];
                }
            }
        }

        return add_query_arg( $query_params, FUSIPATB_CALC_URL );
    }

    /**
     * Build RO calculator URL with modal defaults and explicit page context.
     *
     * @param float  $amount
     * @param string $currency
     * @param string $page
     * @return string
     */
    private static function buildRoCalculatorUrl( $amount, $currency, $page ) {
        $query_params = array(
            'code'     => get_option( 'fusipatb_reseller_code' ),
            'key'      => get_option( 'fusipatb_reseller_key' ),
            'amount'   => number_format( (float) $amount, 2, '.', '' ),
            'currency' => $currency,
            'origin'   => 'plugin',
            'page'     => $page,
        );

        $button_data = InstallmentCalculator::calculate( $amount );
        if ( $button_data && ! empty( $button_data['success'] ) && ! empty( $button_data['data'] ) && is_array( $button_data['data'] ) ) {
            $api_data = $button_data['data'];

            if ( isset( $api_data['scheme_id'] ) && $api_data['scheme_id'] !== null && $api_data['scheme_id'] !== '' ) {
                $query_params['modal_section'] = 'selected';
                $query_params['scheme_id']     = $api_data['scheme_id'];
            } elseif ( isset( $api_data['modal_section'] ) && $api_data['modal_section'] !== null && $api_data['modal_section'] !== '' ) {
                $query_params['modal_section'] = $api_data['modal_section'];
            }

            foreach ( array( 'mode', 'default_installments', 'default_installment_amount' ) as $key ) {
                if ( isset( $api_data[ $key ] ) && $api_data[ $key ] !== null && $api_data[ $key ] !== '' ) {
                    $query_params[ $key ] = $api_data[ $key ];
                }
            }
        }

        return add_query_arg( $query_params, FUSIPATB_CALC_URL );
    }
}



