<?php

namespace FusionPayTbi\Frontend;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProductButton {

    public static function render() {
        if ( ! is_product() ) {
            return;
        }
        if ( intval( get_option( 'fusipatb_product_checked', 2 ) ) === 0 || ! get_option( 'fusipatb_checkout_status' ) ) {
            return;
        }

        global $product;

        if ( $product->is_type( 'grouped' ) ) {
            return;
        }

        $registry      = CountryRegistry::getInstance();
        $config        = $registry->getConfig();
        $dual_currency = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $analysis_fees = $registry->hasFeature( FeatureFlags::ANALYSIS_FEES );

        $eur      = $dual_currency ? floatval( get_option( 'fusipatb_euro_multiplier', 0 ) ) : 0;
        $price    = wc_get_price_including_tax( $product );
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $config->getCurrency();

        $price_with_delivery = 0;
        if ( $price < floatval( get_option( 'fusipatb_delivery_limit', 0 ) ) ) {
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

        // Loan max for render
        $loan_max_for_render = self::getLoanMax( $currency, $admin_currency, $dual_currency );

        // BNPL max for render
        $bnpl_max_for_render = self::getBnplMax( $currency, $admin_currency, $dual_currency );

        $amount_to_check = $price_with_delivery > 0 ? $price_with_delivery : $price;
        if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
            $min_variation_price = (float) $product->get_variation_price( 'min', true );
            if ( $min_variation_price > 0 ) {
                // Convert variable min price too when currencies don't match
                if ( $dual_currency && strtoupper( $currency ) === 'EUR' && $admin_currency < 3 ) {
                    $min_variation_price = CurrencyHelper::bgnToEur( $min_variation_price );
                }
                $amount_to_check = $min_variation_price;
                if ( $amount_to_check < floatval( get_option( 'fusipatb_delivery_limit', 0 ) ) ) {
                    $amount_to_check += floatval( get_option( 'fusipatb_delivery_fee', 0 ) );
                }
            }
        }

        if ( $loan_max_for_render > 0 && $amount_to_check > $loan_max_for_render ) {
            if ( $bnpl_max_for_render <= 0 || $amount_to_check > $bnpl_max_for_render ) {
                return;
            }
        }

        $initial_display_price = $price_with_delivery > 0 ? $price_with_delivery : $price;

        // BNPL min/max
        $bnpl_min_for_render = self::getBnplMin( $currency, $admin_currency, $dual_currency );
        $bnpl_max_amount     = $bnpl_max_for_render;

        // Analysis fees (RO feature)
        $bnpl_analysis_fee     = $analysis_fees ? floatval( get_option( 'fusipatb_bnpl_analysis_fee', 0 ) ) : 0;
        $standard_analysis_fee = $analysis_fees ? floatval( get_option( 'fusipatb_standard_analysis_fee', 0 ) ) : 0;

        // Dynamic tier data
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
            // Fallback to BG legacy 3-tier system
            $tier_count = 3;
            $tiers_data = array(
                1 => array(
                    'tier'       => floatval( get_option( 'fusipatb_tier1_limit', 0 ) ),
                    'multiplier' => floatval( get_option( 'fusipatb_tier1_multiplier', 0 ) ),
                    'period'     => intval( get_option( 'fusipatb_tier1', $config->getMaxMonths() ) ),
                ),
                2 => array(
                    'tier'       => floatval( get_option( 'fusipatb_tier2_limit', 0 ) ),
                    'multiplier' => floatval( get_option( 'fusipatb_tier2_multiplier', 0 ) ),
                    'period'     => intval( get_option( 'fusipatb_tier2', $config->getMaxMonths() ) ),
                ),
                3 => array(
                    'tier'       => floatval( get_option( 'fusipatb_tier3_limit', 0 ) ),
                    'multiplier' => floatval( get_option( 'fusipatb_tier3_multiplier', 0 ) ),
                    'period'     => intval( get_option( 'fusipatb_tier3', $config->getMaxMonthsHigh() ) ),
                ),
            );
        }

        // Visible amount threshold
        $visible_amount = self::getVisibleAmount( $currency, $admin_currency, $dual_currency );
        if ( $bnpl_min_for_render > 0 && $visible_amount < $bnpl_min_for_render ) {
            $visible_amount = $bnpl_min_for_render;
        }

        // Loan min/max for data attributes
        $loan_min_amount = self::getLoanMin( $currency, $admin_currency, $dual_currency );
        $loan_max_amount = $loan_max_for_render;

