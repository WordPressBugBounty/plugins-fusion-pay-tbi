<?php

namespace FusionPayTbi\Tests;

use FusionPayTbi\Bootstrap;

/**
 * Characterizes Bootstrap::on_activation() / Bootstrap::on_deactivation()
 * (src/Bootstrap.php, around lines 541-578+) — the WP.org plugin-review fix
 * for the status-ping guard.
 *
 * Bug 1 (fixed previously): `get_option( 'fusipatb_reseller_code' ) === ''`
 * never matches on a fresh install because get_option() returns `false`
 * (its default), not `''`, when the option was never set. So the guard
 * never triggered and on_activation()/on_deactivation() silently POSTed
 * plugin/version/status data (with empty reseller_code/reseller_key) to
 * tbi bank's external status endpoint before the merchant configured
 * anything or consented. Fix: guard with empty() so both "never set"
 * (false) and "set to empty string" correctly skip the network call.
 *
 * Bug 2 (Guideline 7 — no explicit consent): even with the empty() guard,
 * once a merchant has ever configured credentials, activation/deactivation
 * would silently POST to the external status endpoint on every future
 * activate/deactivate with zero explicit opt-in. Fix: gate the network
 * call behind an additional, explicit `fusipatb_status_ping_consent === '1'`
 * option that the merchant must opt into via a settings-page checkbox
 * (see AdminPage.php / SettingsSaver.php). Both the credentials guard AND
 * the consent guard must pass before any wp_remote_post() call fires.
 *
 * Both methods are public static, so no reflection is needed to invoke
 * them. FUSIPATB_API_WOO_SETTINGS_STATUS / FUSIPATB_PLUGIN_VERSION are
 * already defined as fixed test values in tests/bootstrap.php, following
 * the same convention as the other *_Test files.
 */
final class BootstrapActivationStatusPingTest extends TestCase {

    public function test_on_activation_does_not_ping_status_when_credentials_are_unset(): void {
        // Fresh activation: neither option has ever been set, so get_option()
        // returns false (its default) for both — the bug case.

        Bootstrap::on_activation();

        $this->assertSame(
            array(),
            $GLOBALS['__fusipatb_test_wp_remote_post_calls'],
            'A fresh activation with no reseller credentials configured must not ping the tbi status endpoint.'
        );
    }

    public function test_on_activation_does_not_ping_status_when_credentials_are_empty_strings(): void {
        update_option( 'fusipatb_reseller_code', '' );
        update_option( 'fusipatb_reseller_key', '' );

        Bootstrap::on_activation();

        $this->assertSame( array(), $GLOBALS['__fusipatb_test_wp_remote_post_calls'] );
    }

    public function test_on_activation_does_not_ping_status_when_credentials_are_configured_but_consent_is_not_given(): void {
        // Guideline 7: configured credentials alone are not consent. Without
        // the merchant explicitly opting in via fusipatb_status_ping_consent,
        // activation must not contact the external status endpoint.
        update_option( 'fusipatb_reseller_code', 'reseller-code' );
        update_option( 'fusipatb_reseller_key', 'reseller-key' );

        Bootstrap::on_activation();

        $this->assertSame(
            array(),
            $GLOBALS['__fusipatb_test_wp_remote_post_calls'],
            'Configured credentials without explicit consent must not ping the tbi status endpoint on activation.'
        );
    }

    public function test_on_activation_pings_status_when_credentials_are_configured_and_consent_is_given(): void {
        update_option( 'fusipatb_reseller_code', 'reseller-code' );
        update_option( 'fusipatb_reseller_key', 'reseller-key' );
        update_option( 'fusipatb_status_ping_consent', '1' );

        Bootstrap::on_activation();

        $this->assertCount(
            1,
            $GLOBALS['__fusipatb_test_wp_remote_post_calls'],
            'A merchant with configured credentials who explicitly opted in must get the status ping on activation.'
        );
        $this->assertSame( FUSIPATB_API_WOO_SETTINGS_STATUS, $GLOBALS['__fusipatb_test_wp_remote_post_calls'][0]['url'] );
    }

    public function test_on_deactivation_does_not_ping_status_when_credentials_are_unset(): void {
        Bootstrap::on_deactivation();

        $this->assertSame(
            array(),
            $GLOBALS['__fusipatb_test_wp_remote_post_calls'],
            'Deactivating a plugin that was never configured must not ping the tbi status endpoint.'
        );
    }

    public function test_on_deactivation_does_not_ping_status_when_credentials_are_empty_strings(): void {
        update_option( 'fusipatb_reseller_code', '' );
        update_option( 'fusipatb_reseller_key', '' );

        Bootstrap::on_deactivation();

        $this->assertSame( array(), $GLOBALS['__fusipatb_test_wp_remote_post_calls'] );
    }

    public function test_on_deactivation_does_not_ping_status_when_credentials_are_configured_but_consent_is_not_given(): void {
        update_option( 'fusipatb_reseller_code', 'reseller-code' );
        update_option( 'fusipatb_reseller_key', 'reseller-key' );

        Bootstrap::on_deactivation();

        $this->assertSame(
            array(),
            $GLOBALS['__fusipatb_test_wp_remote_post_calls'],
            'Configured credentials without explicit consent must not ping the tbi status endpoint on deactivation.'
        );
    }

    public function test_on_deactivation_pings_status_when_credentials_are_configured_and_consent_is_given(): void {
        update_option( 'fusipatb_reseller_code', 'reseller-code' );
        update_option( 'fusipatb_reseller_key', 'reseller-key' );
        update_option( 'fusipatb_status_ping_consent', '1' );

        Bootstrap::on_deactivation();

        $this->assertCount( 1, $GLOBALS['__fusipatb_test_wp_remote_post_calls'] );
        $this->assertSame( FUSIPATB_API_WOO_SETTINGS_STATUS, $GLOBALS['__fusipatb_test_wp_remote_post_calls'][0]['url'] );
    }
}
