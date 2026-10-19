<?php
/**
 * Namespaced function override for CryptorOpensslGuardTest.
 *
 * PHP resolves an unqualified call to a global-namespace-looking function (e.g.
 * `extension_loaded(...)`) made from inside namespace X first against
 * `X\extension_loaded`, falling back to the real global function only if no such
 * namespaced function exists. Declaring `FusionPayTbi\Helpers\extension_loaded()`
 * here means the *unqualified* call inside src/Helpers/Cryptor.php (which lives in
 * that same namespace) resolves to this override instead of PHP's builtin -- the
 * standard PHPUnit pattern for mocking an unmockable builtin without extensions
 * like runkit/uopz. Must be declared in its own file: mixing this bracket-free
 * global code already in tests/bootstrap.php with a `namespace X;` declaration in
 * the same file is not allowed once any code precedes it.
 *
 * Controllable via $GLOBALS['__fusipatb_test_extension_loaded_override'] (bool);
 * when unset, defers to the real extension_loaded() so every other test is
 * unaffected.
 */

namespace FusionPayTbi\Helpers;

function extension_loaded( $name ) {
    if ( array_key_exists( '__fusipatb_test_extension_loaded_override', $GLOBALS ) ) {
        return $GLOBALS['__fusipatb_test_extension_loaded_override'];
    }
    return \extension_loaded( $name );
}
