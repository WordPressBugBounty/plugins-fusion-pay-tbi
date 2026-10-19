<?php

namespace FusionPayTbi\Tests;

use FusionPayTbi\Bootstrap;
use ReflectionClass;
use ReflectionMethod;

/**
 * Characterizes Bootstrap::migrate_legacy_options() (src/Bootstrap.php lines 65-81),
 * the one-time copy of legacy unprefixed option keys to their fusipatb_-prefixed
 * replacements added for the CKT-3830 Plugin Check namespace-collision fix.
 *
 * The method is private and Bootstrap::init() also runs define_constants() /
 * register_hooks(), which pull in CountryRegistry/add_action/plugin_dir_path and are
 * unrelated to this change — so tests invoke migrate_legacy_options() directly via
 * reflection instead of going through init(), keeping the harness minimal.
 */
final class BootstrapMigrateLegacyOptionsTest extends TestCase {

    private function invokeMigration(): void {
        $method = new ReflectionMethod( Bootstrap::class, 'migrate_legacy_options' );
        $method->setAccessible( true );
        $method->invoke( null );
    }

    /** @return array<string,string> old_key => new_key, read from the source so tests track it if it changes. */
    private function legacyOptionMap(): array {
        $reflection = new ReflectionClass( Bootstrap::class );
        $property   = $reflection->getProperty( 'legacy_option_map' );
        $property->setAccessible( true );

        return $property->getValue();
    }

    public function test_legacy_option_map_contains_the_documented_bnpl_keys(): void {
        $map = $this->legacyOptionMap();

        $this->assertSame( 'fusipatb_bnpl_min_amount', $map['bnpl_min_amount'] );
        $this->assertSame( 'fusipatb_bnpl_max_amount', $map['bnpl_max_amount'] );
    }

    public function test_copies_a_legacy_value_to_its_prefixed_key(): void {
        update_option( 'bnpl_min_amount', '100' );
        // fusipatb_bnpl_min_amount intentionally not set.

        $this->invokeMigration();

        $this->assertSame( '100', get_option( 'fusipatb_bnpl_min_amount' ) );
    }

    public function test_migrates_every_key_in_the_legacy_option_map(): void {
        $map = $this->legacyOptionMap();
        foreach ( array_keys( $map ) as $index => $old_key ) {
            update_option( $old_key, 'value-' . $index );
        }

        $this->invokeMigration();

        foreach ( $map as $old_key => $new_key ) {
            $this->assertSame(
                get_option( $old_key ),
                get_option( $new_key ),
                "Expected '{$old_key}' to have been copied to '{$new_key}'."
            );
        }
    }

    public function test_does_not_copy_when_the_legacy_option_has_no_value(): void {
        // Neither the old nor the new key is set.
        $this->invokeMigration();

        $this->assertFalse( get_option( 'fusipatb_bnpl_min_amount', false ) );
    }

    public function test_does_not_overwrite_a_prefixed_key_that_already_has_a_value(): void {
        update_option( 'bnpl_min_amount', '100' );
        update_option( 'fusipatb_bnpl_min_amount', '999' );

        $this->invokeMigration();

        $this->assertSame( '999', get_option( 'fusipatb_bnpl_min_amount' ) );
    }

    public function test_sets_the_migrated_flag_after_running(): void {
        $this->assertFalse( get_option( 'fusipatb_legacy_options_migrated', false ) );

        $this->invokeMigration();

        $this->assertSame( 1, get_option( 'fusipatb_legacy_options_migrated' ) );
    }

    public function test_running_again_after_the_flag_is_set_does_not_re_copy_a_changed_legacy_value(): void {
        update_option( 'bnpl_min_amount', '100' );
        $this->invokeMigration();
        $this->assertSame( '100', get_option( 'fusipatb_bnpl_min_amount' ) );

        // Merchant (or a later request) changes the legacy value after migration has run.
        update_option( 'bnpl_min_amount', '200' );
        $this->invokeMigration();

        $this->assertSame(
            '100',
            get_option( 'fusipatb_bnpl_min_amount' ),
            'The migration must only copy once per site, guarded by the migrated flag.'
        );
    }

    // -------------------------------------------------------------------------
    // CKT-3894-follow-up: tbi_* -> fusipatb_* static option key rename.
    // The generic tests above (test_migrates_every_key_in_the_legacy_option_map,
    // test_does_not_overwrite_a_prefixed_key_that_already_has_a_value, etc.)
    // already iterate the *entire* $legacy_option_map, so extending the map is
    // enough to be exercised there too. These tests spot-check a representative
    // sample of the new tbi_* entries plus the specific documented mappings.
    // -------------------------------------------------------------------------

