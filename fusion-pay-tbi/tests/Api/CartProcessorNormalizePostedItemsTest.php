<?php

namespace FusionPayTbi\Tests\Api;

use FusionPayTbi\Api\CartProcessor;
use FusionPayTbi\Tests\TestCase;
use ReflectionMethod;

/**
 * Characterizes CartProcessor::normalizePostedItems() (src/Api/CartProcessor.php),
 * the WP.org review "Data Must be Sanitized, Escaped, and Validated" fix.
 *
 * Bug: json_decode( wp_unslash( $_POST['items'] ) ) is not a sanitizer. The decoded
 * stdClass items were used directly -- $item->price compared with `< 0`, multiplied
 * into $cart_total, and $item->product_id/->variation_id passed straight into
 * `new \WC_Product(...)`/`new \WC_Product_Variation(...)` -- with no casting or
 * validation of client-controlled AJAX input. A non-numeric price (e.g. "abc") would
 * floatval() to 0, which is not negative, so it silently passed the existing
 * `$item->price < 0` discount-line check as a "legitimate" $0 item instead of being
 * recognized as garbage.
 *
 * Design note: `$item->price < 0` (src/Api/CartProcessor.php ~line 118) is NOT an
 * error/reject branch -- it is the intentional discount/coupon-line code path (adds a
 * coupon line item and continues, matching FUSIPATB_COUPON_NAME handling). It is left
 * unchanged. normalizePostedItems() only decides, upstream of that branch, whether an
 * item's price can be trusted as a real number at all.
 *
 * Scope boundary: the method is private/static and the rest of handle() needs a much
 * larger WC/CountryRegistry/Cryptor/InstallmentCalculator stub surface than this
 * minimal harness provides (see tests/Api/CartProcessorTest.php's class docblock),
 * so these tests invoke normalizePostedItems() directly via reflection -- the same
 * convention already used for Bootstrap::migrate_legacy_options() in
 * tests/BootstrapMigrateLegacyOptionsTest.php -- rather than driving the full
 * CartProcessor::handle() AJAX flow.
 */
final class CartProcessorNormalizePostedItemsTest extends TestCase {

    /** @param mixed $items_array */
    private function invokeNormalize( $items_array ) {
        $method = new ReflectionMethod( CartProcessor::class, 'normalizePostedItems' );
        $method->setAccessible( true );

        return $method->invoke( null, $items_array );
    }

    public function test_drops_an_item_with_a_non_numeric_price(): void {
        $items = $this->invokeNormalize( array(
            (object) array(
                'product_id'   => 10,
                'variation_id' => 0,
                'quantity'     => 1,
                'price'        => 'abc',
            ),
        ) );

        $this->assertSame( array(), $items );
    }

    public function test_drops_an_item_with_no_price_field_at_all(): void {
        $items = $this->invokeNormalize( array(
            (object) array(
                'product_id'   => 10,
                'variation_id' => 0,
                'quantity'     => 1,
            ),
        ) );

        $this->assertSame( array(), $items );
    }

    public function test_drops_entries_that_are_not_objects_or_arrays(): void {
        $items = $this->invokeNormalize( array( 'just-a-string', 42, true, null ) );

        $this->assertSame( array(), $items );
    }

    public function test_a_mix_of_garbage_and_valid_items_keeps_only_the_valid_one(): void {
        $items = $this->invokeNormalize( array(
            (object) array( 'product_id' => 1, 'variation_id' => 0, 'quantity' => 1, 'price' => 'not-a-number' ),
            (object) array( 'product_id' => 5, 'variation_id' => 0, 'quantity' => 2, 'price' => 19.99 ),
        ) );

        $this->assertCount( 1, $items );
        $this->assertSame( 5, $items[0]->product_id );
        $this->assertSame( 19.99, $items[0]->price );
    }

    public function test_coerces_a_non_numeric_product_id_via_absint_instead_of_dropping_the_item(): void {
        $items = $this->invokeNormalize( array(
            (object) array( 'product_id' => 'abc', 'variation_id' => 0, 'quantity' => 1, 'price' => 9.5 ),
        ) );

        $this->assertCount( 1, $items );
        $this->assertSame( 0, $items[0]->product_id );
    }

    public function test_keeps_a_valid_negative_price_untouched_for_the_existing_discount_line_branch(): void {
        // Negative price is intentional business logic (coupon/discount line,
        // src/Api/CartProcessor.php ~line 118) -- normalization must not reject it,
        // only make sure it is a real float.
        $items = $this->invokeNormalize( array(
            (object) array( 'product_id' => 0, 'variation_id' => 0, 'quantity' => 1, 'price' => -12.5 ),
        ) );

        $this->assertCount( 1, $items );
        $this->assertSame( -12.5, $items[0]->price );
    }

    public function test_happy_path_numeric_strings_from_js_are_cast_to_the_right_types(): void {
        // JS often round-trips numbers as strings in the JSON payload.
        $items = $this->invokeNormalize( array(
            (object) array( 'product_id' => '42', 'variation_id' => '0', 'quantity' => '2', 'price' => '19.99' ),
        ) );

        $this->assertCount( 1, $items );
        $this->assertSame( 42, $items[0]->product_id );
        $this->assertSame( 0, $items[0]->variation_id );
        $this->assertSame( 2, $items[0]->quantity );
        $this->assertSame( 19.99, $items[0]->price );
    }

    public function test_quantity_is_floored_to_one_when_posted_as_zero_or_missing(): void {
        $items = $this->invokeNormalize( array(
            (object) array( 'product_id' => 1, 'variation_id' => 0, 'quantity' => 0, 'price' => 5 ),
            (object) array( 'product_id' => 2, 'variation_id' => 0, 'price' => 5 ),
        ) );

        $this->assertSame( 1, $items[0]->quantity );
        $this->assertSame( 1, $items[1]->quantity );
    }

    public function test_preserves_extra_fields_like_tm_epo_data_untouched(): void {
        $items = $this->invokeNormalize( array(
            (object) array(
                'product_id'   => 1,
                'variation_id' => 0,
                'quantity'     => 1,
                'price'        => 5,
                'tm_epo_data'  => array( 'foo' => 'bar' ),
            ),
        ) );

        $this->assertSame( array( 'foo' => 'bar' ), $items[0]->tm_epo_data );
    }

    public function test_non_array_input_is_returned_unchanged(): void {
        $this->assertNull( $this->invokeNormalize( null ) );
        $this->assertFalse( $this->invokeNormalize( false ) );
    }
}
