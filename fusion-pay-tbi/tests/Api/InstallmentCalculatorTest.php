<?php

namespace FusionPayTbi\Tests\Api;

use FusionPayTbi\Api\InstallmentCalculator;
use FusionPayTbi\Tests\TestCase;
use WpAjaxResponseException;

/**
 * Characterizes the nonce gate added to InstallmentCalculator::handleAjax()
 * for the CKT-3830 Plugin Check security fixes (nonce action
 * "fusipatb_calculate_installments_ajax", src/Api/InstallmentCalculator.php
 * lines 18-22), plus the pre-existing amount-validation and calculate()
 * delegation it gates access to.
 */
final class InstallmentCalculatorTest extends TestCase {

    public function test_handleAjax_rejects_request_with_no_nonce_field(): void {
        try {
            InstallmentCalculator::handleAjax();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
            $this->assertSame( 403, $e->status );
        }
    }

    public function test_handleAjax_rejects_request_with_invalid_nonce(): void {
        $_POST['_wpnonce'] = 'garbage';

        try {
            InstallmentCalculator::handleAjax();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( array( 'message' => 'Security verification failed' ), $e->data );
            $this->assertSame( 403, $e->status );
        }
    }

    public function test_handleAjax_valid_nonce_but_zero_amount_is_rejected_as_invalid(): void {
        $_POST['_wpnonce'] = 'valid-nonce-for-fusipatb_calculate_installments_ajax';
        $_POST['amount']   = '0';

        try {
            InstallmentCalculator::handleAjax();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            // Distinct message from the nonce failure proves the nonce gate was passed.
            $this->assertSame( array( 'message' => 'Invalid amount provided' ), $e->data );
            $this->assertNull( $e->status );
        }
    }

    public function test_handleAjax_valid_nonce_and_amount_without_reseller_credentials_fails_to_calculate(): void {
        $_POST['_wpnonce'] = 'valid-nonce-for-fusipatb_calculate_installments_ajax';
        $_POST['amount']   = '150.00';
        $_POST['currency'] = 'BGN';
        // No tbi_reseller_code / tbi_reseller_key configured in the fake option store,
        // so InstallmentCalculator::calculate() returns null before any HTTP call.

        try {
            InstallmentCalculator::handleAjax();
            $this->fail( 'Expected WpAjaxResponseException to be thrown.' );
        } catch ( WpAjaxResponseException $e ) {
            $this->assertSame( 'error', $e->type );
            $this->assertSame( array( 'message' => 'Failed to calculate installments' ), $e->data );
            $this->assertNull( $e->status );
        }
    }

    public function test_calculate_returns_null_without_reseller_credentials(): void {
        $this->assertNull( InstallmentCalculator::calculate( 100.0, 'BGN' ) );
    }
}