        // translators: %1$s is the minimum loan amount, %2$s is the maximum loan amount, %3$s is the currency code.
        $loan_range_alert = sprintf(
            // translators: %1$s is the minimum loan amount, %2$s is the maximum loan amount, %3$s is the currency code.
            __( 'Minimum amount is %1$s %3$s and maximum amount is %2$s %3$s for loan.', 'fusion-pay-tbi' ),
            $loan_min_amount,
            $loan_max_amount,
            $currency
        );

        // Calculate initial label
        $label      = '';
        $installment = 0;
        $installments = 4;
        $modal_section = null;
        $mode = null;
        $scheme_id = null;
        $default_installments = null;
        $default_installment_amount = null;

        if ( $price >= $visible_amount && $registry->hasFeature( FeatureFlags::RENDER_BUTTON_API ) ) {
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

        // Fallback label
        if ( $label === '' ) {
            $bnpl_installment = number_format( ( $initial_display_price + $bnpl_analysis_fee ) / 4, 2, '.', '' );
            $installment      = $bnpl_installment;
            $installments     = 4;

            if ( $eur > 0 ) {
                $label = __( '0% in 4 installments for {installment} BGN ({eur_installment})', 'fusion-pay-tbi' );
            } else {
                $label = __( 'de la {installment} LEI / lună', 'fusion-pay-tbi' );
            }

            if ( $initial_display_price > $bnpl_max_amount ) {
                $installments = $config->getMinMonths();
                $prev_limit   = $bnpl_max_amount;
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

        // Variable product data
        $is_variable    = 0;
        $variation_data = '';
        if ( $product->has_child() ) {
            $is_variable = 1;
            foreach ( $product->get_available_variations() as $variation ) {
                $variation_data .= 'data-' . esc_attr( $variation['variation_id'] ) . '="' . esc_attr( $variation['display_price'] ) . '" ';
            }
        }

        // Debug: log threshold values to help diagnose visibility issues.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                'FusionPayTbi ProductButton debug: price=%.2f currency=%s admin_currency=%d dual=%d visible=%.2f bnpl_min=%.2f loan_max=%.2f bnpl_max=%.2f | DB: tbi_min=%s tbi_min_eur=%s tbi_max=%s tbi_max_eur=%s bnpl_min=%s bnpl_min_eur=%s bnpl_max=%s bnpl_max_eur=%s',
                $price, $currency, $admin_currency, $dual_currency ? 1 : 0,
                $visible_amount, $bnpl_min_for_render, $loan_max_for_render, $bnpl_max_for_render,
                get_option( 'fusipatb_min_amount', '(unset)' ), get_option( 'fusipatb_min_amount_eur', '(unset)' ),
                get_option( 'fusipatb_max_amount', '(unset)' ), get_option( 'fusipatb_max_amount_eur', '(unset)' ),
                get_option( 'fusipatb_bnpl_min_amount', '(unset)' ), get_option( 'fusipatb_bnpl_min_amount_eur', '(unset)' ),
                get_option( 'fusipatb_bnpl_max_amount', '(unset)' ), get_option( 'fusipatb_bnpl_max_amount_eur', '(unset)' )
            ) );
        }

        // Button style
        $style_id  = intval( get_option( 'fusipatb_product_checked', 2 ) );
        $container = 'tbi_button_container' . $style_id;
        $button_id = 'tbi_button' . $style_id;
        $body_id   = 'tbi_button_body' . $style_id;
        $logo_id   = 'tbi_button_logo' . $style_id;

        $logo = FUSIPATB_BANK_LOGO_WHITE;
        if ( $style_id == 2 || $style_id == 3 ) {
            $logo = FUSIPATB_BANK_LOGO;
        }

        $disable_button = '';
        $is_bundle_product = $product->is_type( 'bundle' );
        if ( $price < $visible_amount && ! $is_bundle_product ) {
            $disable_button = ' style="display:none"';
        }

        $data_label_bnpl = $eur > 0
            ? __( '0% in 4 installments for {installment} BGN ({eur_installment})', 'fusion-pay-tbi' )
            : __( 'de la {installment} LEI / lună', 'fusion-pay-tbi' );

        // Build dynamic tier data attributes
        $tier_data_attrs = '';
        foreach ( $tiers_data as $i => $tier ) {
            $tier_data_attrs .= 'data-t' . $i . '-limit="' . esc_attr( $tier['tier'] ) . '" ';
            $tier_data_attrs .= 'data-t' . $i . '="' . esc_attr( $tier['period'] ) . '" ';
            $tier_data_attrs .= 'data-f' . $i . '="' . esc_attr( $tier['multiplier'] ) . '" ';
        }

