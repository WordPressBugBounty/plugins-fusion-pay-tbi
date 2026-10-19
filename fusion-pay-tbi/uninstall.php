<?php
// Exit if accessed directly
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Load config to get country-specific API URL
// We can't rely on the autoloader here, so we detect country manually
$wc_country = get_option( 'woocommerce_default_country', '' );
$country     = strtoupper( explode( ':', $wc_country )[0] );
if ( defined( 'FUSION_PAY_TBI_COUNTRY' ) ) {
    $country = strtoupper( FUSION_PAY_TBI_COUNTRY );
}

switch ( $country ) {
    case 'RO':
        $platform_url  = 'https://ro.tbibank.support';
        $status_path   = '/api/PluginsSettings/status';
        $plugin_ver    = '3.3';
        break;
    case 'GR':
        $platform_url  = 'https://gr.tbibank.support';
        $status_path   = '/api/PluginsSettings/status';
        $plugin_ver    = '3.3';
        break;
    default: // BG
        $platform_url  = 'https://beta.tbibank.support';
        $status_path   = '/api/PluginStatusUpdate';
        $plugin_ver    = '3.3';
        break;
}

$status_url = $platform_url . $status_path;

// migrate_legacy_options() never deletes the old key (it only copies forward),
// so the legacy key still holds a value on a migrated site too -- read the new
// key first and fall back to the legacy one so this ping works regardless of
// whether the site ever ran Bootstrap::init().
$reseller_code = get_option( 'fusipatb_reseller_code' ) ?: get_option( 'tbi_reseller_code' );
$reseller_key  = get_option( 'fusipatb_reseller_key' ) ?: get_option( 'tbi_reseller_key' );

$body = wp_json_encode( array(
    'reseller_code' => sanitize_text_field( $reseller_code ),
    'reseller_key'  => sanitize_text_field( $reseller_key ),
    'plugin'        => 'WooCommerce',
    'version'       => $plugin_ver,
    'status'        => 'uninstalled',
    'date'          => gmdate( 'Y-m-d H:i:s' ),
) );

wp_remote_post( $status_url, array( 'body' => $body ) );

