<?php

namespace FusionPayTbi\Tests\Helpers;

use FusionPayTbi\Helpers\SecurityHelper;
use FusionPayTbi\Tests\TestCase;

/**
 * Characterizes the wp_unslash()-before-esc_url_raw() fix applied to
 * SecurityHelper::isOriginAllowed()/isLocalOriginAllowed() for the CKT-3830
 * Plugin Check round-2 review.
 *
 * The bug: $_SERVER['HTTP_ORIGIN'] goes through WordPress's magic-quote
 * escaping like any other superglobal. A legitimate origin never contains a
 * quote or backslash in practice, but esc_url_raw() alone does not strip
 * magic-quote backslashes -- only wp_unslash() does -- so a header value that
 * happened to contain one (e.g. a malformed/spoofed Origin, or a URL with an
 * encoded backslash before %-decoding) would fail the exact-match comparison
 * even when it should have matched, or vice versa. These tests pin the
 * unslash-then-compare behaviour directly against the allowed origin
 * constants (see tests/bootstrap.php).
 */
final class SecurityHelperTest extends TestCase {

    public function test_isOriginAllowed_returns_true_for_the_allowed_gateway_origin(): void {
        $_SERVER['HTTP_ORIGIN'] = FUSIPATB_API_GATEWAY_ALLOWED_ORIGIN;

        $this->assertTrue( SecurityHelper::isOriginAllowed() );
    }

    public function test_isOriginAllowed_strips_magic_quote_backslashes_before_comparing(): void {
        // A magic-quoted origin containing a backslash-escaped character still
        // round-trips to the allowed origin once unslashed. We simulate this by
        // escaping a character that is present in the allowed origin constant.
        $magic_quoted = str_replace( '.', '\\.', FUSIPATB_API_GATEWAY_ALLOWED_ORIGIN );
        $_SERVER['HTTP_ORIGIN'] = $magic_quoted;

        $this->assertTrue( SecurityHelper::isOriginAllowed() );
    }

    public function test_isOriginAllowed_returns_false_for_an_unrelated_origin(): void {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.test';

        $this->assertFalse( SecurityHelper::isOriginAllowed() );
    }

    public function test_isOriginAllowed_returns_false_when_no_origin_header_is_present(): void {
        unset( $_SERVER['HTTP_ORIGIN'] );

        $this->assertFalse( SecurityHelper::isOriginAllowed() );
    }

    public function test_isLocalOriginAllowed_returns_true_for_the_allowed_local_origin(): void {
        $_SERVER['HTTP_ORIGIN'] = FUSIPATB_API_LOCAL_ALLOWED_ORIGIN;

        $this->assertTrue( SecurityHelper::isLocalOriginAllowed() );
    }

    public function test_isLocalOriginAllowed_strips_magic_quote_backslashes_before_comparing(): void {
        $magic_quoted = str_replace( '.', '\\.', FUSIPATB_API_LOCAL_ALLOWED_ORIGIN );
        $_SERVER['HTTP_ORIGIN'] = $magic_quoted;

        $this->assertTrue( SecurityHelper::isLocalOriginAllowed() );
    }

    public function test_isLocalOriginAllowed_returns_false_for_an_unrelated_origin(): void {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.test';

        $this->assertFalse( SecurityHelper::isLocalOriginAllowed() );
    }
}
