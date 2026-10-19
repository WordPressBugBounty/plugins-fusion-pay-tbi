<?php

namespace FusionPayTbi\Tests\Helpers;

use FusionPayTbi\Helpers\Cryptor;
use FusionPayTbi\Tests\TestCase;

/**
 * Behaviour: on a host without the PHP openssl extension, Cryptor's constructor
 * must fail with the same clean \Exception it already throws for an unsupported
 * cipher/hash algo (src/Helpers/Cryptor.php lines 30-35), not fatal with
 * "call to undefined function openssl_get_cipher_methods()" the first time a
 * financing request is encrypted.
 *
 * Real PHP has no way to uninstall the openssl extension for a single test, so
 * this pins the guard via a namespaced extension_loaded() override declared in
 * tests/FusipatbHelpersFunctionOverrides.php (FusionPayTbi\Helpers namespace),
 * which Cryptor.php's unqualified extension_loaded() call resolves to first.
 */
final class CryptorOpensslGuardTest extends TestCase {

    public function test_constructor_throws_when_openssl_extension_is_not_available(): void {
        $GLOBALS['__fusipatb_test_extension_loaded_override'] = false;

        $this->expectException( \Exception::class );
        $this->expectExceptionMessage( 'Cryptor: the PHP openssl extension is required but not available.' );

        new Cryptor();
    }

    public function test_constructor_still_succeeds_when_openssl_extension_is_available(): void {
        $GLOBALS['__fusipatb_test_extension_loaded_override'] = true;

        $cryptor = new Cryptor();

        $this->assertInstanceOf( Cryptor::class, $cryptor );
    }
}
