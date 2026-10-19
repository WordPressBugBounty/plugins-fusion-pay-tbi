<?php

namespace FusionPayTbi\Tests\Helpers;

use FusionPayTbi\Helpers\WooCommerceRequirement;
use FusionPayTbi\Tests\TestCase;

/**
 * Behaviour: fusion-pay-tbi.php must not silently no-op when WooCommerce is
 * inactive. Instead it must tell an admin who can act on it, via a dismissible
 * admin_notices notice, that WooCommerce is required. Extracted out of the
 * top-level plugin bootstrap file into this class so the check and the notice
 * are directly testable (fusion-pay-tbi.php itself has no test coverage and
 * runs before the PSR-4 autoloader is registered).
 */
final class WooCommerceRequirementTest extends TestCase {

    public function test_is_woocommerce_active_is_false_when_not_in_active_plugins(): void {
        update_option( 'active_plugins', array( 'some-other-plugin/some-other-plugin.php' ) );

        $this->assertFalse( WooCommerceRequirement::isWooCommerceActive() );
    }

    public function test_is_woocommerce_active_is_true_when_active_on_this_site(): void {
        update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );

        $this->assertTrue( WooCommerceRequirement::isWooCommerceActive() );
    }

    public function test_is_woocommerce_active_is_true_when_active_network_wide(): void {
        update_option( 'active_plugins', array() );
        $GLOBALS['__fusipatb_test_network_active_plugins'] = array( 'woocommerce/woocommerce.php' );

        $this->assertTrue( WooCommerceRequirement::isWooCommerceActive() );
    }

    public function test_notify_missing_hooks_the_admin_notices_action(): void {
        WooCommerceRequirement::notifyMissing();

        $this->assertCount( 1, $GLOBALS['__fusipatb_test_add_action_calls'] );
        $this->assertSame( 'admin_notices', $GLOBALS['__fusipatb_test_add_action_calls'][0]['hook'] );
        $this->assertSame(
            array( WooCommerceRequirement::class, 'renderMissingNotice' ),
            $GLOBALS['__fusipatb_test_add_action_calls'][0]['callback']
        );
    }

    public function test_render_missing_notice_outputs_the_message_for_a_user_who_can_activate_plugins(): void {
        $GLOBALS['__fusipatb_test_current_user_can_result'] = true;

        ob_start();
        WooCommerceRequirement::renderMissingNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'requires WooCommerce', $output );
        $this->assertStringContainsString( 'notice-error', $output );
        $this->assertStringContainsString( 'is-dismissible', $output );
    }

    public function test_render_missing_notice_outputs_nothing_for_a_user_who_cannot_activate_plugins(): void {
        $GLOBALS['__fusipatb_test_current_user_can_result'] = false;

        ob_start();
        WooCommerceRequirement::renderMissingNotice();
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }
}
