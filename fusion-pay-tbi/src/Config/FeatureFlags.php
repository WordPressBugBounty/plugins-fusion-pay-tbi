<?php

namespace FusionPayTbi\Config;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Feature flag name constants to prevent typos across the codebase.
 */
class FeatureFlags {
    const ADVERTISING               = 'advertising';
    const DUAL_CURRENCY             = 'dual_currency';
    const RENDER_BUTTON_API         = 'render_button_api';
    const INSTALLMENT_AJAX          = 'installment_ajax';
    const SESSION_DATA_AJAX         = 'session_data_ajax';
    const IP_WHITELIST              = 'ip_whitelist';
    const WC_BUNDLES_COMPAT         = 'wc_bundles_compat';
    const SEQUENTIAL_ORDERS         = 'sequential_orders';
    const WCPA_SUPPORT              = 'wcpa_support';
    const WAPF_SUPPORT              = 'wapf_support';
    const ANALYSIS_FEES             = 'analysis_fees';
    const DYNAMIC_TIERS             = 'dynamic_tiers';
    const UAT_SUPPORT               = 'uat_support';
    const EXTENDED_BUTTON_VARIANTS  = 'extended_button_variants';
}
