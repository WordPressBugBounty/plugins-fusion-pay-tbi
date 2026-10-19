<?php

namespace FusionPayTbi\Tests;

/**
 * Regression test for the exact WP.org automated-review rejection this project already
 * hit once ("Fixed mismatched Stable tag in readme.txt (WordPress.org automated check)",
 * see readme.txt changelog under `= 3.3 =`): the plugin version must be declared
 * identically in all three places WordPress.org's review tooling (and WordPress core
 * itself) reads it from:
 *
 *  1. fusion-pay-tbi.php   - the plugin header's `Version:` line.
 *  2. src/Bootstrap.php    - the `FUSIPATB_PLUGIN_VERSION` constant default.
 *  3. readme.txt           - the `Stable tag:` line.
 *
 * This deliberately reads the real source files from disk via file_get_contents()
 * rather than asserting on the FUSIPATB_PLUGIN_VERSION constant: tests/bootstrap.php
 * unconditionally pre-defines that constant as '0.0.0-test' (see its comment) so the
 * rest of the suite never depends on the plugin's real released version. Reading the
 * constant here would therefore only ever see '0.0.0-test', not the real value, and
 * could never catch a mismatch between the three files.
 */
final class PluginVersionConsistencyTest extends TestCase {

    public function test_plugin_header_bootstrap_constant_and_readme_stable_tag_all_match(): void {
        $plugin_file_path = realpath( __DIR__ . '/../fusion-pay-tbi.php' );
        $bootstrap_path   = realpath( __DIR__ . '/../src/Bootstrap.php' );
        $readme_path      = realpath( __DIR__ . '/../readme.txt' );

        $this->assertNotFalse( $plugin_file_path, 'fusion-pay-tbi.php must exist relative to the tests directory.' );
        $this->assertNotFalse( $bootstrap_path, 'src/Bootstrap.php must exist relative to the tests directory.' );
        $this->assertNotFalse( $readme_path, 'readme.txt must exist relative to the tests directory.' );

        $plugin_file_contents = file_get_contents( $plugin_file_path );
        $bootstrap_contents   = file_get_contents( $bootstrap_path );
        $readme_contents      = file_get_contents( $readme_path );

        $this->assertMatchesRegularExpression(
            '/^Version:\s*(\S+)/m',
            $plugin_file_contents,
            'fusion-pay-tbi.php must declare a `Version:` header line.'
        );
        preg_match( '/^Version:\s*(\S+)/m', $plugin_file_contents, $plugin_header_match );
        $plugin_header_version = $plugin_header_match[1];

        $this->assertMatchesRegularExpression(
            '/define\(\s*\'FUSIPATB_PLUGIN_VERSION\',\s*\'([^\']+)\'\s*\)/',
            $bootstrap_contents,
            'src/Bootstrap.php must declare `define( \'FUSIPATB_PLUGIN_VERSION\', \'...\' )`.'
        );
        preg_match( '/define\(\s*\'FUSIPATB_PLUGIN_VERSION\',\s*\'([^\']+)\'\s*\)/', $bootstrap_contents, $bootstrap_match );
        $bootstrap_version = $bootstrap_match[1];

        $this->assertMatchesRegularExpression(
            '/^Stable tag:\s*(\S+)/m',
            $readme_contents,
            'readme.txt must declare a `Stable tag:` line.'
        );
        preg_match( '/^Stable tag:\s*(\S+)/m', $readme_contents, $readme_match );
        $readme_stable_tag = $readme_match[1];

        $this->assertSame(
            $plugin_header_version,
            $bootstrap_version,
            "fusion-pay-tbi.php Version ({$plugin_header_version}) must match Bootstrap.php's FUSIPATB_PLUGIN_VERSION ({$bootstrap_version})."
        );
        $this->assertSame(
            $plugin_header_version,
            $readme_stable_tag,
            "fusion-pay-tbi.php Version ({$plugin_header_version}) must match readme.txt's Stable tag ({$readme_stable_tag})."
        );
    }
}
