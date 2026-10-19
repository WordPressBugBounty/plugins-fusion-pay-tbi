<?php

namespace FusionPayTbi\Tests\Api;

use FusionPayTbi\Api\CartProcessor;
use FusionPayTbi\Tests\TestCase;
use ReflectionMethod;

/**
 * Characterizes CartProcessor::resolveRoPageContext() (src/Api/CartProcessor.php
 * lines 434-451), specifically the WP.org Plugin Check "Proper sanitization of
 * inputs" follow-up that changed the $_SERVER['HTTP_REFERER'] read from:
 *   strtolower( (string) $_SERVER['HTTP_REFERER'] )
 * to:
 *   strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) )
 *
 * Scope boundary: the method is private/static and the rest of CartProcessor::handle()
 * needs a much larger WC/CountryRegistry/Cryptor stub surface than this minimal
 * harness provides (see CartProcessorTest.php's class docblock), so these tests
 * invoke resolveRoPageContext() directly via reflection -- the same convention
 * already used for Bootstrap::migrate_legacy_options() and
 * CartProcessor::normalizePostedItems().
 */
final class CartProcessorResolveRoPageContextTest extends TestCase {

    private function invoke(): string {
        $method = new ReflectionMethod( CartProcessor::class, 'resolveRoPageContext' );
        $method->setAccessible( true );

        return $method->invoke( null );
    }

    public function test_defaults_to_cart_when_no_page_param_and_no_referer_are_present(): void {
        // $_POST['page'] and $_SERVER['HTTP_REFERER'] intentionally absent.
        $this->assertSame( 'cart', $this->invoke() );
    }

    public function test_posted_page_param_wins_over_the_referer(): void {
        $_POST['page']           = 'product';
        $_SERVER['HTTP_REFERER'] = 'https://shop.example.test/checkout/';

        $this->assertSame( 'product', $this->invoke() );
    }

    public function test_returns_checkout_for_a_referer_containing_checkout(): void {
        $_SERVER['HTTP_REFERER'] = 'https://shop.example.test/checkout/';

        $this->assertSame( 'checkout', $this->invoke() );
    }

    public function test_returns_cart_for_a_referer_containing_cart(): void {
        $_SERVER['HTTP_REFERER'] = 'https://shop.example.test/cart/';

        $this->assertSame( 'cart', $this->invoke() );
    }

    public function test_returns_cart_for_a_referer_containing_basket(): void {
        $_SERVER['HTTP_REFERER'] = 'https://shop.example.test/basket/';

        $this->assertSame( 'cart', $this->invoke() );
    }

    public function test_falls_back_to_cart_for_an_unrelated_referer(): void {
        $_SERVER['HTTP_REFERER'] = 'https://shop.example.test/product/some-product/';

        $this->assertSame( 'cart', $this->invoke() );
    }

    public function test_does_not_choke_on_an_html_tag_bearing_referer_and_still_matches_checkout(): void {
        // sanitize_text_field() strips markup (strip_tags) but does not remove the
        // text content between tags, so the 'checkout' substring this test relies
        // on to prove strpos() still matches survives the sanitization step; the
        // key behaviour under test is that a script-tag-bearing referer no longer
        // reaches strtolower()/strpos() unsanitized and does not error out.
        $_SERVER['HTTP_REFERER'] = 'https://shop.example.test/<script>alert(1)</script>checkout/';

        $this->assertSame( 'checkout', $this->invoke() );
    }

    public function test_unslashes_a_magic_quote_escaped_referer_before_matching(): void {
        // Mirrors the wp_unslash()-before-comparison fix already characterized for
        // SecurityHelper::isOriginAllowed()/isLocalOriginAllowed() -- a magic-quoted
        // referer (backslash-escaped character) must still match after wp_unslash().
        $_SERVER['HTTP_REFERER'] = 'https://shop.example.test/che\\ckout/';

        $this->assertSame( 'checkout', $this->invoke() );
    }

    public function test_matching_is_case_insensitive_via_strtolower(): void {
        $_SERVER['HTTP_REFERER'] = 'https://shop.example.test/CHECKOUT/';

        $this->assertSame( 'checkout', $this->invoke() );
    }
}
