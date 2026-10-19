<?php

namespace FusionPayTbi\Frontend;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CartButton {

    public static function render() {
        if ( intval( get_option( 'fusipatb_cart_checked', 2 ) ) === 0 || ! get_option( 'fusipatb_checkout_status' ) ) {
            return;
        }

        if ( function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->calculate_totals();
        }

        $registry      = CountryRegistry::getInstance();
        $config        = $registry->getConfig();
        $dual_currency = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $analysis_fees = $registry->hasFeature( FeatureFlags::ANALYSIS_FEES );

        $eur      = $dual_currency ? floatval( get_option( 'fusipatb_euro_multiplier', 0 ) ) : 0;
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $config->getCurrency();

        $wc_shipping          = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();
        $price                = self::getCartDisplayTotalRaw( $wc_shipping );
        $price_with_delivery  = 0;
        $cart_total           = 0;

        // Plugin custom delivery fee only applies when no WC shipping method is active.
        if ( $wc_shipping <= 0 && $price < floatval( get_option( 'fusipatb_delivery_limit', 0 ) ) ) {
            $price_with_delivery = $price + floatval( get_option( 'fusipatb_delivery_fee', 0 ) );
        }

        $admin_currency = $dual_currency ? intval( get_option( 'fusipatb_admin_currency', 2 ) ) : 0;

        // When admin currency is BGN but display is EUR, WC still returns BGN prices.
        // Convert to EUR so price comparisons against EUR limits are correct.
        if ( $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3 ) {
            $price = CurrencyHelper::bgnToEur( $price );
            if ( $price_with_delivery > 0 ) {
                $price_with_delivery = CurrencyHelper::bgnToEur( $price_with_delivery );
            }
        }

        // Loan max check
        $loan_max_for_render = self::getLoanMax( $currency, $admin_currency, $dual_currency );
        $bnpl_max_for_render = self::getBnplMax( $currency, $admin_currency, $dual_currency );

        $amount_to_check = $price_with_delivery > 0 ? $price_with_delivery : $price;
        if ( $loan_max_for_render > 0 && $amount_to_check > $loan_max_for_render ) {
            if ( $bnpl_max_for_render <= 0 || $amount_to_check > $bnpl_max_for_render ) {
                return;
            }
        }

        $initial_display_price = $price_with_delivery > 0 ? $price_with_delivery : $price;

        // Analysis fees
        $bnpl_analysis_fee     = $analysis_fees ? floatval( get_option( 'fusipatb_bnpl_analysis_fee', 0 ) ) : 0;
        $standard_analysis_fee = $analysis_fees ? floatval( get_option( 'fusipatb_standard_analysis_fee', 0 ) ) : 0;

        // Dynamic tiers
        $tier_count = intval( get_option( 'fusipatb_tier_count', 0 ) );
        $tiers_data = array();
        if ( $tier_count > 0 ) {
            for ( $i = 1; $i <= $tier_count; $i++ ) {
                $tiers_data[ $i ] = array(
                    'tier'       => floatval( get_option( "fusipatb_tier{$i}_amount", 0 ) ),
                    'multiplier' => floatval( get_option( "fusipatb_tier{$i}_multiplier", 0 ) ),
                    'period'     => intval( get_option( "fusipatb_period{$i}", 0 ) ),
                );
            }
        } else {
            $tier_count = 3;
            $tiers_data = array(
                1 => array( 'tier' => floatval( get_option( 'fusipatb_tier1_limit', 0 ) ), 'multiplier' => floatval( get_option( 'fusipatb_tier1_multiplier', 0 ) ), 'period' => intval( get_option( 'fusipatb_tier1', $config->getMaxMonths() ) ) ),
                2 => array( 'tier' => floatval( get_option( 'fusipatb_tier2_limit', 0 ) ), 'multiplier' => floatval( get_option( 'fusipatb_tier2_multiplier', 0 ) ), 'period' => intval( get_option( 'fusipatb_tier2', $config->getMaxMonths() ) ) ),
                3 => array( 'tier' => floatval( get_option( 'fusipatb_tier3_limit', 0 ) ), 'multiplier' => floatval( get_option( 'fusipatb_tier3_multiplier', 0 ) ), 'period' => intval( get_option( 'fusipatb_tier3', $config->getMaxMonthsHigh() ) ) ),
            );
        }

        $bnpl_max_amount = $bnpl_max_for_render > 0 ? $bnpl_max_for_render : floatval( get_option( 'fusipatb_bnpl_max', 0 ) );

        // Calculate label
        $label        = '';
        $installment  = 0;
        $installments = 4;
        $modal_section = null;
        $mode = null;
        $scheme_id = null;
        $default_installments = null;
        $default_installment_amount = null;

        if ( $registry->hasFeature( FeatureFlags::RENDER_BUTTON_API ) ) {
            $calculation_data = \FusionPayTbi\Api\InstallmentCalculator::calculate( $initial_display_price );
            if ( $calculation_data && $calculation_data['success'] ) {
                $modal_section = isset( $calculation_data['data']['modal_section'] ) ? $calculation_data['data']['modal_section'] : null;
                $mode = isset( $calculation_data['data']['mode'] ) ? $calculation_data['data']['mode'] : null;
                $scheme_id = isset( $calculation_data['data']['scheme_id'] ) ? $calculation_data['data']['scheme_id'] : null;
                $default_installments = isset( $calculation_data['data']['default_installments'] ) ? $calculation_data['data']['default_installments'] : null;
                $default_installment_amount = isset( $calculation_data['data']['default_installment_amount'] ) ? $calculation_data['data']['default_installment_amount'] : null;
                if ( ! empty( $calculation_data['data']['button_text'] ) ) {
                    $label = $calculation_data['data']['button_text'];
                } else {
                    $installment  = $calculation_data['data']['installment_amount'];
                    $installments = $calculation_data['data']['installments'];
                    if ( $eur > 0 ) {
                        $label = __('{installments} installments of {eur_installment} EUR ({installment} BGN)', 'fusion-pay-tbi');
                    } else {
                        $label = __( 'de la {installment} LEI / lună', 'fusion-pay-tbi' );
                    }
                }
                $installment  = $calculation_data['data']['installment_amount'];
                $installments = $calculation_data['data']['installments'];
            }
        }

        if ( $label === '' ) {
            $installment  = number_format( ( $initial_display_price + $bnpl_analysis_fee ) / 4, 2, '.', '' );
            $installments = 4;

            if ( $eur > 0 ) {
                $label = __( '0% in 4 installments for {installment} BGN ({eur_installment})', 'fusion-pay-tbi' );
            } else {
                $label = __( 'de la {installment} LEI / lună', 'fusion-pay-tbi' );
            }

            if ( $initial_display_price > $bnpl_max_amount ) {
                $prev_limit = $bnpl_max_amount;
                for ( $i = 1; $i <= $tier_count; $i++ ) {
                    if ( ! isset( $tiers_data[ $i ] ) ) {
                        continue;
                    }
                    if ( $initial_display_price > $prev_limit && ( $tiers_data[ $i ]['tier'] <= 0 || $initial_display_price <= $tiers_data[ $i ]['tier'] ) ) {
                        $multiplier   = $tiers_data[ $i ]['multiplier'] > 0 ? $tiers_data[ $i ]['multiplier'] : 1 / 12;
                        $installment  = number_format( ( $initial_display_price + $standard_analysis_fee ) * $multiplier, 2, '.', '' );
                        $installments = $tiers_data[ $i ]['period'];
                        break;
                    }
                    $prev_limit = $tiers_data[ $i ]['tier'];
                }
                if ( $eur > 0 ) {
                    $label = __('{installments} installments of {eur_installment} EUR ({installment} BGN)', 'fusion-pay-tbi');
                } else {
                    $label = __( 'de la {installment} LEI / lună', 'fusion-pay-tbi' );
                }
            }
        }

        $label = str_replace( '{installments}', (string) $installments, $label );
        if ( $eur > 0 && is_numeric( $installment ) ) {
            if ( strtoupper( $currency ) === 'EUR' ) {
                // API returns EUR; {installment} must be BGN, {eur_installment} must be EUR
                $bgn_installment = number_format( (float) $installment * $eur, 2, '.', '' );
                $label = str_replace( '{eur_installment}', number_format( (float) $installment, 2, '.', '' ), $label );
                $label = str_replace( '{installment}', $bgn_installment, $label );
            } else {
                // API returns BGN; {installment} = BGN (raw), {eur_installment} = EUR (converted)
                $label = str_replace( '{eur_installment}', number_format( (float) $installment / $eur, 2, '.', '' ), $label );
                $label = str_replace( '{installment}', (string) $installment, $label );
            }
        } else {
            $label = str_replace( '{installment}', (string) $installment, $label );
        }

        // Cart items data
        $tbi_cart    = array();
        $items_count = 0;
        $tax_check   = false;
        $tax_multiplier = 1;

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['bundled_by'] ) ) {
                continue;
            }
            $product_cart = $cart_item['data'];
            $single_price = wc_get_price_including_tax( $product_cart );
            $cart_total  += $single_price * $cart_item['quantity'];

            if ( ! $tax_check ) {
                $price_incl = wc_get_price_including_tax( $product_cart );
                $price_excl = wc_get_price_excluding_tax( $product_cart );
                if ( $price_excl > 0 && $price_incl > $price_excl ) {
                    $tax_multiplier = 1 + ( $price_incl - $price_excl ) / $price_excl;
                }
                $tax_check = true;
            }

            $tbi_cart[ $items_count ]['product_id']   = $cart_item['product_id'];
            $tbi_cart[ $items_count ]['variation_id']  = $cart_item['variation_id'];
            $tbi_cart[ $items_count ]['quantity']      = $cart_item['quantity'];
            $tbi_cart[ $items_count ]['price']         = $single_price;
            $items_count++;
        }

        $total_discount = WC()->cart->get_discount_total() * $tax_multiplier;
        if ( $total_discount > 0 ) {
            $applied_coupons = WC()->cart->get_applied_coupons();
            $tbi_cart[ $items_count ]['product_id']  = wc_get_coupon_id_by_code( $applied_coupons[0] );
            $tbi_cart[ $items_count ]['variation_id'] = 0;
            $tbi_cart[ $items_count ]['quantity']     = 1;
            $tbi_cart[ $items_count ]['price']        = -$total_discount;
        }

        // Visible amount
        $bnpl_min_for_render = self::getBnplMin( $currency, $admin_currency, $dual_currency );
        $visible_amount      = self::getVisibleAmount( $currency, $admin_currency, $dual_currency );
        if ( $config->getCountryCode() !== 'RO' && $bnpl_min_for_render > 0 ) {
            $visible_amount = $bnpl_min_for_render;
        }

        if ( $cart_total < $visible_amount ) {
            return;
        }

        // Button style
        $style_id  = intval( get_option( 'fusipatb_cart_checked', 2 ) );
        $container = 'tbi_button_container' . $style_id;
        $button_id = 'tbi_button' . $style_id;
        $body_id   = 'tbi_button_body' . $style_id;
        $logo_id   = 'tbi_button_logo' . $style_id;

        $logo = FUSIPATB_BANK_LOGO_WHITE;
        if ( $style_id == 2 || $style_id == 3 ) {
            $logo = FUSIPATB_BANK_LOGO;
        }

        $class = $button_id;
        if ( get_option( 'fusipatb_reseller_code' ) == '2501' ) {
            $class .= '_cart';
        }

        $converted_cart_total = self::getCartContentTotalConverted();

        echo '
        <input type="hidden" name="tbi_cart_content" id="tbi_cart_content" value=\'' . wp_json_encode( $tbi_cart ) . '\' data-cart-total="' . esc_attr( $converted_cart_total ) . '">
        <div class="' . esc_attr( $container ) . '">
            <div class="' . esc_attr( $class ) . '" id="tbi_button">
                <div class="' . esc_attr( $body_id ) . '" id="tbi_button_body" data-cart-total="' . esc_attr( $initial_display_price ) . '"' . ( $eur > 0 ? ' data-eur="' . esc_attr( $eur ) . '"' : '' ) . ' data-currency="' . esc_attr( $currency ) . '"'
                    . ( null !== $modal_section && '' !== $modal_section ? ' data-modal-section="' . esc_attr( strtolower( (string) $modal_section ) ) . '"' : '' )
                    . ( null !== $mode && '' !== $mode ? ' data-mode="' . esc_attr( $mode ) . '"' : '' )
                    . ( null !== $scheme_id && '' !== $scheme_id ? ' data-scheme-id="' . esc_attr( $scheme_id ) . '"' : '' )
                    . ( null !== $default_installments && '' !== $default_installments ? ' data-default-installments="' . esc_attr( $default_installments ) . '"' : '' )
                    . ( null !== $default_installment_amount && '' !== $default_installment_amount ? ' data-default-installment-amount="' . esc_attr( $default_installment_amount ) . '"' : '' ) . '>
                    <div class="tbi_button_line">' . esc_html__( 'Buy with', 'fusion-pay-tbi' ) . '<img src="' . esc_url( FUSIPATB_CDN_URL . $logo ) . '" alt="tbi bank logo" width="89" height="40" class="' . esc_attr( $logo_id ) . '"></div>
                    <div class="tbi_button_line">' . wp_kses_post( $label ) . '</div>
                </div>
            </div>
        </div>
        ';
    }

    private static function getLoanMax( $currency, $admin_currency, $dual_currency ) {
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

    private static function getVisibleAmount( $currency, $admin_currency, $dual_currency ) {
        $config = CountryRegistry::getInstance()->getConfig();
        if ( $dual_currency && $currency === 'EUR' ) {
            $val = floatval( get_option( 'fusipatb_min_amount_eur', 0 ) );
            if ( $val > 0 ) {
                return $val;
            }
            $val = floatval( get_option( 'fusipatb_min_amount', $config->getMinAmount() ) );
            if ( $admin_currency < 3 && $val > 0 ) {
                $val = CurrencyHelper::bgnToEur( $val );
            }
            return $val;
        }
        $val = floatval( get_option( 'fusipatb_min_amount', 0 ) );
        return $val > 0 ? $val : floatval( $config->getMinAmount() );
    }

    /**
     * Return the cart content total (excl. shipping) converted to the display currency.
     *
     * This is the single source of truth for the cart total that JS receives –
     * no DOM scraping or client-side summing required.
     *
     * @return float
     */
    public static function getCartContentTotalConverted() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        // Ensure totals are calculated — during custom AJAX calls WC may not
        // have recalculated yet, leaving cart_contents_tax at 0.
        WC()->cart->calculate_totals();

        $registry       = CountryRegistry::getInstance();
        $config         = $registry->getConfig();
        $dual_currency  = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $currency       = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $config->getCurrency();
        $admin_currency = $dual_currency ? intval( get_option( 'fusipatb_admin_currency', 2 ) ) : 0;

        $total = (float) WC()->cart->cart_contents_total + (float) WC()->cart->cart_contents_tax;

        if ( $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3 && $total > 0 ) {
            $total = CurrencyHelper::bgnToEur( $total );
        }

        return round( $total, 2 );
    }

    /**
     * Return the cart total used for the button label before currency conversion.
     *
     * This must be tax-inclusive and discount-aware so the initial cart button
     * render matches product-page installment calculations.
     *
     * @param float|null $wc_shipping Optional precomputed shipping total including tax.
     * @return float
     */
    private static function getCartDisplayTotalRaw( $wc_shipping = null ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        $cart = WC()->cart;

        if ( null === $wc_shipping ) {
            $wc_shipping = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
        }

        $price = (float) $cart->get_total( 'edit' );

        if ( $price <= 0 ) {
            $price = (float) $cart->cart_contents_total
                + (float) $cart->cart_contents_tax
                + (float) $wc_shipping
                - (float) $cart->get_discount_total()
                - (float) $cart->get_discount_tax();
        }

        return max( 0, round( $price, 2 ) );
    }

    /**
     * Lightweight AJAX handler that returns only the converted cart total.
     * Called by tbi_cart.js on the `updated_cart_totals` WC event so JS
     * always has the correct server-computed total without DOM scraping.
     */
    public static function handleGetCartTotal() {
        wp_send_json_success( array(
            'cart_total' => self::getCartContentTotalConverted(),
        ) );
    }

    /**
     * Compute all data needed by the WooCommerce Blocks cart button and return
     * it as an array.  This is the Blocks equivalent of render(): the same
     * business logic applies, but the result is consumed by JS instead of
     * being echoed as HTML.
     *
     * @return array
     */
    public static function getBlocksData() {
        if ( intval( get_option( 'fusipatb_cart_checked', 2 ) ) === 0 || ! get_option( 'fusipatb_checkout_status' ) ) {
            return array( 'enabled' => false );
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return array( 'enabled' => false );
        }

        WC()->cart->calculate_totals();

        $registry      = CountryRegistry::getInstance();
        $config        = $registry->getConfig();
        $dual_currency = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $analysis_fees = $registry->hasFeature( FeatureFlags::ANALYSIS_FEES );

        $eur      = $dual_currency ? floatval( get_option( 'fusipatb_euro_multiplier', 0 ) ) : 0;
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $config->getCurrency();

        $wc_shipping         = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax();
        $price               = self::getCartDisplayTotalRaw( $wc_shipping );
        $price_with_delivery = 0;

        if ( $wc_shipping <= 0 && $price < floatval( get_option( 'fusipatb_delivery_limit', 0 ) ) ) {
            $price_with_delivery = $price + floatval( get_option( 'fusipatb_delivery_fee', 0 ) );
        }

        $admin_currency = $dual_currency ? intval( get_option( 'fusipatb_admin_currency', 2 ) ) : 0;

        if ( $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3 ) {
            $price = CurrencyHelper::bgnToEur( $price );
            if ( $price_with_delivery > 0 ) {
                $price_with_delivery = CurrencyHelper::bgnToEur( $price_with_delivery );
            }
        }

        $loan_max_for_render = self::getLoanMax( $currency, $admin_currency, $dual_currency );
        $bnpl_max_for_render = self::getBnplMax( $currency, $admin_currency, $dual_currency );

        $amount_to_check = $price_with_delivery > 0 ? $price_with_delivery : $price;
        if ( $loan_max_for_render > 0 && $amount_to_check > $loan_max_for_render ) {
            if ( $bnpl_max_for_render <= 0 || $amount_to_check > $bnpl_max_for_render ) {
                return array( 'enabled' => false );
            }
        }

        $initial_display_price = $price_with_delivery > 0 ? $price_with_delivery : $price;
        $bnpl_analysis_fee     = $analysis_fees ? floatval( get_option( 'fusipatb_bnpl_analysis_fee', 0 ) ) : 0;
        $standard_analysis_fee = $analysis_fees ? floatval( get_option( 'fusipatb_standard_analysis_fee', 0 ) ) : 0;

        $tier_count = intval( get_option( 'fusipatb_tier_count', 0 ) );
        $tiers_data = array();
        if ( $tier_count > 0 ) {
            for ( $i = 1; $i <= $tier_count; $i++ ) {
                $tiers_data[ $i ] = array(
                    'tier'       => floatval( get_option( "fusipatb_tier{$i}_amount", 0 ) ),
                    'multiplier' => floatval( get_option( "fusipatb_tier{$i}_multiplier", 0 ) ),
                    'period'     => intval( get_option( "fusipatb_period{$i}", 0 ) ),
                );
            }
        } else {
            $tier_count = 3;
            $tiers_data = array(
                1 => array( 'tier' => floatval( get_option( 'fusipatb_tier1_limit', 0 ) ), 'multiplier' => floatval( get_option( 'fusipatb_tier1_multiplier', 0 ) ), 'period' => intval( get_option( 'fusipatb_tier1', $config->getMaxMonths() ) ) ),
                2 => array( 'tier' => floatval( get_option( 'fusipatb_tier2_limit', 0 ) ), 'multiplier' => floatval( get_option( 'fusipatb_tier2_multiplier', 0 ) ), 'period' => intval( get_option( 'fusipatb_tier2', $config->getMaxMonths() ) ) ),
                3 => array( 'tier' => floatval( get_option( 'fusipatb_tier3_limit', 0 ) ), 'multiplier' => floatval( get_option( 'fusipatb_tier3_multiplier', 0 ) ), 'period' => intval( get_option( 'fusipatb_tier3', $config->getMaxMonthsHigh() ) ) ),
            );
        }

        $bnpl_max_amount = $bnpl_max_for_render > 0 ? $bnpl_max_for_render : floatval( get_option( 'fusipatb_bnpl_max', 0 ) );

        $label                      = '';
        $installment                = 0;
        $installments               = 4;
        $modal_section              = null;
        $mode                       = null;
        $scheme_id                  = null;
        $default_installments       = null;
        $default_installment_amount = null;

        if ( $registry->hasFeature( FeatureFlags::RENDER_BUTTON_API ) ) {
            $calculation_data = \FusionPayTbi\Api\InstallmentCalculator::calculate( $initial_display_price );
            if ( $calculation_data && $calculation_data['success'] ) {
                $modal_section              = isset( $calculation_data['data']['modal_section'] ) ? $calculation_data['data']['modal_section'] : null;
                $mode                       = isset( $calculation_data['data']['mode'] ) ? $calculation_data['data']['mode'] : null;
                $scheme_id                  = isset( $calculation_data['data']['scheme_id'] ) ? $calculation_data['data']['scheme_id'] : null;
                $default_installments       = isset( $calculation_data['data']['default_installments'] ) ? $calculation_data['data']['default_installments'] : null;
                $default_installment_amount = isset( $calculation_data['data']['default_installment_amount'] ) ? $calculation_data['data']['default_installment_amount'] : null;
                if ( ! empty( $calculation_data['data']['button_text'] ) ) {
                    $label = $calculation_data['data']['button_text'];
                } else {
                    $installment  = $calculation_data['data']['installment_amount'];
                    $installments = $calculation_data['data']['installments'];
                    if ( $eur > 0 ) {
                        $label = __('{installments} installments of {eur_installment} EUR ({installment} BGN)', 'fusion-pay-tbi');
                    } else {
                        $label = __( 'de la {installment} LEI / lună', 'fusion-pay-tbi' );
                    }
                }
                $installment  = $calculation_data['data']['installment_amount'];
                $installments = $calculation_data['data']['installments'];
            }
        }

        if ( $label === '' ) {
            $installment  = number_format( ( $initial_display_price + $bnpl_analysis_fee ) / 4, 2, '.', '' );
            $installments = 4;

            if ( $eur > 0 ) {
                $label = __( '0% in 4 installments for {installment} BGN ({eur_installment})', 'fusion-pay-tbi' );
            } else {
                $label = __( 'de la {installment} LEI / lună', 'fusion-pay-tbi' );
            }

            if ( $initial_display_price > $bnpl_max_amount ) {
                $prev_limit = $bnpl_max_amount;
                for ( $i = 1; $i <= $tier_count; $i++ ) {
                    if ( ! isset( $tiers_data[ $i ] ) ) {
                        continue;
                    }
                    if ( $initial_display_price > $prev_limit && ( $tiers_data[ $i ]['tier'] <= 0 || $initial_display_price <= $tiers_data[ $i ]['tier'] ) ) {
                        $multiplier   = $tiers_data[ $i ]['multiplier'] > 0 ? $tiers_data[ $i ]['multiplier'] : 1 / 12;
                        $installment  = number_format( ( $initial_display_price + $standard_analysis_fee ) * $multiplier, 2, '.', '' );
                        $installments = $tiers_data[ $i ]['period'];
                        break;
                    }
                    $prev_limit = $tiers_data[ $i ]['tier'];
                }
                if ( $eur > 0 ) {
                    $label = __('{installments} installments of {eur_installment} EUR ({installment} BGN)', 'fusion-pay-tbi');
                } else {
                    $label = __( 'de la {installment} LEI / lună', 'fusion-pay-tbi' );
                }
            }
        }

        $label = str_replace( '{installments}', (string) $installments, $label );
        if ( $eur > 0 && is_numeric( $installment ) ) {
            if ( strtoupper( $currency ) === 'EUR' ) {
                $bgn_installment = number_format( (float) $installment * $eur, 2, '.', '' );
                $label           = str_replace( '{eur_installment}', number_format( (float) $installment, 2, '.', '' ), $label );
                $label           = str_replace( '{installment}', $bgn_installment, $label );
            } else {
                $label = str_replace( '{eur_installment}', number_format( (float) $installment / $eur, 2, '.', '' ), $label );
                $label = str_replace( '{installment}', (string) $installment, $label );
            }
        } else {
            $label = str_replace( '{installment}', (string) $installment, $label );
        }

        // Visible amount check (same as render())
        $cart_total = 0;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['bundled_by'] ) ) {
                continue;
            }
            $product_cart = $cart_item['data'];
            $single_price = wc_get_price_including_tax( $product_cart );
            $cart_total  += $single_price * $cart_item['quantity'];
        }

        $bnpl_min_for_render = self::getBnplMin( $currency, $admin_currency, $dual_currency );
        $visible_amount      = self::getVisibleAmount( $currency, $admin_currency, $dual_currency );
        if ( $config->getCountryCode() !== 'RO' && $bnpl_min_for_render > 0 ) {
            $visible_amount = $bnpl_min_for_render;
        }

        if ( $cart_total < $visible_amount ) {
            return array( 'enabled' => false );
        }

        $style_id = intval( get_option( 'fusipatb_cart_checked', 2 ) );
        $logo     = FUSIPATB_BANK_LOGO_WHITE;
        if ( $style_id == 2 || $style_id == 3 ) {
            $logo = FUSIPATB_BANK_LOGO;
        }

        return array(
            'enabled'                    => true,
            'label'                      => $label,
            'style_id'                   => $style_id,
            'logo_url'                   => FUSIPATB_CDN_URL . $logo,
            'buy_with_text'              => __( 'Buy with', 'fusion-pay-tbi' ),
            'currency'                   => $currency,
            'eur'                        => $eur,
            'initial_display_price'      => $initial_display_price,
            'converted_cart_total'       => self::getCartContentTotalConverted(),
            'loan_max'                   => $loan_max_for_render,
            'bnpl_max'                   => $bnpl_max_for_render,
            'min_amount'                 => $visible_amount,
            'modal_section'              => $modal_section,
            'mode'                       => $mode,
            'scheme_id'                  => $scheme_id,
            'default_installments'       => $default_installments,
            'default_installment_amount' => $default_installment_amount,
            'platform_url'               => $config->getPlatformUrl(),
            'ajax_url'                   => admin_url( 'admin-ajax.php' ),
            'reseller_code'              => get_option( 'fusipatb_reseller_code', '' ),
            'cart_nonce'                 => wp_create_nonce( 'fusipatb_process_cart' ),
            'installments_nonce'         => wp_create_nonce( 'fusipatb_calculate_installments_ajax' ),
        );
    }
}



