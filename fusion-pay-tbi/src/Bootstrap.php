<?php

namespace FusionPayTbi;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bootstraps the plugin: defines constants, registers hooks, and loads components.
 */
class Bootstrap {

    /** @var string Absolute path to the main plugin file. */
    private static $plugin_file;

    /**
     * Legacy unprefixed option keys that collide with other plugins' namespace,
     * mapped to their fusipatb_-prefixed replacement. See migrate_legacy_options().
     *
     * The per-tier multiplier keys (tier1_multiplier/tier2_multiplier/tier3_multiplier)
     * are also written via string interpolation inside the dynamic per-tier loop in
     * SettingsSaver (tier{$idx}_multiplier for arbitrary tier counts beyond 3), so the
     * static 3-tier literals below only cover N=1..3; migrate_dynamic_tier_options()
     * below covers the full dynamic range (including re-migrating N=1..3 for RO sites
     * that use the dynamic tier path instead of the static 3-tier one).
     *
     * @var array<string,string>
     */
    private static $legacy_option_map = array(
        'bnpl_min'              => 'fusipatb_bnpl_min',
        'bnpl_max'              => 'fusipatb_bnpl_max',
        'bnpl_min_amount'       => 'fusipatb_bnpl_min_amount',
        'bnpl_max_amount'       => 'fusipatb_bnpl_max_amount',
        'bnpl_min_amount_eur'   => 'fusipatb_bnpl_min_amount_eur',
        'bnpl_max_amount_eur'   => 'fusipatb_bnpl_max_amount_eur',
        'bnpl_analysis_fee'     => 'fusipatb_bnpl_analysis_fee',
        'bnpl_multiplier'       => 'fusipatb_bnpl_multiplier',
        'bnpl_period'           => 'fusipatb_bnpl_period',
        'standard_analysis_fee' => 'fusipatb_standard_analysis_fee',
        'tier_currency'         => 'fusipatb_tier_currency',
        'euro_multiplier'       => 'fusipatb_euro_multiplier',

        // tbi_*-prefixed keys (CKT-3894 WP.org Plugin Check namespace-collision
        // follow-up). Every distinct static tbi_ option key read/written across
        // src/ as of this rename. Dynamic keys (tbi_tier{N} beyond tier1-3,
        // tbi_promo_products_{id}/tbi_promo_categories_{id}) cannot be listed
        // statically here — see migrate_dynamic_tier_options() and
        // migrate_dynamic_promo_options(), run after this static map from
        // migrate_legacy_options() so the tier_count/active_promo values they
        // depend on are already migrated.
        'tbi_active_promo'         => 'fusipatb_active_promo',
        'tbi_admin_currency'       => 'fusipatb_admin_currency',
        'tbi_advertising'          => 'fusipatb_advertising',
        'tbi_button_placement'     => 'fusipatb_button_placement',
        'tbi_cart_checked'         => 'fusipatb_cart_checked',
        'tbi_checkout_status'      => 'fusipatb_checkout_status',
        'tbi_default_status'       => 'fusipatb_default_status',
        'tbi_delivery_fee'         => 'fusipatb_delivery_fee',
        'tbi_delivery_fee_prc'     => 'fusipatb_delivery_fee_prc',
        'tbi_delivery_limit'       => 'fusipatb_delivery_limit',
        'tbi_encryption_key'       => 'fusipatb_encryption_key',
        'tbi_last_settings_error'  => 'fusipatb_last_settings_error',
        'tbi_max_amount'           => 'fusipatb_max_amount',
        'tbi_max_amount_eur'       => 'fusipatb_max_amount_eur',
        'tbi_min_amount'           => 'fusipatb_min_amount',
        'tbi_min_amount_eur'       => 'fusipatb_min_amount_eur',
        'tbi_popup_checkout'       => 'fusipatb_popup_checkout',
        'tbi_popup_url'            => 'fusipatb_popup_url',
        'tbi_product_button'       => 'fusipatb_product_button',
        'tbi_product_checked'      => 'fusipatb_product_checked',
        'tbi_reseller_code'        => 'fusipatb_reseller_code',
        'tbi_reseller_key'         => 'fusipatb_reseller_key',
        'tbi_sale_promooff'        => 'fusipatb_sale_promooff',
        'tbi_store_currency'       => 'fusipatb_store_currency',
        'tbi_tier_count'           => 'fusipatb_tier_count',
        'tbi_tier1'                => 'fusipatb_tier1',
        'tbi_tier2'                => 'fusipatb_tier2',
        'tbi_tier3'                => 'fusipatb_tier3',
        'tbi_tier1_limit'          => 'fusipatb_tier1_limit',
        'tbi_tier2_limit'          => 'fusipatb_tier2_limit',
        'tbi_tier3_limit'          => 'fusipatb_tier3_limit',
        'tbi_tier1_limit_eur'      => 'fusipatb_tier1_limit_eur',
        'tbi_tier2_limit_eur'      => 'fusipatb_tier2_limit_eur',
        'tbi_tier3_limit_eur'      => 'fusipatb_tier3_limit_eur',
        'tbi_cart_button_text'     => 'fusipatb_cart_button_text',
        'tbi_product_button_text'  => 'fusipatb_product_button_text',

        // Static 3-tier multiplier keys (CKT-4007 WP.org Plugin Check follow-up:
        // "tier1_multiplier" etc. are shorter than the 4-char-minimum prefix rule).
        // migrate_dynamic_tier_options() below handles the tier{N}/period{N} amount
        // and period keys, and re-covers N=1..3 for dynamic-tier (RO) sites.
        'tier1_multiplier'         => 'fusipatb_tier1_multiplier',
        'tier2_multiplier'         => 'fusipatb_tier2_multiplier',
        'tier3_multiplier'         => 'fusipatb_tier3_multiplier',
    );

