<?php

namespace FusionPayTbi\Tests;

use FusionPayTbi\Compat\TmEpoSupport;

/**
 * Characterizes TmEpoSupport::sanitizePostData() (src/Compat/TmEpoSupport.php),
 * the pure sanitization step extracted out of buildCartItemData() for the
 * WP.org plugin-review fix.
 *
 * Bug: buildCartItemData()'s sanitizing fallback
 * (`array_map('sanitize_text_field', wp_unslash($_POST))`) only ran when its
 * caller passed `$post_data = null`. The only real caller,
 * ProductProcessor::handle(), always passes `$_POST` directly (never null),
 * so the fallback never ran and raw, unsanitized `$_POST` (including nested
 * arrays from TMCP checkbox groups) was handed straight to the third-party
 * TM_EPO()->add_cart_item_data_helper()/add_cart_item() API. Whatever labels
 * that plugin extracts from it flow into WooCommerce order-item meta
 * (TmEpoSupport::applyOrderItemMeta() -> buildVisibleOrderMetaRows()) and are
 * later rendered in admin order screens and customer order emails.
 *
 * Also: the original fallback (`array_map('sanitize_text_field', ...)`) is
 * itself broken for nested array values -- sanitize_text_field() expects a
 * string, and PHP 8's internal string-typed functions (trim(), etc., which
 * sanitize_text_field() calls internally) throw a TypeError when given an
 * array. TMCP can post grouped fields as nested arrays, so a flat
 * array_map() is not a safe sanitizer here.
 *
 * Fix: sanitizePostData() recursively sanitizes every scalar leaf with
 * wp_unslash() + sanitize_text_field() (matching extractOptionNames()'s
 * existing convention) and recurses into nested arrays instead of passing
 * them to sanitize_text_field() directly.
 */
final class TmEpoSupportSanitizePostDataTest extends TestCase {

    public function test_sanitizes_a_scalar_string_value_stripping_html_tags(): void {
        $result = TmEpoSupport::sanitizePostData( array(
            'extra_name' => '<script>alert(1)</script>Gift wrap',
        ) );

        $this->assertSame( array( 'extra_name' => 'alert(1)Gift wrap' ), $result );
    }

    public function test_recursively_sanitizes_nested_array_values_without_erroring(): void {
        // TMCP can post a grouped field as a nested array, e.g. multi-select
        // or grouped checkbox values under one key.
        $result = TmEpoSupport::sanitizePostData( array(
            'tmcp_group_1' => array(
                '<b>Option A</b>',
                '<script>alert(1)</script>Option B',
            ),
        ) );

        $this->assertSame(
            array(
                'tmcp_group_1' => array( 'Option A', 'alert(1)Option B' ),
            ),
            $result
        );
    }

    public function test_recurses_into_deeply_nested_arrays(): void {
        $result = TmEpoSupport::sanitizePostData( array(
            'outer' => array(
                'inner' => array(
                    '<i>deep</i>',
                ),
            ),
        ) );

        $this->assertSame(
            array( 'outer' => array( 'inner' => array( 'deep' ) ) ),
            $result
        );
    }

    public function test_leaves_non_string_scalar_values_unchanged(): void {
        $result = TmEpoSupport::sanitizePostData( array(
            'variation_id' => 42,
        ) );

        $this->assertSame( array( 'variation_id' => 42 ), $result );
    }

    public function test_preserves_array_keys_at_every_level(): void {
        $result = TmEpoSupport::sanitizePostData( array(
            'a' => 'x',
            'b' => array( 'k1' => '<b>y</b>', 'k2' => 'z' ),
        ) );

        $this->assertSame(
            array( 'a' => 'x', 'b' => array( 'k1' => 'y', 'k2' => 'z' ) ),
            $result
        );
    }
}
