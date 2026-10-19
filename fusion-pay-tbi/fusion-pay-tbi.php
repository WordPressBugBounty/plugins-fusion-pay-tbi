<?php
/**
 * @package Fusion Pay by tbi bank
 */

/*
Plugin Name: Fusion Pay by tbi bank
Plugin URI: https://beta.tbibank.support/fusion-pay-tbi/woocommerce/
Description: Fusion Pay by tbi bank lets merchants integrate tbi bank installment and BNPL payment methods
Version: 3.3
Author: tbi bank
Author URI: https://tbibank.bg
License: GPLv2 or later
Text Domain: fusion-pay-tbi
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/src/Helpers/WooCommerceRequirement.php';

if ( FusionPayTbi\Helpers\WooCommerceRequirement::isWooCommerceActive() ) {

    // PSR-4 autoloader for FusionPayTbi namespace
    spl_autoload_register( function ( $class ) {
        $prefix   = 'FusionPayTbi\\';
        $base_dir = __DIR__ . '/src/';
        if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
            return;
        }
        $file = $base_dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
        if ( file_exists( $file ) ) {
            require $file;
        }
    } );

    // Bootstrap the plugin
    FusionPayTbi\Bootstrap::init( __FILE__ );
} else {
    // WooCommerce is required but not active: explain why instead of silently
    // doing nothing.
    FusionPayTbi\Helpers\WooCommerceRequirement::notifyMissing();
}