    public function test_legacy_option_map_contains_the_documented_tbi_prefixed_keys(): void {
        $map = $this->legacyOptionMap();

        $this->assertSame( 'fusipatb_reseller_code', $map['tbi_reseller_code'] );
        $this->assertSame( 'fusipatb_reseller_key', $map['tbi_reseller_key'] );
        $this->assertSame( 'fusipatb_encryption_key', $map['tbi_encryption_key'] );
        $this->assertSame( 'fusipatb_checkout_status', $map['tbi_checkout_status'] );
        $this->assertSame( 'fusipatb_default_status', $map['tbi_default_status'] );
        $this->assertSame( 'fusipatb_button_placement', $map['tbi_button_placement'] );
        $this->assertSame( 'fusipatb_active_promo', $map['tbi_active_promo'] );
        $this->assertSame( 'fusipatb_admin_currency', $map['tbi_admin_currency'] );
        $this->assertSame( 'fusipatb_advertising', $map['tbi_advertising'] );
        $this->assertSame( 'fusipatb_cart_checked', $map['tbi_cart_checked'] );
        $this->assertSame( 'fusipatb_product_checked', $map['tbi_product_checked'] );
        $this->assertSame( 'fusipatb_delivery_fee', $map['tbi_delivery_fee'] );
        $this->assertSame( 'fusipatb_delivery_fee_prc', $map['tbi_delivery_fee_prc'] );
        $this->assertSame( 'fusipatb_delivery_limit', $map['tbi_delivery_limit'] );
        $this->assertSame( 'fusipatb_last_settings_error', $map['tbi_last_settings_error'] );
        $this->assertSame( 'fusipatb_max_amount', $map['tbi_max_amount'] );
        $this->assertSame( 'fusipatb_max_amount_eur', $map['tbi_max_amount_eur'] );
        $this->assertSame( 'fusipatb_min_amount', $map['tbi_min_amount'] );
        $this->assertSame( 'fusipatb_min_amount_eur', $map['tbi_min_amount_eur'] );
        $this->assertSame( 'fusipatb_popup_checkout', $map['tbi_popup_checkout'] );
        $this->assertSame( 'fusipatb_popup_url', $map['tbi_popup_url'] );
        $this->assertSame( 'fusipatb_product_button', $map['tbi_product_button'] );
        $this->assertSame( 'fusipatb_sale_promooff', $map['tbi_sale_promooff'] );
        $this->assertSame( 'fusipatb_store_currency', $map['tbi_store_currency'] );
        $this->assertSame( 'fusipatb_tier_count', $map['tbi_tier_count'] );
        $this->assertSame( 'fusipatb_tier1', $map['tbi_tier1'] );
        $this->assertSame( 'fusipatb_tier2', $map['tbi_tier2'] );
        $this->assertSame( 'fusipatb_tier3', $map['tbi_tier3'] );
        $this->assertSame( 'fusipatb_tier1_limit', $map['tbi_tier1_limit'] );
        $this->assertSame( 'fusipatb_tier2_limit', $map['tbi_tier2_limit'] );
        $this->assertSame( 'fusipatb_tier3_limit', $map['tbi_tier3_limit'] );
        $this->assertSame( 'fusipatb_tier1_limit_eur', $map['tbi_tier1_limit_eur'] );
        $this->assertSame( 'fusipatb_tier2_limit_eur', $map['tbi_tier2_limit_eur'] );
        $this->assertSame( 'fusipatb_tier3_limit_eur', $map['tbi_tier3_limit_eur'] );
        $this->assertSame( 'fusipatb_cart_button_text', $map['tbi_cart_button_text'] );
        $this->assertSame( 'fusipatb_product_button_text', $map['tbi_product_button_text'] );
    }

    /**
     * Invokes the private dynamic-tier migration step. Named separately from
     * migrate_legacy_options() so tests can call the static-map migration and
     * the dynamic steps independently where useful, but production code
     * always runs them together (see Bootstrap::migrate_legacy_options()).
     */
    private function invokeMigrateDynamicTierOptions(): void {
        $method = new ReflectionMethod( Bootstrap::class, 'migrate_dynamic_tier_options' );
        $method->setAccessible( true );
        $method->invoke( null );
    }

    private function invokeMigrateDynamicPromoOptions(): void {
        $method = new ReflectionMethod( Bootstrap::class, 'migrate_dynamic_promo_options' );
        $method->setAccessible( true );
        $method->invoke( null );
    }

