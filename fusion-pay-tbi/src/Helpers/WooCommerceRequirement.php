<?php

namespace FusionPayTbi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects whether WooCommerce is active and, when it is not, registers a
 * dismissible admin notice explaining why Fusion Pay by tbi bank did nothing.
 *
 * Loaded directly (require_once) by fusion-pay-tbi.php, before the plugin's
 * PSR-4 autoloader is registered -- so this check must be reachable even when
 * the rest of the plugin never bootstraps.
 */
class WooCommerceRequirement {

    const PLUGIN_BASENAME = 'woocommerce/woocommerce.php';

    /**
     * @return bool True when WooCommerce is active (network-wide or on this site).
     */
    public static function isWooCommerceActive() {
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . '/wp-admin/includes/plugin.php';
        }

        if ( is_plugin_active_for_network( self::PLUGIN_BASENAME ) ) {
            return true;
        }

        $active_plugins = apply_filters( 'active_plugins', get_option( 'active_plugins' ) );
        return in_array( self::PLUGIN_BASENAME, $active_plugins );
    }

    /**
     * Hooks the admin notice that explains why the plugin did nothing.
     */
    public static function notifyMissing() {
        add_action( 'admin_notices', array( __CLASS__, 'renderMissingNotice' ) );
    }

    /**
     * Renders the dismissible "WooCommerce required" admin notice. Shown only
     * to users who can actually act on it (installing/activating plugins).
     */
    public static function renderMissingNotice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error is-dismissible"><p>' .
            esc_html__( 'Fusion Pay by tbi bank requires WooCommerce to be installed and active.', 'fusion-pay-tbi' ) .
            '</p></div>';
    }
}
