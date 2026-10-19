<?php

namespace FusionPayTbi\Frontend;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Popup {

    public static function render() {
        if ( ! get_option( 'fusipatb_checkout_status' ) ) {
            return;
        }

        $on_product  = is_product() && get_option( 'fusipatb_product_checked' ) != 0;
        $on_cart     = is_cart() && get_option( 'fusipatb_cart_checked' ) != 0;
        $on_checkout = is_checkout();

        if ( ! $on_product && ! $on_cart && ! $on_checkout ) {
            return;
        }

        $registry      = CountryRegistry::getInstance();
        $config        = $registry->getConfig();
        $dual_currency = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $is_ro_country = strtoupper( (string) $config->getCountryCode() ) === 'RO';

        $currency       = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $config->getCurrency();
        $admin_currency = $dual_currency ? intval( get_option( 'fusipatb_admin_currency', 2 ) ) : 0;

        $calculatorURL = '';

        if ( $on_checkout ) {
            $tbi_sale_promooff        = false;
            $tbi_sale_promooff_option = get_option( 'fusipatb_sale_promooff' );
            $category                 = 0;

            foreach ( WC()->cart->get_cart() as $item ) {
                if ( isset( $item['bundled_by'] ) ) {
                    continue;
                }

                if ( isset( $item['variation_id'] ) && $item['variation_id'] != 0 ) {
                    $product = new \WC_Product_Variation( $item['variation_id'] );
                } else {
                    $product = new \WC_Product( $item['product_id'] );
                }

                if ( $tbi_sale_promooff_option == 1 && $product->is_on_sale() ) {
                    $tbi_sale_promooff = true;
                    break;
                }

                $category = 0;
                if ( get_option( 'fusipatb_active_promo' ) != '[]' && ! $tbi_sale_promooff ) {
                    $promo_array = json_decode( get_option( 'fusipatb_active_promo' ) );
                    if ( is_array( $promo_array ) ) {
                        foreach ( $promo_array as $promo_scheme ) {
                            $prods = explode( ',', get_option( 'fusipatb_promo_products_' . $promo_scheme->id ) );
                            $cats  = explode( ',', get_option( 'fusipatb_promo_categories_' . $promo_scheme->id ) );

                            if ( in_array( $product->get_id(), $prods ) ) {
                                $category = $promo_scheme->category_id;
                                break;
                            }
                            if ( ! empty( $cats ) ) {
                                $product_cats = get_the_terms( $product->get_id(), 'product_cat' );
                                if ( $product_cats ) {
                                    $cat_ids = array();
                                    foreach ( $product_cats as $c ) {
                                        $cat_ids[] = $c->term_taxonomy_id;
                                    }
                                    foreach ( $cats as $check_cat ) {
                                        if ( in_array( $check_cat, $cat_ids ) ) {
                                            $category = $promo_scheme->category_id;
                                            break 2;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $tbi_cart_total = WC()->cart->total;
            // When admin currency is BGN but display is EUR, convert the cart total
            if ( $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3 && $tbi_cart_total > 0 ) {
                $tbi_cart_total = CurrencyHelper::bgnToEur( (float) $tbi_cart_total );
            }
            $calculatorURL  = FUSIPATB_CALC_URL . '?code=' . get_option( 'fusipatb_reseller_code' ) . '&key=' . get_option( 'fusipatb_reseller_key' ) . '&amount=' . $tbi_cart_total . '&currency=' . $currency . '&origin=plugin';
            if ( $is_ro_country ) {
                $calculatorURL .= '&page=checkout';
            }

            if ( $tbi_sale_promooff ) {
                $calculatorURL .= '&promooff=1';
            }
            if ( isset( $category ) && $category != 0 ) {
                $calculatorURL .= '&category=' . $category;
            }
        } elseif ( $on_product ) {
            $product       = wc_get_product();
            $price         = wc_get_price_including_tax( $product );
            // When admin currency is BGN but display is EUR, convert the price
            if ( $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3 && $price > 0 ) {
                $price = CurrencyHelper::bgnToEur( (float) $price );
            }
            $calculatorURL = FUSIPATB_CALC_URL . '?code=' . get_option( 'fusipatb_reseller_code' ) . '&key=' . get_option( 'fusipatb_reseller_key' ) . '&amount=' . $price . '&currency=' . $currency . '&origin=plugin';
            if ( $is_ro_country ) {
                $calculatorURL .= '&page=product';
            }
        } elseif ( $on_cart ) {
            $cart_total    = WC()->cart->total;
            // When admin currency is BGN but display is EUR, convert the cart total
            if ( $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3 && $cart_total > 0 ) {
                $cart_total = CurrencyHelper::bgnToEur( (float) $cart_total );
            }
            $calculatorURL = FUSIPATB_CALC_URL . '?code=' . get_option( 'fusipatb_reseller_code' ) . '&key=' . get_option( 'fusipatb_reseller_key' ) . '&amount=' . $cart_total . '&currency=' . $currency . '&origin=plugin';
            if ( $is_ro_country ) {
                $calculatorURL .= '&page=cart';
            }
        }

        $rest_route = get_rest_url();
        $site_url   = site_url();
        $rest_route = str_replace( $site_url, '', $rest_route );

        // Popup loan limits
        $popup_min_amount = self::getPopupMin( $currency, $admin_currency, $dual_currency );
        $popup_max_amount = self::getPopupMax( $currency, $admin_currency, $dual_currency );

        // translators: %1$s is the minimum loan amount, %2$s is the maximum loan amount, %3$s is the currency code.
        $popup_range_alert = sprintf(
            // translators: %1$s is the minimum loan amount, %2$s is the maximum loan amount, %3$s is the currency code.
            __( 'Minimum amount is %1$s %3$s and maximum amount is %2$s %3$s for loan.', 'fusion-pay-tbi' ),
            $popup_min_amount,
            $popup_max_amount,
            $currency
        );

        // Popup BNPL limits
        $popup_bnpl_min = self::getBnplMin( $currency, $admin_currency, $dual_currency );
        $popup_bnpl_max = self::getBnplMax( $currency, $admin_currency, $dual_currency );

        echo '
        <div id="tbi_popup_container"
            data-redirect="' . esc_attr( wc_get_checkout_url() ) . '"
            data-rest-route="' . esc_attr( $rest_route ) . '"
            data-calculator="' . esc_attr( $calculatorURL ) . '"
            data-min-amount="' . esc_attr( $popup_min_amount ) . '"
            data-max-amount="' . esc_attr( $popup_max_amount ) . '"
            data-range-alert="' . esc_attr( $popup_range_alert ) . '"
            data-bnpl-min-amount="' . esc_attr( $popup_bnpl_min ) . '"
            data-bnpl-max-amount="' . esc_attr( $popup_bnpl_max ) . '">
            <div id="iframe_container">
                <iframe id="tbi_popup_iframe" src="" scrolling="auto" frameborder="0" width="200" height="200"></iframe>
                <div id="tbi_loading">
                    <img src="' . esc_url( FUSIPATB_CDN_URL . FUSIPATB_LOADING_SVG ) . '" alt="tbi bank loading" width="128" height="128">
                    <div id="tbi_loading_text">' . esc_html__( 'please wait', 'fusion-pay-tbi' ) . '</div>
                </div>
            </div>
        </div>
        ';
    }

    private static function getPopupMin( $currency, $admin_currency, $dual_currency ) {
        $config = CountryRegistry::getInstance()->getConfig();
        if ( $dual_currency && $currency === 'EUR' ) {
            $val = floatval( get_option( 'fusipatb_min_amount_eur', 0 ) );
            if ( $val <= 0 ) {
                $val = floatval( get_option( 'fusipatb_min_amount', $config->getMinAmount() ) );
                if ( $admin_currency < 3 && $val > 0 ) {
                    $val = CurrencyHelper::bgnToEur( $val );
                }
            }
            return $val;
        }
        $val = floatval( get_option( 'fusipatb_min_amount', 0 ) );
        return $val > 0 ? $val : floatval( $config->getMinAmount() );
    }

    private static function getPopupMax( $currency, $admin_currency, $dual_currency ) {
        $config = CountryRegistry::getInstance()->getConfig();
        if ( $dual_currency && $currency === 'EUR' ) {
            $val = floatval( get_option( 'fusipatb_max_amount_eur', 0 ) );
            if ( $val <= 0 ) {
                $val = floatval( get_option( 'fusipatb_max_amount', 0 ) );
                if ( $val <= 0 ) {
                    $val = floatval( $config->getMaxAmount() );
                }
                if ( $admin_currency < 3 && $val > 0 ) {
                    $val = CurrencyHelper::bgnToEur( $val );
                }
            }
            return $val;
        }
        $val = floatval( get_option( 'fusipatb_max_amount', 0 ) );
        return $val > 0 ? $val : floatval( $config->getMaxAmount() );
    }

    private static function getBnplMin( $currency, $admin_currency, $dual_currency ) {
        if ( $dual_currency && $currency === 'EUR' ) {
            $val = floatval( get_option( 'fusipatb_bnpl_min_amount_eur', 0 ) );
            if ( $val <= 0 ) {
                $val = floatval( get_option( 'fusipatb_bnpl_min_amount', 0 ) );
                if ( $admin_currency < 3 && $val > 0 ) {
                    $val = CurrencyHelper::bgnToEur( $val );
                }
            }
            return $val;
        }
        return floatval( get_option( 'fusipatb_bnpl_min_amount', 0 ) );
    }

    private static function getBnplMax( $currency, $admin_currency, $dual_currency ) {
        if ( $dual_currency && $currency === 'EUR' ) {
            $val = floatval( get_option( 'fusipatb_bnpl_max_amount_eur', 0 ) );
            if ( $val <= 0 ) {
                $val = floatval( get_option( 'fusipatb_bnpl_max_amount', 0 ) );
                if ( $admin_currency < 3 && $val > 0 ) {
                    $val = CurrencyHelper::bgnToEur( $val );
                }
            }
            return $val;
        }
        return floatval( get_option( 'fusipatb_bnpl_max_amount', 0 ) );
    }

    /**
     * Return the current checkout cart total converted to the display currency.
     *
     * This is the single source of truth for the checkout total that JS
     * receives – no DOM scraping required.
     *
     * @return float
     */
    public static function getCheckoutTotalConverted() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        // Ensure totals are calculated — during custom AJAX calls WC may not
        // have recalculated yet, leaving tax/shipping values stale.
        WC()->cart->calculate_totals();

        $registry       = CountryRegistry::getInstance();
        $config         = $registry->getConfig();
        $dual_currency  = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $currency       = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $config->getCurrency();
        $admin_currency = $dual_currency ? intval( get_option( 'fusipatb_admin_currency', 2 ) ) : 0;

        $total = (float) WC()->cart->total;

        if ( $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3 && $total > 0 ) {
            $total = CurrencyHelper::bgnToEur( $total );
        }

        return round( $total, 2 );
    }

    /**
     * WooCommerce filter: inject the converted checkout total into the
     * update_order_review AJAX response fragments so JS can read it without selectors.
     *
     * Hooked on: woocommerce_update_order_review_fragments
     *
     * The value arrives in JS as: data.fragments.fusipatb_checkout_total
     *
     * @param array $fragments
     * @return array
     */
    public static function filterOrderReviewResponse( $fragments ) {
        $fragments['fusipatb_checkout_total'] = self::getCheckoutTotalConverted();
        return $fragments;
    }
}



