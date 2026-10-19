<?php

namespace FusionPayTbi\Frontend;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FrontendAssets {

    public static function enqueueStyles() {
        if ( ! get_option( 'fusipatb_checkout_status' ) ) {
            return;
        }
        wp_register_style( 'googlFonts', '//fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700&display=swap', array(), FUSIPATB_PLUGIN_VERSION );
        wp_enqueue_style( 'googlFonts' );

        $style_file = FUSIPATB_WC_PLUGIN_PATH . '/assets/css/style.css';
        wp_register_style(
            'fusipatb_checkout_frontend',
            plugins_url( 'assets/css/style.css', FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ),
            array(),
            file_exists( $style_file ) ? filemtime( $style_file ) : '1'
        );
        wp_enqueue_style( 'fusipatb_checkout_frontend' );
    }

    public static function enqueueScripts() {
        if ( ! get_option( 'fusipatb_checkout_status' ) ) {
            return;
        }

        $config   = CountryRegistry::getInstance()->getConfig();
        $registry = CountryRegistry::getInstance();

        // Shortcode detection fallback
        $page_has_cart_shortcode     = false;
        $page_has_checkout_shortcode = false;
        global $post;
        if ( $post instanceof \WP_Post ) {
            $page_has_cart_shortcode     = has_shortcode( $post->post_content, 'woocommerce_cart' );
            $page_has_checkout_shortcode = has_shortcode( $post->post_content, 'woocommerce_checkout' );
            $page_has_cart_shortcode     = apply_filters( 'fusion_pay_tbi_page_has_cart_shortcode', $page_has_cart_shortcode, $post );
            $page_has_checkout_shortcode = apply_filters( 'fusion_pay_tbi_page_has_checkout_shortcode', $page_has_checkout_shortcode, $post );
        }

        $prefer_checkout_only = ( $page_has_checkout_shortcode && ( is_cart() || $page_has_cart_shortcode ) );
        $prefer_checkout_only = apply_filters( 'fusion_pay_tbi_prefer_checkout_only', $prefer_checkout_only, $post );

        $dual_currency = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $currency      = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $config->getCurrency();

        // Build shared fusipatbConfig object passed to all JS scripts.
        $min_amount = floatval( get_option( 'fusipatb_min_amount', $config->getMinAmount() ) );
        $max_amount = floatval( get_option( 'fusipatb_max_amount', $config->getMaxAmount() ) );
        if ( $min_amount <= 0 ) {
            $min_amount = floatval( $config->getMinAmount() );
        }
        if ( $max_amount <= 0 ) {
            $max_amount = floatval( $config->getMaxAmount() );
        }

        $bnpl_min = floatval( get_option( 'fusipatb_bnpl_min_amount', 0 ) );
        $bnpl_max = floatval( get_option( 'fusipatb_bnpl_max_amount', 0 ) );

        $feature_flags = array();
        foreach ( $config->getFeatureFlags() as $flag => $enabled ) {
            $feature_flags[ $flag ] = (bool) $enabled;
        }

        $tbi_config = array(
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'platformUrl'    => $config->getPlatformUrl(),
            'calcUrlPath'    => $config->getCalcUrlPath(),
            'currency'       => $currency,
            'minAmount'      => $min_amount,
            'maxAmount'      => $max_amount,
            'bnplMin'        => $bnpl_min,
            'bnplMax'        => $bnpl_max,
            'features'       => $feature_flags,
            'resellerCode'   => get_option( 'fusipatb_reseller_code', '' ),
            'dualCurrency'   => $dual_currency,
            'eurMultiplier'  => floatval( get_option( 'fusipatb_euro_multiplier', 0 ) ),
            'popupCheckout'  => (int) get_option( 'fusipatb_popup_checkout', 0 ),
            'nonce'          => wp_create_nonce( 'fusipatb_process_product' ),
            'directNonce'    => wp_create_nonce( 'fusipatb_process_product_direct' ),
            'cartDirectNonce' => wp_create_nonce( 'fusipatb_process_cart_direct' ),
            'cartNonce'      => wp_create_nonce( 'fusipatb_process_cart' ),
            'installmentsNonce' => wp_create_nonce( 'fusipatb_calculate_installments_ajax' ),
            'sessionNonce'   => wp_create_nonce( 'fusipatb_set_session_data' ),
        );

        // Provide initial totals so JS never needs to scrape the DOM.
        if ( is_checkout() && function_exists( 'WC' ) && WC()->cart ) {
            $tbi_config['checkoutTotal'] = \FusionPayTbi\Frontend\Popup::getCheckoutTotalConverted();
        }
        if ( ( is_cart() || $page_has_cart_shortcode ) && function_exists( 'WC' ) && WC()->cart ) {
            $tbi_config['cartTotal'] = \FusionPayTbi\Frontend\CartButton::getCartContentTotalConverted();
        }

        if ( is_product() && get_option( 'fusipatb_product_checked' ) != 0 ) {
            $js_file = FUSIPATB_WC_PLUGIN_PATH . '/assets/js/tbi_product.js';
            wp_register_script(
                'fusipatb_product_frontend',
                plugins_url( 'assets/js/tbi_product.js', FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ),
                array(),
                file_exists( $js_file ) ? filemtime( $js_file ) : '1',
                true
            );
            wp_enqueue_script( 'fusipatb_product_frontend' );
            wp_localize_script( 'fusipatb_product_frontend', 'fusipatbAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
            wp_localize_script( 'fusipatb_product_frontend', 'fusipatbConfig', $tbi_config );
        }

        if ( ( is_cart() || $page_has_cart_shortcode ) && get_option( 'fusipatb_cart_checked' ) != 0 ) {
            if ( $prefer_checkout_only ) {
                $checkout_handle = 'fusipatb_checkout_frontend_on_cart';
                $checkout_script = get_option( 'fusipatb_popup_checkout' ) == 1 ? 'tbi_checkout_simple.js' : 'tbi_checkout.js';
                $js_file         = FUSIPATB_WC_PLUGIN_PATH . '/assets/js/' . $checkout_script;
                wp_register_script(
                    $checkout_handle,
                    plugins_url( 'assets/js/' . $checkout_script, FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ),
                    array( 'jquery' ),
                    file_exists( $js_file ) ? filemtime( $js_file ) : '1',
                    true
                );
                wp_enqueue_script( $checkout_handle );
                wp_localize_script( $checkout_handle, 'fusipatbAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
                wp_localize_script( $checkout_handle, 'fusipatbConfig', $tbi_config );
            } else {
                $js_file = FUSIPATB_WC_PLUGIN_PATH . '/assets/js/tbi_cart.js';
                wp_register_script(
                    'fusipatb_cart_frontend',
                    plugins_url( 'assets/js/tbi_cart.js', FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ),
                    array(),
                    file_exists( $js_file ) ? filemtime( $js_file ) : '1',
                    true
                );
                wp_enqueue_script( 'fusipatb_cart_frontend' );
                wp_localize_script( 'fusipatb_cart_frontend', 'fusipatbAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
                wp_localize_script( 'fusipatb_cart_frontend', 'fusipatbConfig', $tbi_config );
            }
        }

        if ( ( is_checkout() || ( $page_has_checkout_shortcode && ! is_cart() ) ) && ! $prefer_checkout_only ) {
            $checkout_script = get_option( 'fusipatb_popup_checkout' ) == 1 ? 'tbi_checkout_simple.js' : 'tbi_checkout.js';
            $js_file         = FUSIPATB_WC_PLUGIN_PATH . '/assets/js/' . $checkout_script;
            wp_register_script(
                'fusipatb_checkout_frontend',
                plugins_url( 'assets/js/' . $checkout_script, FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ),
                array( 'jquery' ),
                file_exists( $js_file ) ? filemtime( $js_file ) : '1',
                true
            );
            wp_enqueue_script( 'fusipatb_checkout_frontend' );
            wp_localize_script( 'fusipatb_checkout_frontend', 'fusipatbAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
            wp_localize_script( 'fusipatb_checkout_frontend', 'fusipatbConfig', $tbi_config );
        }

        if ( $registry->hasFeature( FeatureFlags::ADVERTISING ) && get_option( 'fusipatb_advertising' ) == 1 ) {
            $js_file = FUSIPATB_WC_PLUGIN_PATH . '/assets/js/tbi_advertising.js';
            wp_register_script(
                'fusipatb_checkout_adv',
                plugins_url( 'assets/js/tbi_advertising.js', FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ),
                array(),
                file_exists( $js_file ) ? filemtime( $js_file ) : '1',
                true
            );
            wp_enqueue_script( 'fusipatb_checkout_adv' );
        }
    }
}



