<?php

namespace FusionPayTbi\Frontend;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Integrates the TBI installment button into the WooCommerce Blocks cart.
 *
 * Implements IntegrationInterface so that WooCommerce Blocks picks up the
 * script and passes server-computed button data to it via wcSettings.
 */
class BlocksCartIntegration implements IntegrationInterface {

    /**
     * The name used by WooCommerce Blocks to namespace the data exposed via
     * window.wc.wcSettings.getSetting('[name]_data', {}).
     */
    public function get_name() {
        return 'tbi-cart-button';
    }

    public function initialize() {
        // Nothing to initialize here — CartButton handles all option reading.
    }

    /**
     * Register and return the handle for the blocks cart JS.
     *
     * @return string[]
     */
    public function get_script_handles() {
        $file = FUSIPATB_WC_PLUGIN_PATH . '/assets/js/tbi_blocks_cart.js';

        wp_register_script(
            'tbi-blocks-cart',
            plugins_url( 'assets/js/tbi_blocks_cart.js', FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ) . '?ver=' . ( file_exists( $file ) ? filemtime( $file ) : '1' ),
            array( 'wc-settings', 'wp-data' ),
            null,
            true
        );

        return array( 'tbi-blocks-cart' );
    }

    /**
     * No editor script needed for the cart button.
     *
     * @return string[]
     */
    public function get_editor_script_handles() {
        return array();
    }

    /**
     * Pass server-computed button data to the JS integration.
     *
     * @return array
     */
    public function get_script_data() {
        return CartButton::getBlocksData();
    }
}



