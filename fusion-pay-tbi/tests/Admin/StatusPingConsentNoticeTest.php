<?php

namespace FusionPayTbi\Tests\Admin;

use FusionPayTbi\Admin\StatusPingConsentNotice;
use FusionPayTbi\Tests\TestCase;
use WpAjaxResponseException;

/**
 * Characterizes StatusPingConsentNotice (WP.org review hardening follow-up to
 * fdd09a3): a dismissible wp-admin notice nudging the merchant toward the
 * fusipatb_status_ping_consent checkbox (src/Admin/AdminPage.php), WITHOUT a
 * forced activation redirect.
 *
 * Behaviour under test (src/Admin/StatusPingConsentNotice.php):
 *  - renderNotice() outputs nothing unless: consent not yet given, not
 *    previously dismissed, current user can 'manage_options', and the current
 *    screen is plugins.php or this plugin's own settings screen
 *    (admin.php?page=tbi-admin-settings).
 *  - handleDismiss() is the wp_ajax_fusipatb_dismiss_status_notice callback:
 *    nonce ("fusipatb_dismiss_status_notice") + capability gate, matching the
 *    nonce-first idiom in SessionDataHandler::handle(), then persists
 *    fusipatb_status_ping_notice_dismissed = '1' as a site option.
 *  - maybeRearm() (admin_init) re-arms a previously dismissed notice when the
 *    stored seen-version differs from FUSIPATB_PLUGIN_VERSION and consent is
 *    still not given; otherwise just advances the stored seen-version.
 */
final class StatusPingConsentNoticeTest extends TestCase {

    private function assertNoOutput( callable $fn ): void {
        ob_start();
        $fn();
        $output = ob_get_clean();
        $this->assertSame( '', $output );
    }

    // -----------------------------------------------------------------
    // renderNotice()
    // -----------------------------------------------------------------

    public function test_render_notice_does_not_output_when_consent_given(): void {
        $GLOBALS['pagenow'] = 'plugins.php';
        update_option( 'fusipatb_status_ping_consent', '1' );

        $this->assertNoOutput( static function () {
            StatusPingConsentNotice::renderNotice();
        } );
    }

    public function test_render_notice_does_not_output_when_previously_dismissed(): void {
        $GLOBALS['pagenow'] = 'plugins.php';
        update_option( 'fusipatb_status_ping_notice_dismissed', '1' );

        $this->assertNoOutput( static function () {
            StatusPingConsentNotice::renderNotice();
        } );
    }

    public function test_render_notice_does_not_output_on_ineligible_screen(): void {
        $GLOBALS['pagenow'] = 'edit.php';
        $_GET['page']       = 'some-other-plugin';

        $this->assertNoOutput( static function () {
            StatusPingConsentNotice::renderNotice();
        } );
    }

    public function test_render_notice_does_not_output_when_user_lacks_capability(): void {
        $GLOBALS['pagenow'] = 'plugins.php';
        $GLOBALS['__fusipatb_test_current_user_can_result'] = false;

        $this->assertNoOutput( static function () {
            StatusPingConsentNotice::renderNotice();
        } );
    }

    public function test_render_notice_outputs_on_plugins_screen_when_eligible(): void {
        $GLOBALS['pagenow'] = 'plugins.php';

        ob_start();
        StatusPingConsentNotice::renderNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-info', $output );
        $this->assertStringContainsString( 'is-dismissible', $output );
        $this->assertStringContainsString( 'page=tbi-admin-settings', $output );
    }

    public function test_render_notice_outputs_on_own_settings_screen_when_eligible(): void {
        $GLOBALS['pagenow'] = 'admin.php';
        $_GET['page']       = 'tbi-admin-settings';

        ob_start();
        StatusPingConsentNotice::renderNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-info', $output );
    }