    public function test_migrate_legacy_options_copies_dynamic_tier_keys_bounded_by_migrated_tier_count(): void {
        update_option( 'tbi_tier_count', 5 );
        for ( $i = 1; $i <= 6; $i++ ) {
            // tier6 is beyond tier_count and must NOT be migrated.
            update_option( "tbi_tier{$i}", 10 + $i );
        }

        $this->invokeMigration();

        $this->assertSame( 5, get_option( 'fusipatb_tier_count' ) );
        for ( $i = 1; $i <= 5; $i++ ) {
            $this->assertSame(
                10 + $i,
                get_option( "fusipatb_tier{$i}" ),
                "Expected fusipatb_tier{$i} to be migrated from tbi_tier{$i}."
            );
        }
        $this->assertFalse(
            get_option( 'fusipatb_tier6', false ),
            'tier6 is beyond the migrated tier_count and must not be migrated.'
        );
    }

    public function test_migrate_legacy_options_does_not_run_dynamic_tier_migration_when_tier_count_is_zero(): void {
        // tbi_tier_count never set (defaults to 0) -- nothing to loop over.
        // tier4 is beyond the static map's tier1-3 entries, so it can only be
        // migrated by the dynamic loop.
        update_option( 'tbi_tier4', 99 );

        $this->invokeMigration();

        $this->assertFalse( get_option( 'fusipatb_tier4', false ) );
    }

    public function test_migrate_legacy_options_copies_dynamic_promo_product_and_category_keys(): void {
        update_option( 'tbi_active_promo', json_encode( array(
            array( 'id' => 7, 'category_id' => 3 ),
            array( 'id' => 12, 'category_id' => 0 ),
        ) ) );
        update_option( 'tbi_promo_products_7', '101,102' );
        update_option( 'tbi_promo_categories_7', '9,10' );
        update_option( 'tbi_promo_products_12', '201' );
        update_option( 'tbi_promo_categories_12', '' );

        $this->invokeMigration();

        $this->assertSame( '101,102', get_option( 'fusipatb_promo_products_7' ) );
        $this->assertSame( '9,10', get_option( 'fusipatb_promo_categories_7' ) );
        $this->assertSame( '201', get_option( 'fusipatb_promo_products_12' ) );
    }

    public function test_migrate_legacy_options_dynamic_promo_migration_is_noop_when_active_promo_is_empty(): void {
        // tbi_active_promo left unset (defaults to false via get_option).
        update_option( 'tbi_promo_products_7', '101' );

        $this->invokeMigration();

        $this->assertFalse( get_option( 'fusipatb_promo_products_7', false ) );
    }

    public function test_migrate_legacy_options_does_not_overwrite_an_already_set_dynamic_key(): void {
        update_option( 'tbi_tier_count', 1 );
        update_option( 'tbi_tier1', 5 );
        update_option( 'fusipatb_tier1', 999 );

        $this->invokeMigration();

        $this->assertSame( 999, get_option( 'fusipatb_tier1' ) );
    }

    // -------------------------------------------------------------------------
    // CKT-4007 follow-up: dynamic per-tier "tier{N}"/"tier{N}_multiplier"/
    // "period{N}" -> "fusipatb_tier{N}_amount"/"fusipatb_tier{N}_multiplier"/
    // "fusipatb_period{N}" migration, driven by the new private
    // migrate_option_once() helper (src/Bootstrap.php lines 186-194) and its
    // caller loop inside migrate_dynamic_tier_options() (lines 173-177). Also
    // covers the new static tier1_multiplier/tier2_multiplier/tier3_multiplier
    // entries added to $legacy_option_map (lines 96-98).
    // -------------------------------------------------------------------------

    private function invokeMigrateOptionOnce( $old_key, $new_key ): void {
        $method = new ReflectionMethod( Bootstrap::class, 'migrate_option_once' );
        $method->setAccessible( true );
        $method->invoke( null, $old_key, $new_key );
    }

    public function test_migrate_option_once_copies_the_legacy_value_when_the_new_key_is_unset(): void {
        update_option( 'tier1', 100 );

        $this->invokeMigrateOptionOnce( 'tier1', 'fusipatb_tier1_amount' );

        $this->assertSame( 100, get_option( 'fusipatb_tier1_amount' ) );
    }

    public function test_migrate_option_once_does_not_overwrite_an_already_set_new_key(): void {
        update_option( 'tier1', 100 );
        update_option( 'fusipatb_tier1_amount', 999 );

        $this->invokeMigrateOptionOnce( 'tier1', 'fusipatb_tier1_amount' );

        $this->assertSame( 999, get_option( 'fusipatb_tier1_amount' ) );
    }

    public function test_migrate_option_once_is_a_noop_when_the_old_key_has_no_value(): void {
        // Neither 'tier1' nor 'fusipatb_tier1_amount' is set.
        $this->invokeMigrateOptionOnce( 'tier1', 'fusipatb_tier1_amount' );

        $this->assertFalse( get_option( 'fusipatb_tier1_amount', false ) );
    }

