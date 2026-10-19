<?php

namespace FusionPayTbi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dismissible wp-admin notice nudging the merchant toward the status-ping
 * consent checkbox (fusipatb_status_ping_consent, see AdminPage.php /
 * SettingsSaver.php and Bootstrap::on_activation()/on_deactivation()).
 *
 * Guideline 7 follow-up (fdd09a3 added the consent gate itself; this makes it
 * discoverable). Deliberately NOT a forced activation redirect -- WP.org
 * dislikes those, they hijack bulk-activation. Instead: a standard dismissible
 * admin_notices notice on the Plugins list and this plugin's own settings
 * screen, dismissed via the standard AJAX pattern, re-armed on a version bump
 * while consent is still outstanding.
 */
class StatusPingConsentNotice {

    const CONSENT_OPTION      = 'fusipatb_status_ping_consent';
    const DISMISSED_OPTION    = 'fusipatb_status_ping_notice_dismissed';
    const SEEN_VERSION_OPTION = 'fusipatb_status_notice_seen_version';
    const DISMISS_ACTION      = 'fusipatb_dismiss_status_notice';
    const CAPABILITY          = 'manage_options';

    /**
     * Registers this class's WordPress hooks. Called from Bootstrap.
     */
    public static function register() {
        add_action( 'admin_init', array( __CLASS__, 'maybeRearm' ) );
        add_action( 'admin_notices', array( __CLASS__, 'renderNotice' ) );
        add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( __CLASS__, 'handleDismiss' ) );
    }

    /**
     * admin_notices callback. Outputs nothing unless every eligibility check
     * passes -- see shouldShow().
     */
    public static function renderNotice() {
        if ( ! self::shouldShow() ) {
            return;
        }

        $settings_url = admin_url( 'admin.php?page=tbi-admin-settings' );
        $ajax_url     = admin_url( 'admin-ajax.php' );
        $nonce        = wp_create_nonce( self::DISMISS_ACTION );
        ?>
        <div class="notice notice-info is-dismissible fusipatb-status-ping-notice" data-fusipatb-nonce="<?php echo esc_attr( $nonce ); ?>">
            <p><strong><?php echo esc_html__( 'Help us support your Fusion Pay integration', 'fusion-pay-tbi' ); ?></strong></p>
            <p>
                <?php echo esc_html__( 'Enable plugin status sharing to let tbi bank know when Fusion Pay is activated or deactivated on your site.', 'fusion-pay-tbi' ); ?>
                <a href="<?php echo esc_url( $settings_url ); ?>"><?php echo esc_html__( 'Enable in settings', 'fusion-pay-tbi' ); ?></a>
            </p>
        </div>
        <script>
        ( function () {
            document.addEventListener( 'click', function ( event ) {
                var notice = event.target.closest && event.target.closest( '.fusipatb-status-ping-notice' );
                if ( ! notice || ! event.target.classList || ! event.target.classList.contains( 'notice-dismiss' ) ) {
                    return;
                }
                var nonce = notice.getAttribute( 'data-fusipatb-nonce' );
                var xhr = new XMLHttpRequest();
                xhr.open( 'POST', '<?php echo esc_url( $ajax_url ); ?>', true );
                xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8' );
                xhr.send( 'action=<?php echo self::DISMISS_ACTION; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed internal constant, not user input. ?>&_wpnonce=' + encodeURIComponent( nonce ) );
            } );
        } )();
        </script>
        <?php
    }

    /**
     * wp_ajax_fusipatb_dismiss_status_notice callback. Mirrors the nonce-first
     * idiom in SessionDataHandler::handle(): verify the raw $_POST['_wpnonce']
     * via wp_verify_nonce() before touching anything else, then gate on the
     * same capability used to reach the settings page in the first place.
     */
    public static function handleDismiss() {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, self::DISMISS_ACTION ) ) {
            wp_send_json_error( array( 'message' => 'Security verification failed' ), 403 );
            return;
        }

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Security verification failed' ), 403 );
            return;
        }

        update_option( self::DISMISSED_OPTION, '1' );
        wp_send_json_success( array( 'message' => 'Notice dismissed' ) );
    }

    /**
     * admin_init callback. Cheap per-load check: if the stored "last seen"
     * plugin version differs from the running version, re-arm a previously
     * dismissed notice -- but only while consent is still outstanding, since
     * there is no point re-nagging a merchant who already opted in.
     */
    public static function maybeRearm() {
        $seen_version = get_option( self::SEEN_VERSION_OPTION, '' );

        if ( $seen_version === FUSIPATB_PLUGIN_VERSION ) {
            return;
        }

        if ( get_option( self::CONSENT_OPTION ) !== '1' ) {
            update_option( self::DISMISSED_OPTION, '' );
        }

        update_option( self::SEEN_VERSION_OPTION, FUSIPATB_PLUGIN_VERSION );
    }

    /**
     * @return bool
     */
    private static function shouldShow() {
        if ( get_option( self::CONSENT_OPTION ) === '1' ) {
            return false;
        }

        if ( get_option( self::DISMISSED_OPTION ) === '1' ) {
            return false;
        }

        if ( ! current_user_can( self::CAPABILITY ) ) {
            return false;
        }

        return self::isEligibleScreen();
    }

    /**
     * Plugins list screen or this plugin's own settings screen only -- no
     * forced redirect, just a notice that shows up where a merchant managing
     * this plugin will actually see it.
     *
     * @return bool
     */
    private static function isEligibleScreen() {
        global $pagenow;

        if ( 'plugins.php' === $pagenow ) {
            return true;
        }

        if ( 'admin.php' === $pagenow
            && isset( $_GET['page'] )
            && 'tbi-admin-settings' === sanitize_text_field( wp_unslash( $_GET['page'] ) )
        ) {
            return true;
        }

        return false;
    }
}
