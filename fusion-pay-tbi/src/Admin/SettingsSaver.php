<?php

namespace FusionPayTbi\Admin;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\Cryptor;
use FusionPayTbi\Helpers\CurrencyHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SettingsSaver {

    /**
     * Process admin form POST and call API.
     * Returns error message string or empty string on success.
     *
     * @return string
     */
    public static function save() {
        if ( empty( $_POST ) || ! check_admin_referer( 'tbi_checkout_action', 'tbi_checkout_nonce' ) ) {
            return '';
        }

        $config                  = CountryRegistry::getInstance()->getConfig();
        $previous_checkout_status = get_option( 'fusipatb_checkout_status' );
        update_option( 'fusipatb_last_settings_error', '' );

        // --- Credentials ---
        if ( isset( $_POST['reseller_code'] ) && strlen( $_POST['reseller_code'] ) <= 50 ) {
            update_option( 'fusipatb_reseller_code', sanitize_text_field( wp_unslash( $_POST['reseller_code'] ) ) );
        }
        if ( isset( $_POST['reseller_key'] ) ) {
            update_option( 'fusipatb_reseller_key', sanitize_text_field( wp_unslash( $_POST['reseller_key'] ) ) );
        }
        if ( isset( $_POST['encryption_key'] ) && strlen( $_POST['encryption_key'] ) < 130 ) {
            update_option( 'fusipatb_encryption_key', sanitize_text_field( wp_unslash( $_POST['encryption_key'] ) ) );
        }

        // --- Call API ---
        self::callSettingsApi( $config, $previous_checkout_status );

        // --- Save form fields (non-API) ---
        self::saveFormFields( $config );

        // --- Ping status endpoint ---
        if ( get_option( 'fusipatb_reseller_code' ) && get_option( 'fusipatb_reseller_key' ) ) {
            self::pingStatus( $config );
        }

        return (string) get_option( 'fusipatb_last_settings_error', '' );
    }

    private static function callSettingsApi( $config, $previous_checkout_status ) {
        $cryptor = new Cryptor();

        $tier_periods = self::collectTierPeriods( $config );

        // Build request body based on country config
        $country_code = $config->getCountryCode();
        if ( $country_code === 'RO' ) {
            // Romania uses different API format: no encrypted data, just plain credentials
            $body = json_encode( array(
                'reseller_code' => sanitize_text_field( wp_unslash( isset( $_POST['reseller_code'] ) ? $_POST['reseller_code'] : '' ) ),
                'reseller_key'  => sanitize_text_field( wp_unslash( isset( $_POST['reseller_key'] ) ? $_POST['reseller_key'] : '' ) ),
                'plugin'        => 'WooCommerce',
            ) );
        } else {
            // Bulgaria/Greece use encrypted tier period data
            $inner = array();
            // Backward compatibility: when exactly 3 tiers, keep legacy keys.
            if ( count( $tier_periods ) === 3 ) {
                $inner['tier1_period'] = $tier_periods[0];
                $inner['tier2_period'] = $tier_periods[1];
                $inner['tier3_period'] = $tier_periods[2];
            }
            // Always send periods[] too for dynamic-tier support.
            $inner['periods'] = array_values( $tier_periods );

            $json_data = json_encode( $inner );

            $encryption_key = sanitize_text_field( isset( $_POST['encryption_key'] ) ? wp_unslash( $_POST['encryption_key'] ) : get_option( 'fusipatb_encryption_key', '' ) );
            $encrypted_data = $cryptor->Encrypt( $json_data, $encryption_key );

            $body = json_encode( array(
                'reseller_code' => sanitize_text_field( wp_unslash( isset( $_POST['reseller_code'] ) ? $_POST['reseller_code'] : '' ) ),
                'reseller_key'  => sanitize_text_field( wp_unslash( isset( $_POST['reseller_key'] ) ? $_POST['reseller_key'] : '' ) ),
                'data'          => $encrypted_data,
                'plugin'        => 'WooCommerce',
            ) );
        }

        $args = array(
            'body'    => $body,
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
        );

        $response = wp_remote_post( FUSIPATB_API_WOO_SETTINGS, $args );

        if ( is_wp_error( $response ) ) {
            update_option( 'fusipatb_last_settings_error', $response->get_error_message() );
            if ( $previous_checkout_status !== null ) {
                update_option( 'fusipatb_checkout_status', $previous_checkout_status );
            }
            return;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code != 200 ) {
            update_option( 'fusipatb_last_settings_error', 'Settings API returned HTTP ' . $response_code );
            if ( $previous_checkout_status !== null ) {
                update_option( 'fusipatb_checkout_status', $previous_checkout_status );
            }
            return;
        }

        $body_raw      = wp_remote_retrieve_body( $response );
        $response_body = json_decode( $body_raw );

        if ( ! isset( $response_body ) || ! is_object( $response_body ) ) {
            update_option( 'fusipatb_last_settings_error', 'Settings API returned invalid JSON.' );
            if ( $previous_checkout_status !== null ) {
                update_option( 'fusipatb_checkout_status', $previous_checkout_status );
            }
            return;
        }

        $api_error = self::parseApiError( $response_body );

        if ( $api_error !== 0 ) {
            $message = '';
            foreach ( array( 'message', 'msg', 'error_message' ) as $key ) {
                if ( isset( $response_body->{$key} ) && is_string( $response_body->{$key} ) ) {
                    $message = $response_body->{$key};
                    break;
                }
            }
            if ( $message === '' ) {
                $message = 'Settings API returned error.';
            }
            update_option( 'fusipatb_last_settings_error', $message );
            update_option( 'fusipatb_checkout_status', false );
            return;
        }

        // Success — store API response values
        update_option( 'fusipatb_checkout_status', true );
        update_option( 'fusipatb_last_settings_error', '' );

        if ( isset( $response_body->bnpl_min_amount ) ) update_option( 'fusipatb_bnpl_min_amount', $response_body->bnpl_min_amount );
        if ( isset( $response_body->bnpl_max_amount ) ) update_option( 'fusipatb_bnpl_max_amount', $response_body->bnpl_max_amount );
        if ( isset( $response_body->bnpl_min_amount_eur ) ) update_option( 'fusipatb_bnpl_min_amount_eur', $response_body->bnpl_min_amount_eur );
        if ( isset( $response_body->bnpl_max_amount_eur ) ) update_option( 'fusipatb_bnpl_max_amount_eur', $response_body->bnpl_max_amount_eur );
        if ( isset( $response_body->tier1_multiplier ) ) update_option( 'fusipatb_tier1_multiplier', $response_body->tier1_multiplier );
        if ( isset( $response_body->tier2_multiplier ) ) update_option( 'fusipatb_tier2_multiplier', $response_body->tier2_multiplier );
        if ( isset( $response_body->tier3_multiplier ) ) update_option( 'fusipatb_tier3_multiplier', $response_body->tier3_multiplier );
        if ( isset( $response_body->promo ) ) update_option( 'fusipatb_active_promo', json_encode( $response_body->promo ) );
        if ( isset( $response_body->euro_multiplier ) ) update_option( 'fusipatb_euro_multiplier', $response_body->euro_multiplier );
        if ( isset( $response_body->tier_currency ) ) update_option( 'fusipatb_tier_currency', $response_body->tier_currency );
        if ( isset( $response_body->tier1 ) ) update_option( 'fusipatb_tier1_limit', $response_body->tier1 );
        if ( isset( $response_body->tier2 ) ) update_option( 'fusipatb_tier2_limit', $response_body->tier2 );
        if ( isset( $response_body->tier3 ) ) update_option( 'fusipatb_tier3_limit', $response_body->tier3 );
        if ( isset( $response_body->tier1_eur ) ) update_option( 'fusipatb_tier1_limit_eur', $response_body->tier1_eur );
        if ( isset( $response_body->tier2_eur ) ) update_option( 'fusipatb_tier2_limit_eur', $response_body->tier2_eur );
        if ( isset( $response_body->tier3_eur ) ) update_option( 'fusipatb_tier3_limit_eur', $response_body->tier3_eur );
        if ( isset( $response_body->product_button ) ) update_option( 'fusipatb_product_button', $response_body->product_button );

        // Dynamic tiers from tiers/periods/multipliers arrays.
        // Accept only when all three are non-empty arrays of equal length; otherwise
        // fall back to the legacy static 3-tier fields (tier{1..3}, tier{1..3}_multiplier)
        // so BG endpoints that still return the static shape keep working.
        $tiers_raw      = isset( $response_body->tiers ) ? $response_body->tiers : null;
        $periods_raw    = isset( $response_body->periods ) ? $response_body->periods : null;
        $mults_raw      = isset( $response_body->multipliers ) ? $response_body->multipliers : null;

        $tiers_arr   = is_array( $tiers_raw ) ? $tiers_raw : ( is_object( $tiers_raw ) ? array_values( (array) $tiers_raw ) : null );
        $periods_arr = is_array( $periods_raw ) ? $periods_raw : ( is_object( $periods_raw ) ? array_values( (array) $periods_raw ) : null );
        $mults_arr   = is_array( $mults_raw ) ? $mults_raw : ( is_object( $mults_raw ) ? array_values( (array) $mults_raw ) : null );

        $dynamic_ok = (
            is_array( $tiers_arr ) && is_array( $periods_arr ) && is_array( $mults_arr )
            && count( $tiers_arr ) > 0
            && count( $tiers_arr ) === count( $periods_arr )
            && count( $tiers_arr ) === count( $mults_arr )
        );

        // Clear any prior dynamic tier options before writing a fresh set (dynamic or fallback).
        $existing_tiers = intval( get_option( 'fusipatb_tier_count', 0 ) );
        for ( $i = 1; $i <= $existing_tiers; $i++ ) {
            delete_option( "fusipatb_tier{$i}_multiplier" );
            delete_option( "fusipatb_tier{$i}_amount" );
            delete_option( "fusipatb_period{$i}" );
        }

        if ( $dynamic_ok ) {
            $tier_count = count( $tiers_arr );
            update_option( 'fusipatb_tier_count', $tier_count );
            for ( $i = 0; $i < $tier_count; $i++ ) {
                $idx = $i + 1;
                update_option( "fusipatb_tier{$idx}_multiplier", $mults_arr[ $i ] );
                update_option( "fusipatb_tier{$idx}_amount", $tiers_arr[ $i ] );
                update_option( "fusipatb_period{$idx}", $periods_arr[ $i ] );
            }
        } else {
            // Static fallback: build 3-tier data from legacy response fields + posted periods.
            // Only apply when at least one legacy tier field is present, to avoid wiping state on partial responses.
            $has_legacy = isset( $response_body->tier1 ) || isset( $response_body->tier2 ) || isset( $response_body->tier3 )
                || isset( $response_body->tier1_multiplier ) || isset( $response_body->tier2_multiplier ) || isset( $response_body->tier3_multiplier );

            if ( $has_legacy ) {
                $posted_periods = self::collectTierPeriods( $config );
                $legacy_tiers = array(
                    1 => array(
                        'tier'       => isset( $response_body->tier1 ) ? $response_body->tier1 : 0,
                        'multiplier' => isset( $response_body->tier1_multiplier ) ? $response_body->tier1_multiplier : 0,
                    ),
                    2 => array(
                        'tier'       => isset( $response_body->tier2 ) ? $response_body->tier2 : 0,
                        'multiplier' => isset( $response_body->tier2_multiplier ) ? $response_body->tier2_multiplier : 0,
                    ),
                    3 => array(
                        'tier'       => isset( $response_body->tier3 ) ? $response_body->tier3 : 0,
                        'multiplier' => isset( $response_body->tier3_multiplier ) ? $response_body->tier3_multiplier : 0,
                    ),
                );
                update_option( 'fusipatb_tier_count', 3 );
                foreach ( $legacy_tiers as $idx => $row ) {
                    update_option( "fusipatb_tier{$idx}_amount", $row['tier'] );
                    update_option( "fusipatb_tier{$idx}_multiplier", $row['multiplier'] );
                    $period_val = isset( $posted_periods[ $idx - 1 ] )
                        ? $posted_periods[ $idx - 1 ]
                        : intval( get_option( "fusipatb_tier{$idx}", 0 ) );
                    update_option( "fusipatb_period{$idx}", $period_val );
                }
            }
        }

        // RO-style: bnpl_period / bnpl_multiplier / bnpl_min / bnpl_max
        if ( isset( $response_body->bnpl_period ) ) update_option( 'fusipatb_bnpl_period', $response_body->bnpl_period );
        if ( isset( $response_body->bnpl_multiplier ) ) update_option( 'fusipatb_bnpl_multiplier', $response_body->bnpl_multiplier );
        if ( isset( $response_body->bnpl_min ) ) update_option( 'fusipatb_bnpl_min', $response_body->bnpl_min );
        if ( isset( $response_body->bnpl_max ) ) update_option( 'fusipatb_bnpl_max', $response_body->bnpl_max );
        // Keep canonical BNPL amount options in sync for RO responses.
        // BG/GR already send bnpl_min_amount/bnpl_max_amount directly.
        if ( isset( $response_body->bnpl_min ) && ! isset( $response_body->bnpl_min_amount ) ) {
            update_option( 'fusipatb_bnpl_min_amount', $response_body->bnpl_min );
        }
        if ( isset( $response_body->bnpl_max ) && ! isset( $response_body->bnpl_max_amount ) ) {
            update_option( 'fusipatb_bnpl_max_amount', $response_body->bnpl_max );
        }
        if ( isset( $response_body->bnpl_analysis_fee ) ) update_option( 'fusipatb_bnpl_analysis_fee', $response_body->bnpl_analysis_fee );
        if ( isset( $response_body->standard_analysis_fee ) ) update_option( 'fusipatb_standard_analysis_fee', $response_body->standard_analysis_fee );

        // Amount limits from API
        self::saveAmountLimits( $response_body );
    }

    /**
     * Collect button_tierN values from POST, clamped by country config.
     * Tier count is bounded by tbi_tier_count (API-driven) when > 0, else the
     * count of contiguous button_tierN POST keys, else 3 for legacy.
     * The last tier is clamped with getMin/MaxMonthsHigh; others with getMin/MaxMonths.
     *
     * @return array<int,int> Zero-indexed list of clamped tier periods.
     */
    private static function collectTierPeriods( $config ) {
        $count = intval( get_option( 'fusipatb_tier_count', 0 ) );

        if ( $count <= 0 ) {
            // Fallback: count contiguous button_tierN POST keys starting at 1.
            $i = 1;
            while ( isset( $_POST[ 'button_tier' . $i ] ) ) {
                $i++;
            }
            $count = max( 3, $i - 1 );
        }

        $min_low  = $config->getMinMonths();
        $max_low  = $config->getMaxMonths();
        $min_high = $config->getMinMonthsHigh();
        $max_high = $config->getMaxMonthsHigh();

        $periods = array();
        for ( $i = 1; $i <= $count; $i++ ) {
            $raw      = isset( $_POST[ 'button_tier' . $i ] ) ? $_POST[ 'button_tier' . $i ] : $min_low;
            $val      = intval( $raw );
            $is_last  = ( $i === $count );
            $min      = $is_last ? $min_high : $min_low;
            $max      = $is_last ? $max_high : $max_low;
            $periods[] = max( $min, min( $max, $val ) );
        }

        return $periods;
    }

    private static function parseApiError( $response_body ) {
        if ( property_exists( $response_body, 'error' ) ) {
            $raw = $response_body->error;
            if ( is_bool( $raw ) ) return $raw ? 1 : 0;
            if ( is_numeric( $raw ) ) return intval( $raw );
            if ( is_string( $raw ) ) {
                $lower = strtolower( trim( $raw ) );
                if ( $lower === '' || $lower === '0' || $lower === 'false' || $lower === 'ok' || $lower === 'success' ) return 0;
                return 1;
            }
        }
        if ( property_exists( $response_body, 'success' ) ) {
            $raw = $response_body->success;
            if ( is_bool( $raw ) ) return $raw ? 0 : 1;
            if ( is_numeric( $raw ) ) return intval( $raw ) ? 0 : 1;
            if ( is_string( $raw ) ) {
                $lower = strtolower( trim( $raw ) );
                return ( $lower === 'true' || $lower === '1' || $lower === 'ok' || $lower === 'success' ) ? 0 : 1;
            }
        }
        if ( property_exists( $response_body, 'status' ) && is_string( $response_body->status ) ) {
            $lower = strtolower( trim( $response_body->status ) );
            if ( $lower === 'ok' || $lower === 'success' ) return 0;
            if ( $lower === 'error' || $lower === 'failed' || $lower === 'fail' ) return 1;
        }
        // Infer from known settings fields
        $known_fields = array(
            'bnpl_min_amount', 'bnpl_max_amount', 'bnpl_min_amount_eur', 'bnpl_max_amount_eur',
            'tier1_multiplier', 'tier2_multiplier', 'tier3_multiplier',
            'promo', 'euro_multiplier', 'tier_currency',
            'tier1', 'tier2', 'tier3',
            'tiers', 'periods', 'multipliers',
            'bnpl_period', 'bnpl_multiplier',
        );
        foreach ( $known_fields as $field ) {
            if ( isset( $response_body->{$field} ) ) {
                return 0;
            }
        }
        return 1;
    }

    private static function saveAmountLimits( $response_body ) {
        $min = null;
        $max = null;
        $min_eur = null;
        $max_eur = null;

        if ( isset( $response_body->amount_min ) && is_numeric( $response_body->amount_min ) ) $min = floatval( $response_body->amount_min );
        elseif ( isset( $response_body->bnpl_min_amount ) && is_numeric( $response_body->bnpl_min_amount ) ) $min = floatval( $response_body->bnpl_min_amount );
        elseif ( isset( $response_body->bnpl_min ) && is_numeric( $response_body->bnpl_min ) ) $min = floatval( $response_body->bnpl_min );

        if ( isset( $response_body->tier3 ) && is_numeric( $response_body->tier3 ) ) $max = floatval( $response_body->tier3 );
        elseif ( isset( $response_body->bnpl_max_amount ) && is_numeric( $response_body->bnpl_max_amount ) ) $max = floatval( $response_body->bnpl_max_amount );
        elseif ( isset( $response_body->bnpl_max ) && is_numeric( $response_body->bnpl_max ) ) $max = floatval( $response_body->bnpl_max );

        if ( isset( $response_body->amount_min_eur ) && is_numeric( $response_body->amount_min_eur ) ) $min_eur = floatval( $response_body->amount_min_eur );
        elseif ( isset( $response_body->bnpl_min_amount_eur ) && is_numeric( $response_body->bnpl_min_amount_eur ) ) $min_eur = floatval( $response_body->bnpl_min_amount_eur );

        if ( isset( $response_body->tier3_eur ) && is_numeric( $response_body->tier3_eur ) ) $max_eur = floatval( $response_body->tier3_eur );
        elseif ( isset( $response_body->bnpl_max_amount_eur ) && is_numeric( $response_body->bnpl_max_amount_eur ) ) $max_eur = floatval( $response_body->bnpl_max_amount_eur );

        if ( $min !== null ) update_option( 'fusipatb_min_amount', $min );
        if ( $max !== null ) update_option( 'fusipatb_max_amount', $max );
        if ( $min_eur !== null ) update_option( 'fusipatb_min_amount_eur', $min_eur );
        if ( $max_eur !== null ) update_option( 'fusipatb_max_amount_eur', $max_eur );

        // When API provides BGN values but no EUR equivalents, compute them
        $registry      = CountryRegistry::getInstance();
        $dual_currency = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $admin_currency = intval( get_option( 'fusipatb_admin_currency', 2 ) );

        if ( $dual_currency && $admin_currency < 3 ) {
            // Compute tbi EUR limits from BGN when not provided by API
            if ( $min_eur === null && $min !== null && $min > 0 ) {
                update_option( 'fusipatb_min_amount_eur', CurrencyHelper::bgnToEur( $min ) );
            }
            if ( $max_eur === null && $max !== null && $max > 0 ) {
                update_option( 'fusipatb_max_amount_eur', CurrencyHelper::bgnToEur( $max ) );
            }

            // Compute BNPL EUR limits from BGN when not provided by API
            $bnpl_min_eur_from_api = isset( $response_body->bnpl_min_amount_eur ) && is_numeric( $response_body->bnpl_min_amount_eur ) && floatval( $response_body->bnpl_min_amount_eur ) > 0;
            $bnpl_max_eur_from_api = isset( $response_body->bnpl_max_amount_eur ) && is_numeric( $response_body->bnpl_max_amount_eur ) && floatval( $response_body->bnpl_max_amount_eur ) > 0;

            if ( ! $bnpl_min_eur_from_api ) {
                $bnpl_min_bgn = floatval( get_option( 'fusipatb_bnpl_min_amount', 0 ) );
                if ( $bnpl_min_bgn > 0 ) {
                    update_option( 'fusipatb_bnpl_min_amount_eur', CurrencyHelper::bgnToEur( $bnpl_min_bgn ) );
                }
            }
            if ( ! $bnpl_max_eur_from_api ) {
                $bnpl_max_bgn = floatval( get_option( 'fusipatb_bnpl_max_amount', 0 ) );
                if ( $bnpl_max_bgn > 0 ) {
                    update_option( 'fusipatb_bnpl_max_amount_eur', CurrencyHelper::bgnToEur( $bnpl_max_bgn ) );
                }
            }
        }
    }

    private static function saveFormFields( $config ) {
        // Persist button_tierN form values for all N posted tiers.
        $min_low  = $config->getMinMonths();
        $max_low  = $config->getMaxMonths();
        $min_high = $config->getMinMonthsHigh();
        $max_high = $config->getMaxMonthsHigh();

        // Determine N: fusipatb_tier_count if set (API-driven), else contiguous POST keys.
        $posted_count = intval( get_option( 'fusipatb_tier_count', 0 ) );
        if ( $posted_count <= 0 ) {
            $i = 1;
            while ( isset( $_POST[ 'button_tier' . $i ] ) ) {
                $i++;
            }
            $posted_count = $i - 1;
        }

        for ( $i = 1; $i <= $posted_count; $i++ ) {
            if ( ! isset( $_POST[ 'button_tier' . $i ] ) ) {
                continue;
            }
            $is_last = ( $i === $posted_count );
            $min     = $is_last ? $min_high : $min_low;
            $max     = $is_last ? $max_high : $max_low;
            $v       = max( $min, min( $max, intval( $_POST[ 'button_tier' . $i ] ) ) );
            update_option( 'fusipatb_tier' . $i, $v );
        }

        update_option( 'fusipatb_sale_promooff', isset( $_POST['fusipatb_sale_promooff'] ) ? 1 : 0 );
        update_option( 'fusipatb_popup_checkout', isset( $_POST['fusipatb_popup_checkout'] ) ? 1 : 0 );
        update_option( 'fusipatb_advertising', isset( $_POST['fusipatb_advertising'] ) ? 1 : 0 );
        // Guideline 7: explicit, merchant-driven opt-in for the activation/
        // deactivation status ping to tbi bank's external endpoint (see
        // Bootstrap::on_activation()/on_deactivation()). Checkbox unchecked
        // = absent from $_POST = consent withdrawn.
        update_option( 'fusipatb_status_ping_consent', isset( $_POST['fusipatb_status_ping_consent'] ) ? '1' : '' );

        // NOTE: 'tbi_product_button'/'tbi_cart_button' here are the button-style
        // (0-4) radio POST field names from AdminPage::renderButtonSelector() —
        // coincidentally the same literal as the unrelated 'tbi_product_button'
        // OPTION (API-driven, see callSettingsApi() above), not a read/write of
        // it. Left unprefixed intentionally; see Bootstrap::$legacy_option_map
        // doc comment / rename plan for the full "why".
        if ( isset( $_POST['tbi_product_button'] ) ) {
            update_option( 'fusipatb_product_checked', intval( $_POST['tbi_product_button'] ) );
        }
        if ( isset( $_POST['tbi_cart_button'] ) ) {
            update_option( 'fusipatb_cart_checked', intval( $_POST['tbi_cart_button'] ) );
        }
        if ( isset( $_POST['fusipatb_delivery_fee'] ) ) {
            update_option( 'fusipatb_delivery_fee', floatval( $_POST['fusipatb_delivery_fee'] ) );
        }
        if ( isset( $_POST['fusipatb_delivery_fee_prc'] ) ) {
            update_option( 'fusipatb_delivery_fee_prc', floatval( $_POST['fusipatb_delivery_fee_prc'] ) );
        }
        if ( isset( $_POST['fusipatb_delivery_limit'] ) ) {
            update_option( 'fusipatb_delivery_limit', floatval( $_POST['fusipatb_delivery_limit'] ) );
        }
        if ( isset( $_POST['fusipatb_default_status'] ) ) {
            update_option( 'fusipatb_default_status', sanitize_text_field( wp_unslash( $_POST['fusipatb_default_status'] ) ) );
        }
        if ( isset( $_POST['fusipatb_button_placement'] ) ) {
            update_option( 'fusipatb_button_placement', sanitize_text_field( wp_unslash( $_POST['fusipatb_button_placement'] ) ) );
        }

        // RO: allow merchant to override min/max amounts from form.
        // API values are saved first; posted values are clamped to the allowed API range.
        if ( $config->getCountryCode() === 'RO' && ( isset( $_POST['min_amount'] ) || isset( $_POST['max_amount'] ) ) ) {
            $lower_bound = floatval( get_option( 'fusipatb_bnpl_min_amount', 0 ) );
            if ( $lower_bound <= 0 ) {
                $lower_bound = floatval( get_option( 'fusipatb_bnpl_min', 0 ) );
            }
            if ( $lower_bound <= 0 ) {
                $lower_bound = floatval( $config->getMinAmount() );
            }

            $tier_count = intval( get_option( 'fusipatb_tier_count', 0 ) );
            $upper_bound = 0.0;
            if ( $tier_count > 0 ) {
                $upper_bound = floatval( get_option( "fusipatb_tier{$tier_count}_amount", 0 ) );
            }
            if ( $upper_bound <= 0 ) {
                $upper_bound = floatval( get_option( 'fusipatb_max_amount', 0 ) );
            }
            if ( $upper_bound <= 0 ) {
                $upper_bound = floatval( $config->getMaxAmount() );
            }
            if ( $upper_bound < $lower_bound ) {
                $upper_bound = $lower_bound;
            }

            $post_min = isset( $_POST['min_amount'] ) && is_numeric( $_POST['min_amount'] )
                ? floatval( $_POST['min_amount'] )
                : $lower_bound;
            $post_max = isset( $_POST['max_amount'] ) && is_numeric( $_POST['max_amount'] )
                ? floatval( $_POST['max_amount'] )
                : $upper_bound;

            $post_min = max( $lower_bound, min( $upper_bound, $post_min ) );
            $post_max = max( $lower_bound, min( $upper_bound, $post_max ) );

            if ( $post_max < $post_min ) {
                $post_max = $post_min;
            }

            update_option( 'fusipatb_min_amount', $post_min );
            update_option( 'fusipatb_max_amount', $post_max );
        }

        // Promo product/category lists
        $promo_option = get_option( 'fusipatb_active_promo', '[]' );
        if ( $promo_option && $promo_option !== '[]' ) {
            $promo_array = json_decode( $promo_option );
            if ( is_array( $promo_array ) ) {
                foreach ( $promo_array as $promo_scheme ) {
                    $prod_key = 'fusipatb_promo_products_' . $promo_scheme->id;
                    $cat_key  = 'fusipatb_promo_categories_' . $promo_scheme->id;
                    if ( isset( $_POST[ $prod_key ] ) ) {
                        update_option( $prod_key, sanitize_text_field( wp_unslash( $_POST[ $prod_key ] ) ) );
                    }
                    if ( isset( $_POST[ $cat_key ] ) ) {
                        update_option( $cat_key, sanitize_text_field( wp_unslash( $_POST[ $cat_key ] ) ) );
                    }
                }
            }
        }
    }

    private static function pingStatus( $config ) {
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
}