    public function test_migrate_dynamic_tier_options_migrates_amount_multiplier_and_period_for_every_tier(): void {
        update_option( 'fusipatb_tier_count', 2 );
        update_option( 'tier1', 100 );
        update_option( 'tier1_multiplier', 0.05 );
        update_option( 'period1', 6 );
        update_option( 'tier2', 200 );
        update_option( 'tier2_multiplier', 0.08 );
        update_option( 'period2', 12 );

        $this->invokeMigrateDynamicTierOptions();

        $this->assertSame( 100, get_option( 'fusipatb_tier1_amount' ) );
        $this->assertSame( 0.05, get_option( 'fusipatb_tier1_multiplier' ) );
        $this->assertSame( 6, get_option( 'fusipatb_period1' ) );
        $this->assertSame( 200, get_option( 'fusipatb_tier2_amount' ) );
        $this->assertSame( 0.08, get_option( 'fusipatb_tier2_multiplier' ) );
        $this->assertSame( 12, get_option( 'fusipatb_period2' ) );
    }

    public function test_migrate_dynamic_tier_options_does_not_migrate_a_tier_beyond_tier_count(): void {
        update_option( 'fusipatb_tier_count', 1 );
        update_option( 'tier2', 200 );
        update_option( 'tier2_multiplier', 0.08 );
        update_option( 'period2', 12 );

        $this->invokeMigrateDynamicTierOptions();

        $this->assertFalse( get_option( 'fusipatb_tier2_amount', false ) );
        $this->assertFalse( get_option( 'fusipatb_tier2_multiplier', false ) );
        $this->assertFalse( get_option( 'fusipatb_period2', false ) );
    }

    public function test_migrate_dynamic_tier_options_does_not_overwrite_an_already_set_new_amount_key(): void {
        update_option( 'fusipatb_tier_count', 1 );
        update_option( 'tier1', 100 );
        update_option( 'fusipatb_tier1_amount', 999 );

        $this->invokeMigrateDynamicTierOptions();

        $this->assertSame( 999, get_option( 'fusipatb_tier1_amount' ) );
    }

    public function test_migrate_legacy_options_migrates_the_full_dynamic_per_tier_set_via_the_public_entrypoint(): void {
        update_option( 'fusipatb_tier_count', 2 );
        update_option( 'tier1', 100 );
        update_option( 'tier1_multiplier', 0.05 );
        update_option( 'period1', 6 );
        update_option( 'tier2', 200 );
        update_option( 'tier2_multiplier', 0.08 );
        update_option( 'period2', 12 );

        $this->invokeMigration();

        $this->assertSame( 100, get_option( 'fusipatb_tier1_amount' ) );
        $this->assertSame( 0.05, get_option( 'fusipatb_tier1_multiplier' ) );
        $this->assertSame( 6, get_option( 'fusipatb_period1' ) );
        $this->assertSame( 200, get_option( 'fusipatb_tier2_amount' ) );
        $this->assertSame( 0.08, get_option( 'fusipatb_tier2_multiplier' ) );
        $this->assertSame( 12, get_option( 'fusipatb_period2' ) );
    }

    public function test_migrate_legacy_options_does_not_overwrite_an_already_set_dynamic_amount_key(): void {
        update_option( 'fusipatb_tier_count', 1 );
        update_option( 'tier1', 100 );
        update_option( 'fusipatb_tier1_amount', 999 );

        $this->invokeMigration();

        $this->assertSame( 999, get_option( 'fusipatb_tier1_amount' ) );
    }

    public function test_legacy_option_map_contains_the_static_tier_multiplier_keys(): void {
        $map = $this->legacyOptionMap();

        $this->assertSame( 'fusipatb_tier1_multiplier', $map['tier1_multiplier'] );
        $this->assertSame( 'fusipatb_tier2_multiplier', $map['tier2_multiplier'] );
        $this->assertSame( 'fusipatb_tier3_multiplier', $map['tier3_multiplier'] );
    }

    public function test_migrate_legacy_options_copies_the_static_tier_multiplier_keys(): void {
        update_option( 'tier1_multiplier', 0.05 );
        update_option( 'tier2_multiplier', 0.08 );
        update_option( 'tier3_multiplier', 0.10 );

        $this->invokeMigration();

        $this->assertSame( 0.05, get_option( 'fusipatb_tier1_multiplier' ) );
        $this->assertSame( 0.08, get_option( 'fusipatb_tier2_multiplier' ) );
        $this->assertSame( 0.10, get_option( 'fusipatb_tier3_multiplier' ) );
    }

    public function test_migrate_legacy_options_does_not_overwrite_an_already_set_static_tier_multiplier_key(): void {
        update_option( 'tier1_multiplier', 0.05 );
        update_option( 'fusipatb_tier1_multiplier', 0.99 );

        $this->invokeMigration();

        $this->assertSame( 0.99, get_option( 'fusipatb_tier1_multiplier' ) );
    }
}
