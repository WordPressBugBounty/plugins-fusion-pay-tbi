<?php

namespace FusionPayTbi\Admin;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\CurrencyHelper;
use FusionPayTbi\Api\RenderButton;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminPage {

    public static function addMenuPage() {
        $icon = 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHN2ZyB2aWV3Qm94PSIzLjIxNyAyLjg1OSAzMiAzMiIgd2lkdGg9IjMyIiBoZWlnaHQ9IjMyIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgogIDxkZWZzPgogICAgPHN0eWxlPi5jbHMtMXtmaWxsOiNmNjA7fS5jbHMtMntmaWxsOiMwZDBkMGQ7fTwvc3R5bGU+CiAgPC9kZWZzPgogIDxwYXRoIGNsYXNzPSJjbHMtMiIgZD0iTSAxNC45NyAxOC41MDggTCAxMS4zOTMgMTguNTA4IEwgMTEuMzkzIDIzLjMyNyBDIDExLjM5MyAyNC4zNjUgMTIuMDkyIDI0LjgyMiAxMi45MTcgMjQuODIyIEMgMTMuNTc4IDI0LjgyMiAxNC4zMzIgMjQuNjUzIDE0Ljk2NyAyNC4zNjUgTCAxNC45NjcgMjcuMzU2IEMgMTQuMjgxIDI3LjY0NCAxMy40MDkgMjcuODEyIDEyLjQxOSAyNy44MTIgQyA5LjgwNSAyNy44MTIgOC4xNTkgMjYuMDQ2IDguMTYyIDIzLjUzOSBMIDguMTYyIDE4LjQ5NyBMIDUuMTc1IDE4LjQ5NyBMIDUuMTc1IDE1LjUwNyBMIDguMTY5IDE1LjUwNyBMIDguMTY5IDEyLjM5NiBMIDExLjM5NiAxMi4zOTYgTCAxMS4zOTYgMTUuNTExIEwgMTQuOTc0IDE1LjUxMSBMIDE0Ljk3NCAxOC41MDUgTCAxNC45NyAxOC41MDggWiIgc3R5bGU9ImZpbGw6IHJnYigxNjcsIDE3MCwgMTczKTsiLz4KICA8cGF0aCBjbGFzcz0iY2xzLTIiIGQ9Ik0gMjkuMDYxIDExLjY3MyBDIDI5LjA2MSAxMC40NDQgMjkuOTc5IDkuNTA1IDMxLjE4MSA5LjUwNSBDIDMyLjM4MyA5LjUwNSAzMy4yOTkgMTAuNDQ4IDMzLjI5OSAxMS42NzYgQyAzMy4yOTkgMTIuOTA0IDMyLjM4IDEzLjg0NCAzMS4xNzggMTMuODQ0IEMgMjkuOTc2IDEzLjg0NCAyOS4wNjEgMTIuOTAxIDI5LjA2MSAxMS42NzMgWiBNIDI5LjU1MSAxNS41MzQgTCAzMi44MDMgMTUuNTM0IEwgMzIuNzkzIDI3LjU5MyBMIDI5LjU0MSAyNy41OTMgTCAyOS41NTEgMTUuNTM0IFoiIHN0eWxlPSJmaWxsOiByZ2IoMTY3LCAxNzAsIDE3Myk7Ii8+CiAgPHBhdGggY2xhc3M9ImNscy0yIiBkPSJNIDIyLjg4NCAxNS4yOTIgQyAyMS4zODcgMTUuMjkyIDIwLjE0MiAxNS45MSAxOS4zNTcgMTYuNzI5IEwgMTkuMzU3IDEwLjQ1NiBMIDE2LjEyOSAxMC40NTYgTCAxNi4xMTUgMjcuNTgxIEwgMTkuMTc2IDI3LjU4MSBMIDE5LjE3NiAyNi4xNzEgQyAyMC4wNzggMjcuMTggMjEuNTUyIDI3LjgyNiAyMi45MDkgMjcuODI2IEMgMjYuNDE5IDI3LjgyNiAyOC42MjMgMjUuMDIyIDI4LjYyMyAyMS41NjEgQyAyOC42MjMgMTguMSAyNi40MDUgMTUuMjk2IDIyLjg4NCAxNS4yOTYgTCAyMi44ODQgMTUuMjkyIFogTSAyMi4yNDUgMjQuNzg2IEMgMjAuNDkxIDI0Ljc4NiAxOS4yNTUgMjMuMzg1IDE5LjI1NSAyMS41NDYgQyAxOS4yNTUgMTkuNzA4IDIwLjQ2OCAxOC4zMDggMjIuMjg1IDE4LjMwOCBDIDI0LjEwMSAxOC4zMDggMjUuMjE0IDE5Ljc3NyAyNS4yMTQgMjEuNTQ2IEMgMjUuMjE0IDIzLjMxNSAyMy45NjggMjQuNzg2IDIyLjI0NSAyNC43ODYgWiIgc3R5bGU9ImZpbGw6IHJnYigxNjcsIDE3MCwgMTczKTsiLz4KPC9zdmc+';

        add_menu_page(
            'Fusion Pay tbi Settings',
            'Fusion Pay',
            'manage_options',
            'tbi-admin-settings',
            array( __CLASS__, 'render' ),
            $icon,
            '56'
        );
    }

    public static function addActionLinks( $actions ) {
        $mylinks = array(
            '<a href="' . admin_url( 'admin.php?page=tbi-admin-settings' ) . '">' . __( 'Settings', 'fusion-pay-tbi' ) . '</a>',
        );
        return array_merge( $mylinks, $actions );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html( __( 'You do not have sufficient permissions to access this page.', 'fusion-pay-tbi' ) ) );
        }

        // Save form if submitted
        SettingsSaver::save();

        $registry      = CountryRegistry::getInstance();
        $config        = $registry->getConfig();
        $dual_currency = $registry->hasFeature( FeatureFlags::DUAL_CURRENCY );
        $has_adv       = $registry->hasFeature( FeatureFlags::ADVERTISING );
        $has_analysis  = $registry->hasFeature( FeatureFlags::ANALYSIS_FEES );

        $status = get_option( 'fusipatb_checkout_status' );

        // Currency info for dual-currency display
        if ( $dual_currency ) {
            $currency_info = CurrencyHelper::fetchCurrencyType();
            $currency_type = $currency_info['admin_currency'];
        } else {
            $currency_info = array( 'admin_currency' => 1, 'store_currency' => 1 );
            $currency_type = 1;
        }
        $primary_label   = $dual_currency ? self::getCurrencyLabel( $currency_type, 'primary' ) : $config->getCurrency();
        $secondary_label = $dual_currency ? self::getCurrencyLabel( $currency_type, 'secondary' ) : '';

        // Load saved values
        $tier1             = intval( get_option( 'fusipatb_tier1', $config->getMaxMonths() ) );
        $tier2             = intval( get_option( 'fusipatb_tier2', $config->getMaxMonths() ) );
        $tier3             = intval( get_option( 'fusipatb_tier3', $config->getMaxMonthsHigh() ) );
        $tier1_multiplier  = get_option( 'fusipatb_tier1_multiplier', 0 );
        $tier2_multiplier  = get_option( 'fusipatb_tier2_multiplier', 0 );
        $tier3_multiplier  = get_option( 'fusipatb_tier3_multiplier', 0 );
        $tier1_limit       = get_option( 'fusipatb_tier1_limit', 0 );
        $tier2_limit       = get_option( 'fusipatb_tier2_limit', 0 );
        $tier3_limit       = get_option( 'fusipatb_tier3_limit', 0 );
        $tier1_limit_eur   = get_option( 'fusipatb_tier1_limit_eur', 0 );
        $tier2_limit_eur   = get_option( 'fusipatb_tier2_limit_eur', 0 );
        $tier3_limit_eur   = get_option( 'fusipatb_tier3_limit_eur', 0 );
        $bnpl_min          = get_option( 'fusipatb_bnpl_min_amount', 0 );
        $bnpl_max          = get_option( 'fusipatb_bnpl_max_amount', 0 );
        $bnpl_min_eur      = get_option( 'fusipatb_bnpl_min_amount_eur', 0 );
        $bnpl_max_eur      = get_option( 'fusipatb_bnpl_max_amount_eur', 0 );
        $min_amount        = get_option( 'fusipatb_min_amount', $config->getMinAmount() );
        if ( $config->getCountryCode() === 'RO' ) {
            if ( floatval( $bnpl_min ) <= 0 ) {
                $bnpl_min = get_option( 'fusipatb_bnpl_min', 0 );
            }
            if ( floatval( $bnpl_max ) <= 0 ) {
                $bnpl_max = get_option( 'fusipatb_bnpl_max', 0 );
            }
            if ( floatval( $bnpl_min ) > 0 && floatval( $min_amount ) < floatval( $bnpl_min ) ) {
                $min_amount = $bnpl_min;
            }
        }
        $max_amount        = get_option( 'fusipatb_max_amount', $config->getMaxAmount() );
        $min_amount_eur    = get_option( 'fusipatb_min_amount_eur', 0 );
        $max_amount_eur    = get_option( 'fusipatb_max_amount_eur', 0 );
        $delivery_fee      = get_option( 'fusipatb_delivery_fee', 0 );
        $delivery_limit    = get_option( 'fusipatb_delivery_limit', 0 );
        $btn_placement     = get_option( 'fusipatb_button_placement', 'woocommerce_after_add_to_cart_button' );

        // Dynamic tier data (RO)
        $tier_count = intval( get_option( 'fusipatb_tier_count', 0 ) );
        $tiers_data = array();
        for ( $i = 1; $i <= $tier_count; $i++ ) {
            $tiers_data[ $i ] = array(
                'multiplier' => get_option( "fusipatb_tier{$i}_multiplier", 0 ),
                'tier'       => get_option( "fusipatb_tier{$i}_amount", 0 ),
                'period'     => get_option( "fusipatb_period{$i}", 0 ),
            );
        }

        // Checked states for radio buttons (product / cart)
        $product_checked = array_fill( 0, 6, '' );
        $cart_checked    = array_fill( 0, 6, '' );
        $pc              = intval( get_option( 'fusipatb_product_checked', 2 ) );
        $cc              = intval( get_option( 'fusipatb_cart_checked', 2 ) );
        if ( isset( $product_checked[ $pc ] ) ) $product_checked[ $pc ] = ' checked';
        if ( isset( $cart_checked[ $cc ] ) ) $cart_checked[ $cc ] = ' checked';

        $adv_checked      = get_option( 'fusipatb_advertising' ) == 1 ? ' checked' : '';
        $promooff_checked = get_option( 'fusipatb_sale_promooff' ) == 1 ? ' checked' : '';
        $popup_checked    = get_option( 'fusipatb_popup_checkout' ) == 1 ? ' checked' : '';
        $status_ping_checked = get_option( 'fusipatb_status_ping_consent' ) === '1' ? ' checked' : '';

        $output_status = $status
            ? '<span style="color:green">' . __( 'Active', 'fusion-pay-tbi' ) . '</span>'
            : '<span style="color:red">' . __( 'Inactive', 'fusion-pay-tbi' ) . '</span>';

        $last_error = get_option( 'fusipatb_last_settings_error', '' );

        // Button preview (BG only — render-button API)
        $preview_html  = '';
        $preview_label = '';
        if ( $registry->hasFeature( FeatureFlags::RENDER_BUTTON_API ) ) {
            $currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : ( get_option( 'woocommerce_currency' ) ?: $config->getCurrency() );
            $example_amt = max( floatval( $min_amount ) > 0 ? floatval( $min_amount ) : floatval( $config->getMinAmount() ), 1001 );
            if ( floatval( $max_amount ) > 0 ) $example_amt = min( $example_amt, floatval( $max_amount ) );

            $api_result = RenderButton::fetchText( $example_amt, $currency );
            if ( $api_result['ok'] ) {
                $preview_label = RenderButton::fillPlaceholders( $api_result['data']['button_text'], $api_result['data'], $currency );
                $preview_html  = '<div class="tbi_row"><div>' . __( 'Button preview (live)', 'fusion-pay-tbi' ) . ': '
                    . esc_html( number_format( $example_amt, 2, '.', '' ) ) . ' ' . esc_html( $currency ) . ' → ' . esc_html( $preview_label ) . '</div></div><hr>';
            }
        }

        // Form output
        ?>
        <div class="tbi_logo_container">
            <img src="<?php echo esc_url( FUSIPATB_CDN_URL . FUSIPATB_BANK_LOGO ); ?>" class="tbi_logo" alt="tbi bank">
        </div>

        <form name="tbi_checkout" action="<?php echo esc_url( sanitize_text_field( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' ) ) ); ?>" method="POST" enctype="multipart/form-data" class="tbi_form">

        <h2><?php esc_html_e( 'Settings', 'fusion-pay-tbi' ); ?></h2>

        <div><?php esc_html_e( 'Status: ', 'fusion-pay-tbi' ); echo wp_kses_post( $output_status ); ?></div>

        <?php if ( $dual_currency ) : ?>
        <div class="tbi_row">
            <div><?php esc_html_e( 'Currency: ', 'fusion-pay-tbi' ); echo esc_html( self::getCurrencyTypeDescription( $currency_type ) ); ?></div>
        </div>
        <?php endif; ?>

        <div class="tbi_row">
            <div><?php esc_html_e( 'API base URL: ', 'fusion-pay-tbi' ); echo defined( 'FUSIPATB_PLATFORM_URL' ) ? esc_html( FUSIPATB_PLATFORM_URL ) : '-'; ?></div>
        </div>

        <?php if ( ! empty( $last_error ) ) : ?>
        <div class="tbi_row"><div style="color:#b32d2e"><?php echo esc_html( $last_error ); ?></div></div>
        <?php endif; ?>

        <div class="tbi_row">
            <label for="reseller_code"><?php esc_html_e( 'Reseller Code', 'fusion-pay-tbi' ); ?></label>
            <input type="text" maxlength="50" name="reseller_code" id="reseller_code" class="tbi_field" value="<?php echo esc_attr( get_option( 'fusipatb_reseller_code' ) ); ?>">
        </div>
        <div class="tbi_row">
            <label for="reseller_key"><?php esc_html_e( 'Reseller Key', 'fusion-pay-tbi' ); ?></label>
            <input type="text" maxlength="50" name="reseller_key" id="reseller_key" class="tbi_field" value="<?php echo esc_attr( get_option( 'fusipatb_reseller_key' ) ); ?>">
        </div>
        <div class="tbi_row">
            <label for="encryption_key"><?php esc_html_e( 'Encryption Key', 'fusion-pay-tbi' ); ?></label>
            <input type="text" maxlength="130" name="encryption_key" id="encryption_key" class="tbi_field" value="<?php echo esc_attr( get_option( 'fusipatb_encryption_key' ) ); ?>">
        </div>

        <div class="tbi_row">
            <label for="tbi_status_ping_consent"><?php esc_html_e( 'Share plugin status with tbi bank', 'fusion-pay-tbi' ); ?></label>
            <div>
                <input type="checkbox" name="fusipatb_status_ping_consent" id="tbi_status_ping_consent" class="tbi_field" <?php echo esc_attr( $status_ping_checked ); ?>>
                <p class="description"><?php esc_html_e( 'Allow Fusion Pay to notify tbi bank when this plugin is activated or deactivated. This helps us support your integration and monitor its status.', 'fusion-pay-tbi' ); ?></p>
            </div>
        </div>

        <?php
        // Tier limits / periods (read-only, from API or legacy fallback)
        $display_tiers = array();

        if ( $tier_count > 0 ) {
            for ( $i = 1; $i <= $tier_count; $i++ ) {
                if ( ! isset( $tiers_data[ $i ] ) ) {
                    continue;
                }

                $limit_value = isset( $tiers_data[ $i ]['tier'] ) ? $tiers_data[ $i ]['tier'] : 0;
                $display_tiers[ $i ] = array(
                    // translators: %d is the tier number.
                    'label'  => sprintf( __( 'Tier %d', 'fusion-pay-tbi' ), $i ),
                    'val'    => $limit_value,
                    'eur'    => self::getSecondaryTierValue( $limit_value, $primary_label, $secondary_label ),
                    'period' => isset( $tiers_data[ $i ]['period'] ) ? $tiers_data[ $i ]['period'] : 0,
                    'mult'   => isset( $tiers_data[ $i ]['multiplier'] ) ? $tiers_data[ $i ]['multiplier'] : 0,
                );
            }
        } else {
            $display_tiers = array(
                1 => array( 'label' => __( 'Tier 1', 'fusion-pay-tbi' ), 'val' => $tier1_limit, 'eur' => $tier1_limit_eur, 'period' => $tier1, 'mult' => $tier1_multiplier ),
                2 => array( 'label' => __( 'Tier 2', 'fusion-pay-tbi' ), 'val' => $tier2_limit, 'eur' => $tier2_limit_eur, 'period' => $tier2, 'mult' => $tier2_multiplier ),
                3 => array( 'label' => __( 'Tier 3', 'fusion-pay-tbi' ), 'val' => $tier3_limit, 'eur' => $tier3_limit_eur, 'period' => $tier3, 'mult' => $tier3_multiplier ),
            );
        }

        foreach ( $display_tiers as $ti => $tv ) :
            $tl = $tv['label'];
        ?>
        <div class="tbi_row">
            <?php // translators: %s is the tier label (e.g. "Standard", "Premium"). ?>
            <label><?php echo esc_html( sprintf( __( '%s limit', 'fusion-pay-tbi' ), $tl ) . ( $secondary_label ? ' (' . $primary_label . '/' . $secondary_label . ')' : '' ) ); ?></label>
            <?php if ( $secondary_label ) : ?>
            <div>
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $tv['val'] ); ?>" disabled>
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $tv['eur'] ); ?>" disabled placeholder="<?php echo esc_attr( $secondary_label ); ?>" style="margin-left:5px">
            </div>
            <?php else : ?>
            <input type="text" class="tbi_field" value="<?php echo esc_attr( $tv['val'] ); ?>" disabled>
            <?php endif; ?>
        </div>
        <div class="tbi_row">
            <?php // translators: %s is the tier label. ?>
            <label><?php echo esc_html( sprintf( __( 'Button period (%s)', 'fusion-pay-tbi' ), $tl ) ); ?></label>
            <div>
                <input type="text" maxlength="2" name="button_tier<?php echo esc_attr( $ti ); ?>" class="tbi_field_half" value="<?php echo esc_attr( $tv['period'] ); ?>" readonly>
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $tv['mult'] ); ?>" disabled>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="tbi_row">
            <label><?php esc_html_e( 'BNPL min/max amount', 'fusion-pay-tbi' ); ?><?php echo $secondary_label ? esc_html( ' (' . $primary_label . '/' . $secondary_label . ')' ) : ''; ?></label>
            <?php if ( $secondary_label ) : ?>
            <div>
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $bnpl_min ); ?>" disabled style="width:70px">
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $bnpl_max ); ?>" disabled style="width:70px">
                /
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $bnpl_min_eur ); ?>" disabled placeholder="<?php echo esc_attr( $secondary_label ); ?>" style="width:70px;margin-top:5px">
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $bnpl_max_eur ); ?>" disabled placeholder="<?php echo esc_attr( $secondary_label ); ?>" style="width:70px;margin-top:5px">
            </div>
            <?php else : ?>
            <div>
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $bnpl_min ); ?>" disabled>
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $bnpl_max ); ?>" disabled>
            </div>
            <?php endif; ?>
        </div>

        <div class="tbi_row">
            <label for="min_amount"><?php esc_html_e( 'Minimum amount', 'fusion-pay-tbi' ); ?><?php echo $secondary_label ? esc_html( ' (' . $primary_label . '/' . $secondary_label . ')' ) : ''; ?></label>
            <?php if ( $secondary_label ) : ?>
            <div>
                <input type="text" name="min_amount" id="min_amount" class="tbi_field_half" value="<?php echo esc_attr( $min_amount ); ?>" disabled>
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $min_amount_eur ); ?>" disabled placeholder="<?php echo esc_attr( $secondary_label ); ?>" style="margin-left:5px">
            </div>
            <?php else : ?>
            <input type="text" name="min_amount" id="min_amount" class="tbi_field" value="<?php echo esc_attr( $min_amount ); ?>">
            <?php endif; ?>
        </div>
        <div class="tbi_row">
            <label for="max_amount"><?php esc_html_e( 'Maximum amount', 'fusion-pay-tbi' ); ?><?php echo $secondary_label ? esc_html( ' (' . $primary_label . '/' . $secondary_label . ')' ) : ''; ?></label>
            <?php if ( $secondary_label ) : ?>
            <div>
                <input type="text" name="max_amount" id="max_amount" class="tbi_field_half" value="<?php echo esc_attr( $max_amount ); ?>" disabled>
                <input type="text" class="tbi_field_half" value="<?php echo esc_attr( $max_amount_eur ); ?>" disabled placeholder="<?php echo esc_attr( $secondary_label ); ?>" style="margin-left:5px">
            </div>
            <?php else : ?>
            <input type="text" name="max_amount" id="max_amount" class="tbi_field" value="<?php echo esc_attr( $max_amount ); ?>">
            <?php endif; ?>
        </div>

        <div class="tbi_row">
            <label for="tbi_popup_checkout"><?php esc_html_e( 'Disable/Enable pop up on checkout', 'fusion-pay-tbi' ); ?></label>
            <input type="checkbox" name="fusipatb_popup_checkout" id="tbi_popup_checkout" class="tbi_field" <?php echo esc_attr( $popup_checked ); ?>>
        </div>
        <div class="tbi_row">
            <div class="tbi_important"><?php esc_html_e( "If experience or functionality is broken on checkout you can mark the 'Disable/Enable pop up on checkout' checkbox.", 'fusion-pay-tbi' ); ?></div>
        </div>

        <div class="tbi_row">
            <label for="tbi_sale_promooff"><?php esc_html_e( 'Disable/Enable Promo schemes for products on sale', 'fusion-pay-tbi' ); ?></label>
            <input type="checkbox" name="fusipatb_sale_promooff" id="tbi_sale_promooff" class="tbi_field" <?php echo esc_attr( $promooff_checked ); ?>>
        </div>

        <div class="tbi_row">
            <label><?php esc_html_e( 'Add delivery', 'fusion-pay-tbi' ); ?></label>
            <div>
                <input type="text" name="fusipatb_delivery_fee" id="tbi_delivery_fee" class="tbi_field_half" value="<?php echo esc_attr( $delivery_fee ); ?>">
            </div>
        </div>
        <div class="tbi_row">
            <label><?php esc_html_e( 'For amounts below', 'fusion-pay-tbi' ); ?><?php echo $secondary_label ? esc_html( ' (' . $primary_label . '/' . $secondary_label . ')' ) : ''; ?></label>
            <div>
                <input type="text" name="fusipatb_delivery_limit" id="tbi_delivery_limit" class="tbi_field_half" value="<?php echo esc_attr( $delivery_limit ); ?>">
            </div>
        </div>

        <div class="tbi_row">
            <label><?php esc_html_e( 'Order default status', 'fusion-pay-tbi' ); ?></label>
            <select name="fusipatb_default_status">
                <?php
                $order_statuses = wc_get_order_statuses();
                foreach ( $order_statuses as $status_key => $status_name ) {
                    $selected = get_option( 'fusipatb_default_status' ) == esc_attr( $status_key ) ? ' selected' : '';
                    echo '<option value="' . esc_attr( $status_key ) . '"' . esc_attr( $selected ) . '>' . esc_html( $status_name ) . '</option>';
                }
                ?>
            </select>
        </div>

        <?php if ( $has_adv ) : ?>
        <div class="tbi_row">
            <label for="tbi_advertising"><?php esc_html_e( 'Enable/Disable Advertise box', 'fusion-pay-tbi' ); ?></label>
            <input type="checkbox" name="fusipatb_advertising" id="tbi_advertising" class="tbi_field" <?php echo esc_attr( $adv_checked ); ?>>
        </div>
        <?php endif; ?>

        <div class="tbi_row">
            <label for="tbi_button_placement"><?php esc_html_e( 'Button placement', 'fusion-pay-tbi' ); ?></label>
            <select name="fusipatb_button_placement" id="tbi_button_placement" class="tbi_field">
                <?php
                $placements = array(
                    'woocommerce_after_add_to_cart_button',
                    'woocommerce_single_product_summary',
                    'woocommerce_before_add_to_cart_form',
                    'woocommerce_before_variations_form',
                    'woocommerce_before_add_to_cart_button',
                    'woocommerce_before_single_variation',
                    'woocommerce_single_variation',
                    'woocommerce_after_single_variation',
                    'woocommerce_after_variations_form',
                    'woocommerce_after_add_to_cart_form',
                    'woocommerce_product_meta_start',
                    'woocommerce_product_meta_end',
                    'woocommerce_share',
                );
                foreach ( $placements as $p ) {
                    $selected = $btn_placement === $p ? ' selected' : '';
                    echo '<option value="' . esc_attr( $p ) . '"' . esc_attr( $selected ) . '>' . esc_html( $p ) . '</option>';
                }
                ?>
            </select>
        </div>

        <?php
        // Promo schemes
        $promo_opt = get_option( 'fusipatb_active_promo', '[]' );
        if ( $promo_opt && $promo_opt !== '[]' ) {
            $promo_array = json_decode( $promo_opt );
            if ( is_array( $promo_array ) ) {
                foreach ( $promo_array as $promo_scheme ) {
                    echo '<hr>';
                    echo '<div class="tbi_row">' . esc_html__( 'Promo scheme', 'fusion-pay-tbi' ) . ': ' . esc_html( $promo_scheme->id ) . '</div>';
                    if ( isset( $promo_scheme->category_id ) && $promo_scheme->category_id != 0 ) {
                        echo '<div class="tbi_row"><label>' . esc_html__( 'List of products', 'fusion-pay-tbi' ) . '</label><input type="text" name="fusipatb_promo_products_' . esc_attr( $promo_scheme->id ) . '" class="tbi_field" value="' . esc_attr( get_option( 'fusipatb_promo_products_' . $promo_scheme->id ) ) . '"></div>';
                        echo '<div class="tbi_row"><label>' . esc_html__( 'List of categories', 'fusion-pay-tbi' ) . '</label><input type="text" name="fusipatb_promo_categories_' . esc_attr( $promo_scheme->id ) . '" class="tbi_field" value="' . esc_attr( get_option( 'fusipatb_promo_categories_' . $promo_scheme->id ) ) . '"></div>';
                    } else {
                        echo '<div class="tbi_row">' . esc_html__( 'This promo scheme is active for all categories and products', 'fusion-pay-tbi' ) . '</div>';
                    }
                }
            }
        }
        ?>

        <?php echo wp_kses_post( $preview_html ); ?>

        <hr>

        <?php echo self::renderButtonSelector( 'tbi_product_button', __( 'Choose button in product page', 'fusion-pay-tbi' ), $product_checked, $config, $preview_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is generated by a private controlled method, all user-facing values are escaped within it. ?>

        <div class="tbi_row"></div>

        <?php echo self::renderButtonSelector( 'tbi_cart_button', __( 'Choose button in cart page', 'fusion-pay-tbi' ), $cart_checked, $config, $preview_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is generated by a private controlled method, all user-facing values are escaped within it. ?>

        <div class="tbi_row"></div>

        <?php wp_nonce_field( 'tbi_checkout_action', 'tbi_checkout_nonce' ); ?>

        <div class="tbi_row">
            <input type="submit" class="tbi_confirm" value="<?php esc_attr_e( 'Save settings', 'fusion-pay-tbi' ); ?>">
        </div>

        </form>
        <?php
    }

    private static function renderButtonSelector( $name, $title, $checked, $config, $api_preview_label = '' ) {
        $logo      = FUSIPATB_CDN_URL . FUSIPATB_BANK_LOGO;
        $logo_w    = FUSIPATB_CDN_URL . FUSIPATB_BANK_LOGO_WHITE;
        $logo_l    = FUSIPATB_CDN_URL . FUSIPATB_BANK_LOGO_LIGHT;
        $default_t = $config->getDefaultGatewayTitle();

        $label_s    = ! empty( $api_preview_label ) ? $api_preview_label : '';
        $show_label = $label_s !== '';

        ob_start();
        ?>
        <div class="tbi_row"><?php echo esc_html( $title ); ?></div>

        <label class="tbi_row_no_space">
            <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="0" <?php echo esc_attr( $checked[0] ?? '' ); ?>>/no-button/
        </label>

        <label class="tbi_row_no_space">
            <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="1" <?php echo esc_attr( $checked[1] ?? '' ); ?>>
            <div id="tbi_button_container1">
                <div id="tbi_button1">
                    <div id="tbi_button_body1">
                        <div class="tbi_button_line"><?php esc_html_e( 'Buy with', 'fusion-pay-tbi' ); ?><img src="<?php echo esc_url( $logo_w ); ?>" id="tbi_button_logo1" alt="tbi" width="89" height="40"></div>
                        <?php if ( $show_label ) : ?><div class="tbi_button_line"><?php echo esc_html( $label_s ); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </label>

        <label class="tbi_row_no_space">
            <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="2" <?php echo esc_attr( $checked[2] ?? '' ); ?>>
            <div id="tbi_button_container2">
                <div id="tbi_button2">
                    <div id="tbi_button_body2">
                        <div class="tbi_button_line"><?php esc_html_e( 'Buy with', 'fusion-pay-tbi' ); ?><img src="<?php echo esc_url( $logo ); ?>" id="tbi_button_logo2" alt="tbi" width="89" height="40"></div>
                        <?php if ( $show_label ) : ?><div class="tbi_button_line"><?php echo esc_html( $label_s ); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </label>

        <label class="tbi_row_no_space">
            <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="3" <?php echo esc_attr( $checked[3] ?? '' ); ?>>
            <div id="tbi_button_container3">
                <div id="tbi_button3">
                    <div id="tbi_button_body3">
                        <div class="tbi_button_line"><?php esc_html_e( 'Buy with', 'fusion-pay-tbi' ); ?><img src="<?php echo esc_url( $logo ); ?>" id="tbi_button_logo3" alt="tbi" width="89" height="40"></div>
                        <?php if ( $show_label ) : ?><div class="tbi_button_line"><?php echo esc_html( $label_s ); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </label>

        <label class="tbi_row_no_space">
            <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="4" <?php echo esc_attr( $checked[4] ?? '' ); ?>>
            <div id="tbi_button_container4">
                <div id="tbi_button4">
                    <div id="tbi_button_body4">
                        <div class="tbi_button_line"><?php esc_html_e( 'Buy with', 'fusion-pay-tbi' ); ?><img src="<?php echo esc_url( $logo_w ); ?>" id="tbi_button_logo4" alt="tbi" width="89" height="40"></div>
                        <?php if ( $show_label ) : ?><div class="tbi_button_line"><?php echo esc_html( $label_s ); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </label>

        <?php
        return ob_get_clean();
    }

    private static function getCurrencyLabel( $currency_type, $which ) {
        $map = array(
            1 => array( 'primary' => 'лв.', 'secondary' => '' ),
            2 => array( 'primary' => 'лв.', 'secondary' => '€' ),
            3 => array( 'primary' => '€', 'secondary' => '' ),
            4 => array( 'primary' => '€', 'secondary' => 'лв.' ),
        );
        return isset( $map[ $currency_type ][ $which ] ) ? $map[ $currency_type ][ $which ] : '';
    }

    private static function getCurrencyTypeDescription( $currency_type ) {
        $map = array( 1 => 'BGN', 2 => 'BGN/EUR', 3 => 'EUR', 4 => 'EUR/BGN' );
        return isset( $map[ $currency_type ] ) ? $map[ $currency_type ] : 'BGN';
    }

    private static function getSecondaryTierValue( $value, $primary_label, $secondary_label ) {
        if ( '' === $secondary_label || ! is_numeric( $value ) ) {
            return '';
        }

        $numeric_value = (float) $value;
        if ( 'лв.' === $primary_label && '€' === $secondary_label ) {
            return number_format( CurrencyHelper::bgnToEur( $numeric_value ), 2, '.', '' );
        }

        if ( '€' === $primary_label && 'лв.' === $secondary_label ) {
            return number_format( CurrencyHelper::eurToBgn( $numeric_value ), 2, '.', '' );
        }

        return number_format( $numeric_value, 2, '.', '' );
    }
}