// Delete all plugin options
$options = array(
    // Legacy unprefixed tbi_* keys (pre fusipatb_ rename, CKT-3894) — kept here
    // so sites that never triggered the migration still get cleaned up.
    'tbi_reseller_code',
    'tbi_reseller_key',
    'tbi_encryption_key',
    'tbi_checkout_status',
    'tbi_active_promo',
    'tbi_admin_currency',
    'tbi_advertising',
    'tbi_button_placement',
    'tbi_cart_checked',
    'tbi_default_status',
    'tbi_delivery_fee',
    'tbi_delivery_fee_prc',
    'tbi_delivery_limit',
    'tbi_last_settings_error',
    'tbi_max_amount',
    'tbi_max_amount_eur',
    'tbi_min_amount',
    'tbi_min_amount_eur',
    'tbi_popup_checkout',
    'tbi_popup_url',
    'tbi_product_button',
    'tbi_product_button_text',
    'tbi_cart_button_text',
    'tbi_product_checked',
    'tbi_sale_promooff',
    'tbi_store_currency',
    'tbi_tier_count',
    'tbi_tier1',
    'tbi_tier2',
    'tbi_tier3',
    'tbi_tier1_limit',
    'tbi_tier2_limit',
    'tbi_tier3_limit',
    'tbi_tier1_limit_eur',
    'tbi_tier2_limit_eur',
    'tbi_tier3_limit_eur',
    'tbi_bnpl_min_amount',
    'tbi_bnpl_max_amount',
    'tbi_euro_multiplier',
    'tbi_eur_min_amount',
    'tbi_eur_max_amount',
    // Current fusipatb_-prefixed keys (CKT-3894 tbi_* -> fusipatb_* rename).
    'fusipatb_reseller_code',
    'fusipatb_reseller_key',
    'fusipatb_encryption_key',
    'fusipatb_checkout_status',
    'fusipatb_active_promo',
    'fusipatb_admin_currency',
    'fusipatb_advertising',
    'fusipatb_button_placement',
    'fusipatb_cart_checked',
    'fusipatb_default_status',
    'fusipatb_delivery_fee',
    'fusipatb_delivery_fee_prc',
    'fusipatb_delivery_limit',
    'fusipatb_last_settings_error',
    'fusipatb_max_amount',
    'fusipatb_max_amount_eur',
    'fusipatb_min_amount',
    'fusipatb_min_amount_eur',
    'fusipatb_popup_checkout',
    'fusipatb_popup_url',
    'fusipatb_product_button',
    'fusipatb_product_button_text',
    'fusipatb_cart_button_text',
    'fusipatb_product_checked',
    'fusipatb_sale_promooff',
    'fusipatb_store_currency',
    'fusipatb_tier_count',
    'fusipatb_tier1',
    'fusipatb_tier2',
    'fusipatb_tier3',
    'fusipatb_tier1_limit',
    'fusipatb_tier2_limit',
    'fusipatb_tier3_limit',
    'fusipatb_tier1_limit_eur',
    'fusipatb_tier2_limit_eur',
    'fusipatb_tier3_limit_eur',
    // Legacy unprefixed keys (pre fusipatb_ rename, earlier bnpl_* cleanup) —
    // kept here so upgraded sites that never triggered the migration still
    // get cleaned up.
    'bnpl_min_amount',
    'bnpl_max_amount',
    'bnpl_min_amount_eur',
    'bnpl_max_amount_eur',
    'bnpl_min',
    'bnpl_max',
    'bnpl_period',
    'bnpl_multiplier',
    'bnpl_analysis_fee',
    'standard_analysis_fee',
    'euro_multiplier',
    'tier_currency',
    // Current fusipatb_-prefixed keys.
    'fusipatb_bnpl_min_amount',
    'fusipatb_bnpl_max_amount',
    'fusipatb_bnpl_min_amount_eur',
    'fusipatb_bnpl_max_amount_eur',
    'fusipatb_bnpl_min',
    'fusipatb_bnpl_max',
    'fusipatb_bnpl_period',
    'fusipatb_bnpl_multiplier',
    'fusipatb_bnpl_analysis_fee',
    'fusipatb_standard_analysis_fee',
    'fusipatb_euro_multiplier',
    'fusipatb_tier_currency',
    'fusipatb_legacy_options_migrated',
);

// Delete dynamic tier options (up to 20 tiers).
for ( $i = 1; $i <= 20; $i++ ) {
    $options[] = "tier{$i}";
    $options[] = "tier{$i}_multiplier";
    $options[] = "period{$i}";
    // Dynamic per-tier fusipatb_tier{N} option (N beyond the static
    // fusipatb_tier1-3 entries above; see Bootstrap::migrate_dynamic_tier_options()).
    $options[] = "fusipatb_tier{$i}";
}

// Delete dynamic promo product/category options, decoding both the new and
// legacy active_promo option the same way
// Bootstrap::migrate_dynamic_promo_options() does, so this cleans up whether
// or not the site ever ran the migration.
foreach ( array( 'fusipatb_active_promo', 'tbi_active_promo' ) as $active_promo_key ) {
    $promo_opt = get_option( $active_promo_key, '[]' );
    if ( ! $promo_opt || '[]' === $promo_opt ) {
        continue;
    }
    $promo_array = json_decode( $promo_opt );
    if ( ! is_array( $promo_array ) ) {
        continue;
    }
    foreach ( $promo_array as $promo_scheme ) {
        if ( ! isset( $promo_scheme->id ) ) {
            continue;
        }
        $options[] = 'fusipatb_promo_products_' . $promo_scheme->id;
        $options[] = 'fusipatb_promo_categories_' . $promo_scheme->id;
        $options[] = 'tbi_promo_products_' . $promo_scheme->id;
        $options[] = 'tbi_promo_categories_' . $promo_scheme->id;
    }
}

foreach ( $options as $option_name ) {
    delete_option( $option_name );
}


