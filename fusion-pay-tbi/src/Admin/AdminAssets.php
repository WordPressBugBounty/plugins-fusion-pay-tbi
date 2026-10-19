<?php

namespace FusionPayTbi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminAssets {

    public static function enqueue( $hook ) {
        $current_screen = get_current_screen();
        if ( ! $current_screen || strpos( $current_screen->base, 'tbi-admin-settings' ) === false ) {
            return;
        }

        $css_file = FUSIPATB_WC_PLUGIN_PATH . '/assets/css/admin.css';
        wp_enqueue_style(
            'tbi-admin-css',
            plugins_url( 'assets/css/admin.css', FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ),
            array(),
            file_exists( $css_file ) ? filemtime( $css_file ) : '1'
        );

        $js_file = FUSIPATB_WC_PLUGIN_PATH . '/assets/js/admin.js';
        if ( file_exists( $js_file ) ) {
            wp_enqueue_script(
                'tbi-admin-js',
                plugins_url( 'assets/js/admin.js', FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ),
                array( 'jquery' ),
                filemtime( $js_file ),
                true
            );
        }
    }
}