    /**
     * Initialize the plugin.
     *
     * @param string $plugin_file Absolute path to fusion-pay-tbi.php.
     */
    public static function init( $plugin_file ) {
        self::$plugin_file = $plugin_file;

        self::migrate_legacy_options();
        self::define_constants();
        self::register_hooks();
    }

    /**
     * One-time copy of legacy unprefixed option values to their fusipatb_-prefixed
     * replacement, so existing merchant settings survive the WP.org-flagged rename.
     * Guarded by a flag option so the ~15 lookups only happen once per site, not
     * on every request.
     */
    private static function migrate_legacy_options() {
        if ( get_option( 'fusipatb_legacy_options_migrated' ) ) {
            return;
        }

        foreach ( self::$legacy_option_map as $old_key => $new_key ) {
            if ( false !== get_option( $new_key, false ) ) {
                continue;
            }
            $old_value = get_option( $old_key, false );
            if ( false !== $old_value ) {
                update_option( $new_key, $old_value );
            }
        }

        // Dynamic keys depend on tier_count / active_promo, which the static
        // loop above has just migrated -- must run after it.
        self::migrate_dynamic_tier_options();
        self::migrate_dynamic_promo_options();

        update_option( 'fusipatb_legacy_options_migrated', 1 );
    }

    /**
     * Copies legacy tbi_tier{N} option values (N = 1..fusipatb_tier_count) to
     * their fusipatb_tier{N} replacement. Bounded by the just-migrated
     * fusipatb_tier_count so it covers RO's dynamic tier counts beyond the
     * static tier1-3 entries already handled by the map above.
     *
     * Also migrates the unprefixed dynamic per-tier amount/multiplier/period
     * keys ("tier{N}", "tier{N}_multiplier", "period{N}") written by
     * SettingsSaver's dynamic-tier loop, to their fusipatb_-prefixed
     * replacements (CKT-4007 WP.org Plugin Check follow-up). Runs for the
     * full N=1..tier_count range so dynamic-tier (RO) sites are covered even
     * when tier_count <= 3, where the static map above only handles the
     * *_multiplier half.
     */
    private static function migrate_dynamic_tier_options() {
        $tier_count = intval( get_option( 'fusipatb_tier_count', 0 ) );

        for ( $i = 1; $i <= $tier_count; $i++ ) {
            $old_key = "tbi_tier{$i}";
            $new_key = "fusipatb_tier{$i}";

            if ( false !== get_option( $new_key, false ) ) {
                continue;
            }
            $old_value = get_option( $old_key, false );
            if ( false !== $old_value ) {
                update_option( $new_key, $old_value );
            }
        }

        for ( $i = 1; $i <= $tier_count; $i++ ) {
            self::migrate_option_once( "tier{$i}", "fusipatb_tier{$i}_amount" );
            self::migrate_option_once( "tier{$i}_multiplier", "fusipatb_tier{$i}_multiplier" );
            self::migrate_option_once( "period{$i}", "fusipatb_period{$i}" );
        }
    }