        echo '
        <div class="' . esc_attr( $container ) . '" id="tbi_button_container"' . ( ( $price < $visible_amount && ! $is_bundle_product ) ? ' style="display:none"' : '' ) . '>
            <div class="' . esc_attr( $button_id ) . '" id="tbi_button">
                <div class="' . esc_attr( $body_id ) . '" id="tbi_button_body"'
                    . ( $eur > 0 ? ' data-eur="' . esc_attr( $eur ) . '"' : '' )
                    . ' data-currency="' . esc_attr( $currency ) . '"'
                    . ' data-visible="' . esc_attr( $visible_amount ) . '"'
                    . ' data-hide-bnpl="' . esc_attr( $bnpl_max_amount ) . '"'
                    . ' data-min-amount="' . esc_attr( $loan_min_amount ) . '"'
                    . ' data-max-amount="' . esc_attr( $loan_max_amount ) . '"'
                    . ' data-range-alert="' . esc_attr( $loan_range_alert ) . '"'
                    . ' data-label-bnpl="' . esc_attr( $data_label_bnpl ) . '"'
                    . ' data-price="' . esc_attr( $price ) . '"'
                    . ' data-alert="' . esc_attr( __( 'Please select product variation', 'fusion-pay-tbi' ) ) . '"'
                    . ' data-alert1="' . esc_attr( __( 'The product is out of stock', 'fusion-pay-tbi' ) ) . '"'
                    . ' data-tier-count="' . esc_attr( $tier_count ) . '"'
                    . ' ' . $tier_data_attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_attr() during construction
                    . ' data-label="' . esc_attr( $data_label_bnpl ) . '"'
                    . ( null !== $modal_section && '' !== $modal_section ? ' data-modal-section="' . esc_attr( strtolower( (string) $modal_section ) ) . '"' : '' )
                    . ( null !== $mode && '' !== $mode ? ' data-mode="' . esc_attr( $mode ) . '"' : '' )
                    . ( null !== $scheme_id && '' !== $scheme_id ? ' data-scheme-id="' . esc_attr( $scheme_id ) . '"' : '' )
                    . ( null !== $default_installments && '' !== $default_installments ? ' data-default-installments="' . esc_attr( $default_installments ) . '"' : '' )
                    . ( null !== $default_installment_amount && '' !== $default_installment_amount ? ' data-default-installment-amount="' . esc_attr( $default_installment_amount ) . '"' : '' )
                    . ' data-variable="' . esc_attr( $is_variable ) . '"'
                    . ' ' . $variation_data // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_attr() during construction
                    . ' data-delivery-limit="' . esc_attr( get_option( 'fusipatb_delivery_limit', 0 ) ) . '"'
                    . ' data-delivery-fee="' . esc_attr( get_option( 'fusipatb_delivery_fee', 0 ) ) . '"'
                    . ( $analysis_fees ? ' data-bnpl-analysis-fee="' . esc_attr( $bnpl_analysis_fee ) . '" data-standard-analysis-fee="' . esc_attr( $standard_analysis_fee ) . '"' : '' )
                    . '>
                    <div class="tbi_button_line">' . esc_html__( 'Buy with', 'fusion-pay-tbi' ) . '<img src="' . esc_url( FUSIPATB_CDN_URL . $logo ) . '" alt="tbi bank logo" width="89" height="40" class="' . esc_attr( $logo_id ) . '"></div>
                    <div class="tbi_button_line">' . wp_kses_post( $label ) . '</div>
                </div>
            </div>
        </div>
        ';

        wp_add_inline_script(
            'fusipatb_product_frontend',
            'window.FUSION_TBI_PRODUCT = { id: ' . intval( get_the_ID() ) . ', slug: "' . esc_js( basename( get_permalink() ) ) . '" };',
            'before'
        );
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

    private static function getLoanMin( $currency, $admin_currency, $dual_currency ) {
        $config = CountryRegistry::getInstance()->getConfig();
        if ( $dual_currency && $currency === 'EUR' ) {
            $val = floatval( get_option( 'fusipatb_min_amount_eur', 0 ) );
            if ( $val <= 0 ) {
                $val = floatval( get_option( 'fusipatb_min_amount', 0 ) );
                if ( $val <= 0 ) {
                    $val = floatval( $config->getMinAmount() );
                }
                if ( $admin_currency < 3 && $val > 0 ) {
                    $val = CurrencyHelper::bgnToEur( $val );
                }
            }
            return $val;
        }
        $val = floatval( get_option( 'fusipatb_min_amount', 0 ) );
        return $val > 0 ? $val : floatval( $config->getMinAmount() );
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
}