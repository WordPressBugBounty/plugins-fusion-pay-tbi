<?php

namespace FusionPayTbi\Api;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Compat\TmEpoSupport;
use FusionPayTbi\Helpers\CurrencyHelper;
use FusionPayTbi\Helpers\SecurityHelper;
use FusionPayTbi\Helpers\Cryptor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CartProcessor {

    public static function handle() {
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            wp_send_json_error( array( 'message' => 'Invalid request' ) );
            return;
        }

        // Verify nonce for security
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'fusipatb_process_cart' ) ) {
            wp_send_json_error( array( 'message' => 'Security verification failed' ), 403 );
            return;
        }

        if ( ! SecurityHelper::isLocalOriginAllowed() ) {
            wp_send_json_error( array( 'message' => 'Access denied' ), 403 );
            return;
        }

        $registry      = CountryRegistry::getInstance();
        $config        = $registry->getConfig();
        $currency_info = self::resolveCurrencyInfo();
        $currency      = $currency_info['currency'];

        // Optional modal-preselect params coming from cart button JS.
        $posted_scheme_id = isset( $_POST['scheme_id'] ) ? absint( wp_unslash( $_POST['scheme_id'] ) ) : 0;
        $posted_modal_section = isset( $_POST['modal_section'] ) ? sanitize_text_field( wp_unslash( $_POST['modal_section'] ) ) : '';
        $posted_mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';
        $posted_default_installments = isset( $_POST['default_installments'] ) ? absint( wp_unslash( $_POST['default_installments'] ) ) : 0;
        $posted_default_installment_amount = isset( $_POST['default_installment_amount'] )
            ? floatval( sanitize_text_field( wp_unslash( $_POST['default_installment_amount'] ) ) )
            : 0.0;

        // Detect dual-currency BGN-admin + EUR-display setup so we can convert
        // item prices (which WooCommerce always returns in the admin currency).
        $dual_currency        = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $admin_currency       = $dual_currency ? intval( get_option( 'fusipatb_admin_currency', 2 ) ) : 0;
        $needs_eur_conversion = $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3;

        $items             = array();
        $items_count       = 0;
        $cart_total        = 0;
        $tbi_sale_promooff = false;
        $tbi_sale_promooff_option = get_option( 'fusipatb_sale_promooff' );

        $items_json      = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
        $items_array     = json_decode( $items_json );
        // json_decode() is not a sanitizer: coerce/validate every posted item's
        // numeric fields (and drop entries whose price cannot be trusted) before
        // anything below touches $item->price / ->quantity / ->product_id.
        $items_array     = self::normalizePostedItems( $items_array );
        // True only if an external caller explicitly POSTed items JSON.
        // Both cart page and checkout page now let CartProcessor build items
        // from the live WC session for consistency.
        $items_from_post = is_array( $items_array ) && ! empty( $items_array );

        $scheme_id   = WC()->session->get( 'fusipatb_scheme_id' );
        $downpayment = WC()->session->get( 'fusipatb_downpayment' );

        if ( is_array( $items_array ) ) {
            $items_array = self::enrichPostedItemsFromCart( $items_array );
        }

        if ( ! is_array( $items_array ) ) {
            // Fallback: build items from WC session cart (used when called from checkout page)
            $items_array = array();
            if ( WC()->cart ) {
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    if ( isset( $cart_item['bundled_by'] ) ) {
                        continue;
                    }
                    $product_obj = ( isset( $cart_item['variation_id'] ) && $cart_item['variation_id'] != 0 )
                        ? new \WC_Product_Variation( $cart_item['variation_id'] )
                        : new \WC_Product( $cart_item['product_id'] );
                    $tm_epo_payload = self::extractTmEpoPayload( $cart_item );
                    $items_array[] = (object) array(
                        'product_id'   => $cart_item['product_id'],
                        'variation_id' => isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0,
                        'quantity'     => $cart_item['quantity'],
                        'price'        => wc_get_price_including_tax( $product_obj ),
                        'tm_epo_data'  => $tm_epo_payload['tm_epo_data'],
                        'tm_epo_original_price' => $tm_epo_payload['tm_epo_original_price'],
                        'tm_epo_options_prices' => $tm_epo_payload['tm_epo_options_prices'],
                    );
                }
            }
            if ( empty( $items_array ) ) {
                wp_send_json_error( array( 'message' => 'Cart is empty' ) );
                return;
            }

            // Add coupon/discount as a negative line item so TBI sees the correct total.
            $discount_total = (float) WC()->cart->get_discount_total() + (float) WC()->cart->get_discount_tax();
            if ( $discount_total > 0 ) {
                $items_array[] = (object) array(
                    'product_id'   => 0,
                    'variation_id' => 0,
                    'quantity'     => 1,
                    'price'        => -$discount_total,
                );
            }
        }

        foreach ( $items_array as $item ) {
            // Discount/coupon items
            if ( $item->price < 0 ) {
                $items[ $items_count ] = array(
                    'name'         => FUSIPATB_COUPON_NAME,
                    'description'  => '',
                    'qty'          => $item->quantity,
                    'price'        => $item->price,
                    'sku'          => 0,
                    'id'           => $item->product_id,
                    'variation_id' => $item->variation_id,
                    'category'     => 0,
                    'imagelink'    => '',
                );
                $items_count++;
                $cart_total += $item->price;
                continue;
            }

            if ( isset( $item->variation_id ) && $item->variation_id != 0 ) {
                $product = new \WC_Product_Variation( $item->variation_id );
            } else {
                $product = new \WC_Product( $item->product_id );
            }

            $image = wp_get_attachment_image_src( get_post_thumbnail_id( $item->product_id ), 'single-post-thumbnail' );

            if ( $tbi_sale_promooff_option == 1 && $product->is_on_sale() ) {
                $tbi_sale_promooff = true;
            }

            $category = self::resolveCategory( $product, $tbi_sale_promooff );

            $tm_epo_data = array();
            $tm_epo_original_price = 0.0;
            $tm_epo_options_prices = 0.0;
            if ( isset( $item->tm_epo_data ) && is_array( $item->tm_epo_data ) ) {
                $tm_epo_data = $item->tm_epo_data;
            }
            if ( isset( $item->tm_epo_original_price ) ) {
                $tm_epo_original_price = floatval( $item->tm_epo_original_price );
            }
            if ( isset( $item->tm_epo_options_prices ) ) {
                $tm_epo_options_prices = floatval( $item->tm_epo_options_prices );
            }

            $items[ $items_count ] = array(
                'name'         => $product->get_name(),
                'description'  => '',
                'qty'          => $item->quantity,
                'price'        => $item->price,
                'sku'          => $product->get_sku(),
                'id'           => $item->product_id,
                'variation_id' => $item->variation_id,
                'category'     => $category,
                'imagelink'    => isset( $image[0] ) ? urlencode( $image[0] ) : '',
                'tm_epo_data'  => $tm_epo_data,
                'tm_epo_original_price' => $tm_epo_original_price,
                'tm_epo_options_prices' => $tm_epo_options_prices,
            );
            $items_count++;
            $cart_total += $item->quantity * $item->price;
        }

        // Delivery / shipping
        if ( $items_from_post ) {
            // Cart page: use plugin's custom delivery fee (amounts are in admin currency).
            $delivery_limit = floatval( get_option( 'fusipatb_delivery_limit' ) );
            if ( $delivery_limit > 0 && $cart_total < $delivery_limit ) {
                $fee = floatval( get_option( 'fusipatb_delivery_fee' ) ) + $cart_total * floatval( get_option( 'fusipatb_delivery_fee_prc' ) ) / 100;
                $items[ $items_count ] = array(
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
                $items_count++;
                $cart_total += $fee;
            }
        } elseif ( WC()->cart ) {
            // No items were POSTed — use the actual WC shipping from the session.
            // Ensure totals are calculated so shipping values are fresh in AJAX context.
            WC()->cart->calculate_totals();
            $shipping_total = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();
            if ( $shipping_total > 0 ) {
                $items[ $items_count ] = array(
                    'name'         => FUSIPATB_SHIPPING_NAME,
                    'description'  => '',
                    'qty'          => 1,
                    'price'        => $shipping_total,
                    'sku'          => '348',
                    'id'           => '348',
                    'variation_id' => '348',
                    'category'     => '348',
                    'imagelink'    => '',
                );
                $items_count++;
                $cart_total += $shipping_total;
            }
        }

        // Convert all item prices and the running total from BGN to EUR when the
        // admin currency is BGN but the customer is browsing in EUR.
        // WooCommerce always stores/returns amounts in the admin currency, so all
        // prices (from POST or WC session) are in BGN in a dual-currency BG setup.
        if ( $needs_eur_conversion ) {
            foreach ( $items as $idx => $item ) {
                $items[ $idx ]['price'] = CurrencyHelper::bgnToEur( (float) $item['price'] );
            }
            $cart_total = CurrencyHelper::bgnToEur( $cart_total );
        }

        // For RO: use our computed total (includes actual shipping, correctly converted).
        $tbi_cart_total = $cart_total;

        $application_array = array(
            'orderid'         => '0',
            'firstname'       => '',
            'lastname'        => '',
            'email'           => '',
            'phone'           => '',
            'deliveryaddress' => array( 'city' => '', 'streetname' => '', 'postalcode' => '' ),
            'items'           => $items,
            'currency'        => $currency,
        );

        if ( $tbi_sale_promooff ) {
            $application_array['promoOff'] = 1;
        }

        if ( $scheme_id ) {
            $application_array['scheme_id'] = $scheme_id;
        }
        if ( $posted_scheme_id > 0 ) {
            // Explicit widget-selected scheme should win over session/default.
            $application_array['scheme_id'] = $posted_scheme_id;
        }
        if ( $downpayment ) {
            $application_array['downpayment'] = $downpayment;
        }

        if ( $posted_modal_section !== '' ) {
            $application_array['modal_section'] = $posted_modal_section;
        }
        if ( $posted_mode !== '' ) {
            $application_array['mode'] = $posted_mode;
        }
        if ( $posted_default_installments > 0 ) {
            $application_array['default_installments'] = $posted_default_installments;
        }
        if ( $posted_default_installment_amount > 0 ) {
            $application_array['default_installment_amount'] = number_format( $posted_default_installment_amount, 2, '.', '' );
        }

        // Keep popup defaults aligned with the financing widget:
        // when no scheme is selected in session yet, use the scheme returned by
        // the same installments API for the exact amount/currency we send.
        if ( empty( $application_array['scheme_id'] ) ) {
            $button_data = InstallmentCalculator::calculate( $tbi_cart_total, $currency );
            if ( $button_data && ! empty( $button_data['success'] ) && ! empty( $button_data['data'] ) && is_array( $button_data['data'] ) ) {
                if ( isset( $button_data['data']['scheme_id'] ) && $button_data['data']['scheme_id'] !== null && $button_data['data']['scheme_id'] !== '' ) {
                    $application_array['scheme_id'] = sanitize_text_field( (string) $button_data['data']['scheme_id'] );
                }
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'FusionPayTbi cart scheme debug: ' . wp_json_encode( array(
                'currency'  => $currency,
                'amount'    => round( (float) $tbi_cart_total, 2 ),
                'scheme_id' => isset( $application_array['scheme_id'] ) ? $application_array['scheme_id'] : '',
            ) ) );
        }

        // For Romania, use calculator endpoint directly
        if ( $config->getCountryCode() === 'RO' ) {
            $page_context = self::resolveRoPageContext();
            $tbi_url = self::buildRoCalculatorUrl( $tbi_cart_total, $currency, $page_context );
        } else {
            // For Bulgaria/Greece, use encrypted application endpoint
            $application_data = json_encode( $application_array, JSON_UNESCAPED_UNICODE );
            $cryptor          = new Cryptor();
            $encrypted        = $cryptor->encryptString( $application_data, get_option( 'fusipatb_encryption_key' ) );

            $body_array = array(
                'reseller_code' => get_option( 'fusipatb_reseller_code' ),
                'reseller_key'  => get_option( 'fusipatb_reseller_key' ),
                'data'          => $encrypted,
            );

            $tbi_url  = FUSIPATB_DEFAULT_ERROR_PAGE . '?store=' . esc_url( site_url() );
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

        // Mirror product behavior by propagating modal preselect parameters in URL,
        // so the external calculator can apply the same preselected scheme/period.
        $popup_query_args = array();
        if ( isset( $application_array['scheme_id'] ) && $application_array['scheme_id'] ) {
            $popup_query_args['modal_section'] = 'selected';
            $popup_query_args['scheme_id']     = $application_array['scheme_id'];
        } elseif ( $posted_modal_section !== '' ) {
            $popup_query_args['modal_section'] = $posted_modal_section;
        }
        if ( $posted_mode !== '' ) {
            $popup_query_args['mode'] = $posted_mode;
        }
        if ( $posted_default_installments > 0 ) {
            $popup_query_args['default_installments'] = $posted_default_installments;
        }
        if ( $posted_default_installment_amount > 0 ) {
            $popup_query_args['default_installment_amount'] = number_format( $posted_default_installment_amount, 2, '.', '' );
        }
        if ( ! empty( $popup_query_args ) ) {
            $tbi_url = add_query_arg( $popup_query_args, $tbi_url );
        }

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
            'scheme_id' => isset( $application_array['scheme_id'] ) ? $application_array['scheme_id'] : '',
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
     * Resolve RO page context for add-to-cart button visibility.
     *
     * @return string One of product|cart|checkout.
     */
    private static function resolveRoPageContext() {
        if ( isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ) {
            $posted = strtolower( trim( (string) wp_unslash( $_POST['page'] ) ) );
            if ( in_array( $posted, array( 'product', 'cart', 'checkout' ), true ) ) {
                return $posted;
            }
        }

        $referer = isset( $_SERVER['HTTP_REFERER'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) ) : '';
        if ( strpos( $referer, 'checkout' ) !== false ) {
            return 'checkout';
        }
        if ( strpos( $referer, 'cart' ) !== false || strpos( $referer, 'basket' ) !== false ) {
            return 'cart';
        }

        return 'cart';
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

        $button_data = InstallmentCalculator::calculate( $amount, $currency );
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
     * Normalize/validate items POSTed as JSON, immediately after json_decode().
     *
     * json_decode() only parses JSON — it is not a sanitizer. Without this step the
     * decoded stdClass fields (price, quantity, product_id, variation_id) would flow
     * straight into cart-total arithmetic and into `new \WC_Product(...)` /
     * `new \WC_Product_Variation(...)` with no casting or validation of
     * client-controlled AJAX input.
     *
     * Note: `$item->price < 0` (see the foreach below) is intentional business logic
     * (a discount/coupon line), not an error condition, and is left untouched here.
     * What IS rejected is an item whose price cannot be trusted as a real number at
     * all (missing, or non-numeric such as "abc") — letting floatval() silently
     * coerce that to 0 would make it pass through as a seemingly legitimate item.
     *
     * @param mixed $items_array Decoded JSON payload (expected: array of objects).
     * @return mixed The same value unchanged when it is not an array (so callers'
     *                existing is_array() checks route it to their fallback logic);
     *                otherwise a re-indexed array of normalized item objects with
     *                any malformed entries dropped.
     */
    private static function normalizePostedItems( $items_array ) {
        if ( ! is_array( $items_array ) ) {
            return $items_array;
        }

        $normalized = array();

        foreach ( $items_array as $item ) {
            $normalized_item = self::normalizePostedItem( $item );
            if ( null !== $normalized_item ) {
                $normalized[] = $normalized_item;
            }
        }

        return $normalized;
    }

    /**
     * Normalize a single decoded posted item, or return null when it cannot be
     * trusted (wrong shape, or no resolvable price).
     *
     * @param mixed $item
     * @return object|null
     */
    private static function normalizePostedItem( $item ) {
        if ( is_array( $item ) ) {
            $item = (object) $item;
        }

        if ( ! is_object( $item ) ) {
            return null;
        }

        if ( ! isset( $item->price ) || ! is_numeric( $item->price ) ) {
            return null;
        }

        $item->price        = floatval( $item->price );
        $item->product_id   = isset( $item->product_id ) ? absint( $item->product_id ) : 0;
        $item->variation_id = isset( $item->variation_id ) ? absint( $item->variation_id ) : 0;
        $item->quantity     = max( 1, isset( $item->quantity ) ? absint( $item->quantity ) : 1 );

        return $item;
    }

    /**
     * Enrich cart items posted from the frontend with TM EPO metadata from the live WC cart.
     *
     * The cart page JS sends a compact JSON payload that does not include extra option
     * metadata. Match those items back to the current cart so TM EPO data survives into
     * the application payload and later order creation flow.
     *
     * @param array<int,object> $items_array
     * @return array<int,object>
     */
    private static function enrichPostedItemsFromCart( $items_array ) {
        if ( ! WC()->cart ) {
            return $items_array;
        }

        $cart_index = array();
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['bundled_by'] ) ) {
                continue;
            }

            $product_id   = isset( $cart_item['product_id'] ) ? intval( $cart_item['product_id'] ) : 0;
            $variation_id = isset( $cart_item['variation_id'] ) ? intval( $cart_item['variation_id'] ) : 0;
            $quantity     = isset( $cart_item['quantity'] ) ? intval( $cart_item['quantity'] ) : 0;
            $lookup_key   = $product_id . '|' . $variation_id . '|' . $quantity;

            if ( ! isset( $cart_index[ $lookup_key ] ) ) {
                $cart_index[ $lookup_key ] = array();
            }
            $cart_index[ $lookup_key ][] = $cart_item;
        }

        foreach ( $items_array as $index => $item ) {
            if ( ! is_object( $item ) ) {
                continue;
            }

            $product_id   = isset( $item->product_id ) ? intval( $item->product_id ) : 0;
            $variation_id = isset( $item->variation_id ) ? intval( $item->variation_id ) : 0;
            $quantity     = isset( $item->quantity ) ? intval( $item->quantity ) : 0;
            $lookup_key   = $product_id . '|' . $variation_id . '|' . $quantity;

            if ( empty( $cart_index[ $lookup_key ] ) ) {
                continue;
            }

            $cart_item = array_shift( $cart_index[ $lookup_key ] );
            $tm_epo_payload = self::extractTmEpoPayload( $cart_item );

            $items_array[ $index ]->tm_epo_data = $tm_epo_payload['tm_epo_data'];
            $items_array[ $index ]->tm_epo_original_price = $tm_epo_payload['tm_epo_original_price'];
            $items_array[ $index ]->tm_epo_options_prices = $tm_epo_payload['tm_epo_options_prices'];
        }

        return $items_array;
    }

    /**
     * Extract TM EPO payload from a cart item with backward compatibility for older deployments.
     *
     * @param array<string,mixed> $cart_item
     * @return array<string,mixed>
     */
    private static function extractTmEpoPayload( $cart_item ) {
        if ( method_exists( TmEpoSupport::class, 'extractPayloadFromCartItem' ) ) {
            return TmEpoSupport::extractPayloadFromCartItem( $cart_item );
        }

        $payload = array(
            'tm_epo_data'           => array(),
            'tm_epo_original_price' => 0.0,
            'tm_epo_options_prices' => 0.0,
        );

        if ( ! is_array( $cart_item ) ) {
            return $payload;
        }

        if ( ! empty( $cart_item['tmcartepo'] ) && is_array( $cart_item['tmcartepo'] ) ) {
            $payload['tm_epo_data'] = $cart_item['tmcartepo'];
        }

        if ( isset( $cart_item['tm_epo_product_original_price'] ) ) {
            $payload['tm_epo_original_price'] = floatval( $cart_item['tm_epo_product_original_price'] );
        }

        if ( isset( $cart_item['tm_epo_options_prices'] ) ) {
            $payload['tm_epo_options_prices'] = floatval( $cart_item['tm_epo_options_prices'] );
        }

        return $payload;
    }
}



