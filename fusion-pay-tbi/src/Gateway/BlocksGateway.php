<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Fusipatb_BlocksGateway extends AbstractPaymentMethodType {

    public $id = 'tbi_bank';

    public $settings = array();

    protected $name = 'tbi_bank';

    private $gateway;

    public function initialize() {
        $this->settings = get_option( 'woocommerce_tbi_bank_settings', array() );
        $this->gateway  = new Fusipatb_PaymentGateway();
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        $assets_dir = FUSIPATB_WC_PLUGIN_PATH . '/assets/js/';
        $file       = $assets_dir . 'tbi_blocks_integration.js';

        wp_register_script(
            'tbi-blocks-integration',
            plugins_url( 'assets/js/tbi_blocks_integration.js', FUSIPATB_WC_PLUGIN_PATH . '/fusion-pay-tbi.php' ) . '?ver=' . ( file_exists( $file ) ? filemtime( $file ) : '1' ),
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
            null,
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'tbi-blocks-integration', 'fusion-pay-tbi' );
        }

        return array( 'tbi-blocks-integration' );
    }

    public function get_payment_method_data() {
        global $woocommerce;
        if ( null === $woocommerce->cart ) {
            return array();
        }
        $config = \FusionPayTbi\Config\CountryRegistry::getInstance()->getConfig();
        return array(
            'title'       => $this->gateway->title,
            'description' => $this->gateway->description,
            'popup_url'   => get_option( 'fusipatb_popup_url', '' ),
            'supports'    => array( 'products' ),
            'nonce'       => wp_create_nonce( 'tbi_gateway_action' ),
            'cartNonce'   => wp_create_nonce( 'fusipatb_process_cart' ),
            'platformUrl' => $config->getPlatformUrl(),
        );
    }
}



