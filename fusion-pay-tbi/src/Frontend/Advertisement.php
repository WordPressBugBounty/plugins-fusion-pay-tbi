<?php

namespace FusionPayTbi\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Advertisement {

    public static function render() {
        if ( get_option( 'fusipatb_advertising' ) == 0 ) {
            return;
        }
        if ( ! get_option( 'fusipatb_checkout_status' ) ) {
            return;
        }

        $advertisement_url = FUSIPATB_ADVERTISEMENT_PAGE . '?web=' . urlencode( esc_url( site_url() ) );

        include_once FUSIPATB_WC_PLUGIN_PATH . '/includes/views/tbi_checkout_advertisement.php';
    }
}