    /**
     * Copy a single legacy option value to its prefixed replacement, once.
     *
     * @param string $old_key
     * @param string $new_key
     */
    private static function migrate_option_once( $old_key, $new_key ) {
        if ( false !== get_option( $new_key, false ) ) {
            return;
        }
        $old_value = get_option( $old_key, false );
        if ( false !== $old_value ) {
            update_option( $new_key, $old_value );
        }
    }

    /**
     * Copies legacy tbi_promo_products_{id}/tbi_promo_categories_{id} option
     * values to their fusipatb_ replacement, for every scheme id found in the
     * just-migrated fusipatb_active_promo JSON array. Mirrors the decode used
     * throughout src/ (see CartProcessor::resolveCategory()).
     */
    private static function migrate_dynamic_promo_options() {
        $promo_opt = get_option( 'fusipatb_active_promo', '[]' );
        if ( ! $promo_opt || '[]' === $promo_opt ) {
            return;
        }

        $promo_array = json_decode( $promo_opt );
        if ( ! is_array( $promo_array ) ) {
            return;
        }

        foreach ( $promo_array as $promo_scheme ) {
            if ( ! isset( $promo_scheme->id ) ) {
                continue;
            }

            foreach ( array( 'promo_products', 'promo_categories' ) as $suffix ) {
                $old_key = "tbi_{$suffix}_{$promo_scheme->id}";
                $new_key = "fusipatb_{$suffix}_{$promo_scheme->id}";

                if ( false !== get_option( $new_key, false ) ) {
                    continue;
                }
                $old_value = get_option( $old_key, false );
                if ( false !== $old_value ) {
                    update_option( $new_key, $old_value );
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    private static function define_constants() {
        $registry = CountryRegistry::getInstance();
        $config   = $registry->getConfig();

        $plugin_path = untrailingslashit( plugin_dir_path( self::$plugin_file ) );

        if ( ! defined( 'FUSIPATB_PLUGIN_VERSION' ) ) {
            define( 'FUSIPATB_PLUGIN_VERSION', '3.3' );
        }

        // UAT support (BG): define before platform URL resolution so config can honor it.
        if ( ! defined( 'FUSIPATB_FORCE_UAT' ) ) {
            define( 'FUSIPATB_FORCE_UAT', false );
        }

        // Country-specific values (for backward compat with JS / templates)
        if ( ! defined( 'FUSIPATB_MIN_AMOUNT' ) ) {
            define( 'FUSIPATB_MIN_AMOUNT', $config->getMinAmount() );
        }
        if ( ! defined( 'FUSIPATB_MAX_AMOUNT' ) ) {
            define( 'FUSIPATB_MAX_AMOUNT', $config->getMaxAmount() );
        }
        if ( ! defined( 'FUSIPATB_MIN_MONTHS' ) ) {
            define( 'FUSIPATB_MIN_MONTHS', $config->getMinMonths() );
        }
        if ( ! defined( 'FUSIPATB_MAX_MONTHS' ) ) {
            define( 'FUSIPATB_MAX_MONTHS', $config->getMaxMonths() );
        }
        if ( ! defined( 'FUSIPATB_MIN_MONTHS_HIGH' ) ) {
            define( 'FUSIPATB_MIN_MONTHS_HIGH', $config->getMinMonthsHigh() );
        }
        if ( ! defined( 'FUSIPATB_MAX_MONTHS_HIGH' ) ) {
            define( 'FUSIPATB_MAX_MONTHS_HIGH', $config->getMaxMonthsHigh() );
        }
        if ( ! defined( 'FUSIPATB_SHIPPING_NAME' ) ) {
            define( 'FUSIPATB_SHIPPING_NAME', $config->getShippingName() );
        }
        if ( ! defined( 'FUSIPATB_COUPON_NAME' ) ) {
            define( 'FUSIPATB_COUPON_NAME', $config->getCouponName() );
        }

        // Platform URL
        $platform_url = $config->getPlatformUrl();
        if ( ! defined( 'FUSIPATB_PLATFORM_URL' ) ) {
            define( 'FUSIPATB_PLATFORM_URL', $platform_url );
        }

        if ( ! defined( 'FUSIPATB_TEST_ENV' ) ) {
            define( 'FUSIPATB_TEST_ENV', ( stripos( $platform_url, 'tbi-uat.online' ) !== false ) );
        }

        // Allowed origins
        $local_origin = esc_url( site_url() );
        if ( strlen( esc_url( site_url() ) ) > strlen( esc_url( home_url() ) ) ) {
            $local_origin = esc_url( home_url() );
        }
        if ( ! defined( 'FUSIPATB_API_LOCAL_ALLOWED_ORIGIN' ) ) {
            define( 'FUSIPATB_API_LOCAL_ALLOWED_ORIGIN', $local_origin );
        }
        if ( ! defined( 'FUSIPATB_API_GATEWAY_ALLOWED_ORIGIN' ) ) {
            define( 'FUSIPATB_API_GATEWAY_ALLOWED_ORIGIN', $platform_url );
        }

        $allowed_ip = $config->getAllowedIp();
        if ( $allowed_ip && ! defined( 'FUSIPATB_API_GATEWAY_ALLOWED_IP' ) ) {
            define( 'FUSIPATB_API_GATEWAY_ALLOWED_IP', $allowed_ip );
        }

        // URLs
        if ( ! defined( 'FUSIPATB_CALC_URL' ) ) {
            define( 'FUSIPATB_CALC_URL', $platform_url . $config->getCalcUrlPath() );
        }
        if ( ! defined( 'FUSIPATB_API_WOO_SETTINGS' ) ) {
            define( 'FUSIPATB_API_WOO_SETTINGS', $platform_url . $config->getApiSettingsPath() );
        }
        if ( ! defined( 'FUSIPATB_API_WOO_SETTINGS_STATUS' ) ) {
            define( 'FUSIPATB_API_WOO_SETTINGS_STATUS', $platform_url . $config->getApiStatusPath() );
        }
        if ( ! defined( 'FUSIPATB_API_REGISTER_APPLICATION' ) ) {
            define( 'FUSIPATB_API_REGISTER_APPLICATION', $platform_url . '/api/RegisterApplication' );
        }
        if ( ! defined( 'FUSIPATB_DEFAULT_ERROR_PAGE' ) ) {
            define( 'FUSIPATB_DEFAULT_ERROR_PAGE', $platform_url . '/application/error/error_notified' );
        }
        if ( ! defined( 'FUSIPATB_ADVERTISEMENT_PAGE' ) ) {
            define( 'FUSIPATB_ADVERTISEMENT_PAGE', $platform_url . '/tbi-ad/' );
        }

        // CDN
        if ( ! defined( 'FUSIPATB_CDN_URL' ) ) {
            define( 'FUSIPATB_CDN_URL', $config->getCdnUrl() );
        }
        if ( ! defined( 'FUSIPATB_BANK_LOGO' ) ) {
            define( 'FUSIPATB_BANK_LOGO', $config->getBankLogo() );
        }
        if ( ! defined( 'FUSIPATB_BANK_LOGO_WHITE' ) ) {
            define( 'FUSIPATB_BANK_LOGO_WHITE', $config->getBankLogoWhite() );
        }
        if ( ! defined( 'FUSIPATB_BANK_LOGO_LIGHT' ) ) {
            define( 'FUSIPATB_BANK_LOGO_LIGHT', $config->getBankLogoLight() );
        }
        if ( ! defined( 'FUSIPATB_LOADING_SVG' ) ) {
            define( 'FUSIPATB_LOADING_SVG', $config->getLoadingSvg() );
        }

        // Plugin path
        if ( ! defined( 'FUSIPATB_WC_PLUGIN_PATH' ) ) {
            define( 'FUSIPATB_WC_PLUGIN_PATH', $plugin_path );
        }

    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    private static function register_hooks() {
        $registry = CountryRegistry::getInstance();

        // Admin page
        add_filter( 'plugin_action_links_' . untrailingslashit( plugin_basename( self::$plugin_file ) ), array( __CLASS__, 'add_action_links' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

        // Frontend
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_styles' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_scripts' ) );

        // Product button placement
        $button_placement = get_option( 'fusipatb_button_placement', 'woocommerce_after_add_to_cart_button' );
        add_action( $button_placement, array( __CLASS__, 'add_product_button' ), 1 );

        // Popup & cart button
        add_action( 'wp_footer', array( __CLASS__, 'add_popup' ) );
        add_action( 'woocommerce_after_cart_totals', array( __CLASS__, 'add_cart_button' ) );

        // Advertisement (feature-gated)
        if ( $registry->hasFeature( FeatureFlags::ADVERTISING ) ) {
            add_action( 'wp_footer', array( __CLASS__, 'add_advertisement' ) );
        }

        // Payment gateway
        add_action( 'plugins_loaded', array( __CLASS__, 'init_gateway' ) );
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'disable_gateway_when_ineligible' ) );

        // Inject converted checkout total into WC AJAX responses (no JS DOM scraping needed)
        add_filter( 'woocommerce_update_order_review_fragments', array( 'FusionPayTbi\Frontend\Popup', 'filterOrderReviewResponse' ) );

        // WooCommerce Blocks
        add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_blocks_compatibility' ) );
        add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_blocks_gateway' ) );
        add_action( 'woocommerce_blocks_cart_block_registration', array( __CLASS__, 'register_blocks_cart_button' ) );

        // Orders column
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_column' ) );
        add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_column' ) );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_order_column_hpos' ), 10, 2 );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_column_legacy' ), 10, 2 );

        // REST API
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // AJAX handlers
        add_action( 'wp_ajax_nopriv_fusipatb_set_session_data', array( __CLASS__, 'handle_session_data' ) );
        add_action( 'wp_ajax_fusipatb_set_session_data', array( __CLASS__, 'handle_session_data' ) );

        if ( $registry->hasFeature( FeatureFlags::INSTALLMENT_AJAX ) ) {
            add_action( 'wp_ajax_fusipatb_calculate_installments_ajax', array( __CLASS__, 'handle_installments_ajax' ) );
            add_action( 'wp_ajax_nopriv_fusipatb_calculate_installments_ajax', array( __CLASS__, 'handle_installments_ajax' ) );
        }

        if ( get_option( 'fusipatb_product_checked' ) ) {
            add_action( 'wp_ajax_fusipatb_process_product', array( __CLASS__, 'handle_product_ajax' ) );
            add_action( 'wp_ajax_nopriv_fusipatb_process_product', array( __CLASS__, 'handle_product_ajax' ) );
            add_action( 'wp_loaded', array( __CLASS__, 'prevent_add_to_cart_on_product_ajax' ), 10 );
        }

        if ( get_option( 'fusipatb_cart_checked' ) ) {
            add_action( 'wp_ajax_fusipatb_process_cart', array( __CLASS__, 'handle_cart_ajax' ) );
            add_action( 'wp_ajax_nopriv_fusipatb_process_cart', array( __CLASS__, 'handle_cart_ajax' ) );
        }

        // Lightweight AJAX endpoint: returns the converted cart total so JS
        // never needs to scrape the DOM or sum prices client-side.
        add_action( 'wp_ajax_fusipatb_get_cart_total', array( __CLASS__, 'handle_get_cart_total' ) );
        add_action( 'wp_ajax_nopriv_fusipatb_get_cart_total', array( __CLASS__, 'handle_get_cart_total' ) );

        // Direct AJAX endpoints (no DOM selector dependency)
        if ( get_option( 'fusipatb_product_checked' ) ) {
            add_action( 'wp_ajax_fusipatb_process_product_direct', array( __CLASS__, 'handle_direct_product_ajax' ) );
            add_action( 'wp_ajax_nopriv_fusipatb_process_product_direct', array( __CLASS__, 'handle_direct_product_ajax' ) );
        }

        if ( get_option( 'fusipatb_cart_checked' ) ) {
            add_action( 'wp_ajax_fusipatb_process_cart_direct', array( __CLASS__, 'handle_direct_cart_ajax' ) );
            add_action( 'wp_ajax_nopriv_fusipatb_process_cart_direct', array( __CLASS__, 'handle_direct_cart_ajax' ) );
        }

        // Activation / deactivation
        register_activation_hook( self::$plugin_file, array( __CLASS__, 'on_activation' ) );
        register_deactivation_hook( self::$plugin_file, array( __CLASS__, 'on_deactivation' ) );

        // Status-ping consent notice (Guideline 7 follow-up to the consent gate
        // in on_activation()/on_deactivation() above): a dismissible nudge on
        // the Plugins list and this plugin's settings screen -- no forced
        // activation redirect. See StatusPingConsentNotice for the full rule set.
        add_action( 'admin_init', array( __CLASS__, 'maybe_rearm_status_ping_notice' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_status_ping_notice' ) );
        add_action( 'wp_ajax_fusipatb_dismiss_status_notice', array( __CLASS__, 'handle_dismiss_status_notice' ) );

    }

    // -------------------------------------------------------------------------
    // Delegates — each delegates to the appropriate src class
    // -------------------------------------------------------------------------

    public static function add_action_links( $actions ) {
        $link = array(
            '<a href="' . admin_url( 'admin.php?page=tbi-admin-settings' ) . '">' . __( 'Settings', 'fusion-pay-tbi' ) . '</a>',
        );
        return array_merge( $link, $actions );
    }

    public static function register_admin_menu() {
        \FusionPayTbi\Admin\AdminPage::addMenuPage();
    }

    public static function enqueue_admin_assets( $hook ) {
        \FusionPayTbi\Admin\AdminAssets::enqueue( $hook );
    }

    public static function enqueue_frontend_styles() {
        \FusionPayTbi\Frontend\FrontendAssets::enqueueStyles();
    }

    public static function enqueue_frontend_scripts() {
        \FusionPayTbi\Frontend\FrontendAssets::enqueueScripts();
    }

    public static function add_product_button() {
        \FusionPayTbi\Frontend\ProductButton::render();
    }

    public static function add_popup() {
        \FusionPayTbi\Frontend\Popup::render();
    }

    public static function add_cart_button() {
        \FusionPayTbi\Frontend\CartButton::render();
    }

    public static function add_advertisement() {
        \FusionPayTbi\Frontend\Advertisement::render();
    }

    public static function init_gateway() {
        require_once FUSIPATB_WC_PLUGIN_PATH . '/src/Gateway/PaymentGateway.php';
        if ( function_exists( 'fusipatb_init_gateway_class' ) ) {
            fusipatb_init_gateway_class();
        }
    }

    public static function register_gateway( $gateways ) {
        $gateways[] = 'Fusipatb_PaymentGateway';
        return $gateways;
    }

    public static function disable_gateway_when_ineligible( $gateways ) {
        return \FusionPayTbi\Gateway\GatewayAvailability::filter( $gateways );
    }

    public static function declare_blocks_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', self::$plugin_file, true );
        }
    }

    public static function register_blocks_gateway() {
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }
        require_once FUSIPATB_WC_PLUGIN_PATH . '/src/Gateway/BlocksGateway.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
                $registry->register( new \Fusipatb_BlocksGateway() );
            }
        );
    }

    public static function register_blocks_cart_button( $registry ) {
        if ( ! interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
            return;
        }
        $registry->register( new \FusionPayTbi\Frontend\BlocksCartIntegration() );
    }

    public static function add_order_column( $columns ) {
        return \FusionPayTbi\Orders\StatusColumn::addColumn( $columns );
    }

    public static function render_order_column_hpos( $column, $order_id ) {
        \FusionPayTbi\Orders\StatusColumn::renderHpos( $column, $order_id );
    }

    public static function render_order_column_legacy( $column, $post_id = null ) {
        \FusionPayTbi\Orders\StatusColumn::renderLegacy( $column, $post_id );
    }

    public static function register_rest_routes() {
        register_rest_route( 'api/tbi/v1', '/tbi-api-gateway', array(
            'methods'             => 'POST',
            'callback'            => array( 'FusionPayTbi\Api\RestGateway', 'handle' ),
            'permission_callback' => array( 'FusionPayTbi\Api\RequestAuthenticator', 'authenticate' ),
        ) );
    }

    public static function handle_session_data() {
        \FusionPayTbi\Api\SessionDataHandler::handle();
    }

    public static function handle_installments_ajax() {
        \FusionPayTbi\Api\InstallmentCalculator::handleAjax();
    }

    public static function handle_product_ajax() {
        \FusionPayTbi\Api\ProductProcessor::handle();
    }

    public static function handle_cart_ajax() {
        \FusionPayTbi\Api\CartProcessor::handle();
    }

    public static function handle_get_cart_total() {
        \FusionPayTbi\Frontend\CartButton::handleGetCartTotal();
    }

    public static function handle_direct_product_ajax() {
        \FusionPayTbi\Api\DirectProductProcessor::handle();
    }

    public static function handle_direct_cart_ajax() {
        \FusionPayTbi\Api\DirectCartProcessor::handle();
    }

    public static function maybe_rearm_status_ping_notice() {
        \FusionPayTbi\Admin\StatusPingConsentNotice::maybeRearm();
    }

    public static function render_status_ping_notice() {
        \FusionPayTbi\Admin\StatusPingConsentNotice::renderNotice();
    }

    public static function handle_dismiss_status_notice() {
        \FusionPayTbi\Admin\StatusPingConsentNotice::handleDismiss();
    }

    public static function prevent_add_to_cart_on_product_ajax() {
        if ( isset( $_POST['action'] ) && sanitize_text_field( wp_unslash( $_POST['action'] ) ) === 'fusipatb_process_product' ) {
            remove_action( 'wp_loaded', array( 'WC_Form_Handler', 'add_to_cart_action' ), 20 );
        }
    }

    public static function on_activation() {
        // Migrate legacy unprefixed option key to prefixed version.
        $old_value = get_option( 'product_button' );
        if ( $old_value !== false && get_option( 'fusipatb_product_button' ) === false ) {
            update_option( 'fusipatb_product_button', $old_value );
            delete_option( 'product_button' );
        }

        $config = CountryRegistry::getInstance()->getConfig();
        if ( empty( get_option( 'fusipatb_reseller_code' ) ) || empty( get_option( 'fusipatb_reseller_key' ) ) ) {
            return;
        }
        // Guideline 7: configured credentials are not consent by themselves —
        // the merchant must have explicitly opted in via the settings-page
        // checkbox before we contact the external status endpoint.
        if ( get_option( 'fusipatb_status_ping_consent' ) !== '1' ) {
            return;
        }
        $body = json_encode( array(
            'reseller_code' => sanitize_text_field( get_option( 'fusipatb_reseller_code' ) ),
            'reseller_key'  => sanitize_text_field( get_option( 'fusipatb_reseller_key' ) ),
            'plugin'        => 'WooCommerce',
            'version'       => FUSIPATB_PLUGIN_VERSION,
            'status'        => 'active',
            'date'          => gmdate( 'Y-m-d H:i:s' ),
        ) );
        wp_remote_post( FUSIPATB_API_WOO_SETTINGS_STATUS, array( 'body' => $body ) );
    }

    public static function on_deactivation() {
        $config = CountryRegistry::getInstance()->getConfig();
        if ( empty( get_option( 'fusipatb_reseller_code' ) ) || empty( get_option( 'fusipatb_reseller_key' ) ) ) {
            return;
        }
        // Guideline 7: same explicit-consent gate as on_activation() above.
        if ( get_option( 'fusipatb_status_ping_consent' ) !== '1' ) {
            return;
        }
        $body = json_encode( array(
            'reseller_code' => sanitize_text_field( get_option( 'fusipatb_reseller_code' ) ),
            'reseller_key'  => sanitize_text_field( get_option( 'fusipatb_reseller_key' ) ),
            'plugin'        => 'WooCommerce',
            'version'       => FUSIPATB_PLUGIN_VERSION,
            'status'        => 'inactive',
            'date'          => gmdate( 'Y-m-d H:i:s' ),
        ) );
        wp_remote_post( FUSIPATB_API_WOO_SETTINGS_STATUS, array( 'body' => $body ) );
    }

}



