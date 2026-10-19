<?php

namespace FusionPayTbi\Tests\Admin;

use FusionPayTbi\Admin\SettingsSaver;
use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Tests\TestCase;

/**
 * Characterizes the wp_unslash()-before-sanitize_text_field() fix applied to
 * SettingsSaver::save()/callSettingsApi()/saveFormFields() for the CKT-3830
 * Plugin Check round-2 review.
 *
 * The bug: WordPress runs wp_magic_quotes() on every superglobal, so a POSTed
 * value containing a quote or backslash arrives backslash-escaped (e.g. an
 * original "O'Brien" arrives as "O\'Brien"). sanitize_text_field() alone does
 * NOT strip that escaping -- only wp_unslash() does. Without wp_unslash(),
 * a reseller key, encryption key, or promo id list containing a quote or
 * backslash would have been persisted to the options table with a stray
 * backslash baked in, silently corrupting stored credentials/config.
 *
 * Scope boundary: save() also drives CountryRegistry (real singleton, real
 * BulgariaConfig -- neither has any WordPress dependency for the methods
 * reached here) and callSettingsApi() (which calls wp_remote_post()). Rather
 * than mocking the whole WooSettings API round-trip, every test here forces
 * wp_remote_post() to return a WP_Error via the bootstrap stub, so
 * callSettingsApi() short-circuits immediately after the option writes this
 * suite cares about (see SettingsSaver::save() lines 32-40, which run BEFORE
 * callSettingsApi() is even called). saveFormFields() always runs regardless
 * of the API outcome, so its unslash fixes are equally exercised here.
 */
final class SettingsSaverTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        // Real singleton; reset so each test starts from a fresh BulgariaConfig
        // (default country when 'woocommerce_default_country' is unset).
        CountryRegistry::reset();

        // save() is gated behind check_admin_referer(); every scenario below is
        // about what happens once a request already has a valid nonce.
        $GLOBALS['__fusipatb_test_check_admin_referer_result'] = true;

        // Force callSettingsApi()'s wp_remote_post() to fail fast so it never
        // tries to parse a real (nonexistent) HTTP response in these tests.
        $GLOBALS['__fusipatb_test_wp_remote_post_response'] = new \WP_Error(
            'http_request_failed',
            'stubbed: no network access in tests'
        );
    }

    protected function tearDown(): void {
        CountryRegistry::reset();
        parent::tearDown();
    }

    public function test_reseller_code_with_magic_quoted_apostrophe_is_stored_without_stray_backslash(): void {
        // Simulates what WordPress's wp_magic_quotes() does to a shopper-typed
        // reseller code of "O'Brien" before it reaches $_POST.
        $_POST['reseller_code'] = "O\\'Brien";

        SettingsSaver::save();

        $this->assertSame( "O'Brien", get_option( 'fusipatb_reseller_code' ) );
        $this->assertStringNotContainsString( '\\', (string) get_option( 'fusipatb_reseller_code' ) );
    }

    public function test_reseller_key_with_magic_quoted_backslash_is_stored_without_stray_backslash(): void {
        // Magic-quoted representation of a reseller key that legitimately
        // contains a backslash, e.g. "abc\def" arrives as "abc\\def".
        $_POST['reseller_key'] = 'abc\\\\def';

        SettingsSaver::save();

        $this->assertSame( 'abc\\def', get_option( 'fusipatb_reseller_key' ) );
    }

    public function test_encryption_key_with_magic_quoted_quote_is_stored_without_stray_backslash(): void {
        $_POST['encryption_key'] = "sup3r\\\"secret\\\"key";

        SettingsSaver::save();

        $this->assertSame( 'sup3r"secret"key', get_option( 'fusipatb_encryption_key' ) );
    }

    public function test_default_status_and_button_placement_are_stored_without_stray_backslash(): void {
        $_POST['fusipatb_default_status']   = "processing\\'ish";
        $_POST['fusipatb_button_placement'] = "after_add_to_cart\\'button";

        SettingsSaver::save();

        $this->assertSame( "processing'ish", get_option( 'fusipatb_default_status' ) );
        $this->assertSame( "after_add_to_cart'button", get_option( 'fusipatb_button_placement' ) );
    }

    public function test_dynamic_promo_product_and_category_keys_are_stored_without_stray_backslash(): void {
        // saveFormFields() derives the promo POST keys to read from the
        // currently stored fusipatb_active_promo scheme list.
        update_option( 'fusipatb_active_promo', json_encode( array( array( 'id' => 42, 'category_id' => 7 ) ) ) );

        $_POST['fusipatb_promo_products_42']   = "12,34\\'ok";
        $_POST['fusipatb_promo_categories_42'] = "5,6\\'ok";

        SettingsSaver::save();

        $this->assertSame( "12,34'ok", get_option( 'fusipatb_promo_products_42' ) );
        $this->assertSame( "5,6'ok", get_option( 'fusipatb_promo_categories_42' ) );
    }

    public function test_save_returns_empty_string_without_a_valid_nonce(): void {
        $GLOBALS['__fusipatb_test_check_admin_referer_result'] = false;
        $_POST['reseller_code'] = "O\\'Brien";

        $result = SettingsSaver::save();

        $this->assertSame( '', $result );
        // The whole body of save() is gated behind the referer check -- nothing
        // should have been written to the options table.
        $this->assertFalse( get_option( 'fusipatb_reseller_code', false ) );
    }
}