    public function test_render_notice_includes_title_and_body_copy_when_eligible(): void {
        // Regression/characterization test: renderNotice() now outputs a
        // bolded title line above the existing body paragraph, rather than a
        // single unlabeled paragraph.
        $GLOBALS['pagenow'] = 'plugins.php';

        ob_start();
        StatusPingConsentNotice::renderNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            '<strong>Help us support your Fusion Pay integration</strong>',
            $output
        );
        $this->assertStringContainsString(
            'Enable plugin status sharing to let tbi bank know when Fusion Pay is activated or deactivated on your site.',
            $output
        );
    }

    // -----------------------------------------------------------------
    // handleDismiss() — wp_ajax_fusipatb_dismiss_status_notice
    // -----------------------------------------------------------------

    public function test_handle_dismiss_rejects_missing_nonce(): void {
        try {
            StatusPingConsentNotice::handleDismiss();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( 403, $e->status );
        }

        $this->assertNotSame( '1', get_option( 'fusipatb_status_ping_notice_dismissed' ) );
    }

    public function test_handle_dismiss_rejects_invalid_nonce(): void {
        $_POST['_wpnonce'] = 'not-the-right-nonce';

        try {
            StatusPingConsentNotice::handleDismiss();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( 403, $e->status );
        }

        $this->assertNotSame( '1', get_option( 'fusipatb_status_ping_notice_dismissed' ) );
    }

    public function test_handle_dismiss_rejects_when_user_lacks_capability(): void {
        $_POST['_wpnonce'] = 'valid-nonce-for-fusipatb_dismiss_status_notice';
        $GLOBALS['__fusipatb_test_current_user_can_result'] = false;

        try {
            StatusPingConsentNotice::handleDismiss();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( 403, $e->status );
        }

        $this->assertNotSame( '1', get_option( 'fusipatb_status_ping_notice_dismissed' ) );
    }

    public function test_handle_dismiss_persists_dismissal_on_valid_request(): void {
        $_POST['_wpnonce'] = 'valid-nonce-for-fusipatb_dismiss_status_notice';

        try {
            StatusPingConsentNotice::handleDismiss();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'success', $e->type );
        }

        $this->assertSame( '1', get_option( 'fusipatb_status_ping_notice_dismissed' ) );
    }

    // -----------------------------------------------------------------
    // maybeRearm() — admin_init
    // -----------------------------------------------------------------

    public function test_maybe_rearm_clears_dismissal_when_version_changed_and_consent_not_given(): void {
        update_option( 'fusipatb_status_ping_notice_dismissed', '1' );
        update_option( 'fusipatb_status_notice_seen_version', '0.0.0-old' );

        StatusPingConsentNotice::maybeRearm();

        $this->assertNotSame( '1', get_option( 'fusipatb_status_ping_notice_dismissed' ) );
        $this->assertSame( FUSIPATB_PLUGIN_VERSION, get_option( 'fusipatb_status_notice_seen_version' ) );
    }

    public function test_maybe_rearm_leaves_dismissal_when_consent_given_regardless_of_version(): void {
        update_option( 'fusipatb_status_ping_notice_dismissed', '1' );
        update_option( 'fusipatb_status_notice_seen_version', '0.0.0-old' );
        update_option( 'fusipatb_status_ping_consent', '1' );

        StatusPingConsentNotice::maybeRearm();

        $this->assertSame( '1', get_option( 'fusipatb_status_ping_notice_dismissed' ) );
        $this->assertSame( FUSIPATB_PLUGIN_VERSION, get_option( 'fusipatb_status_notice_seen_version' ) );
    }

    public function test_maybe_rearm_is_noop_when_seen_version_already_current(): void {
        update_option( 'fusipatb_status_ping_notice_dismissed', '1' );
        update_option( 'fusipatb_status_notice_seen_version', FUSIPATB_PLUGIN_VERSION );

        StatusPingConsentNotice::maybeRearm();

        $this->assertSame(
            '1',
            get_option( 'fusipatb_status_ping_notice_dismissed' ),
            'An intentionally-dismissed notice must not reappear on every admin_init once the seen-version already matches.'
        );
    }
}
