<?php

namespace FusionPayTbi\Tests;

use FusionPayTbi\Compat\TmEpoSupport;

/**
 * Characterizes TmEpoSupport::extractOptionNames() (src/Compat/TmEpoSupport.php,
 * around lines 193-207) — the WP.org plugin-review fix for unsanitized TM EPO
 * checkbox input.
 *
 * Bug: raw $_POST['tmcp_checkbox_*'] values were used as-is (only trim()'d and
 * run through a price-index regex) before being joined into the return value.
 * That value flows into ProductProcessor::handle() (appended to the product
 * name) and is eventually JSON-encoded/encrypted and POSTed to tbi bank's
 * application-registration API, so unsanitized attacker-controlled text (e.g.
 * HTML/script tags) could ride along in that payload.
 *
 * Fix: sanitize each raw value with wp_unslash() + sanitize_text_field() before
 * use, matching this codebase's existing convention (see e.g.
 * src/Api/ProductProcessor.php, src/Admin/SettingsSaver.php).
 */
final class TmEpoSupportExtractOptionNamesTest extends TestCase {

    public function test_extracts_a_normal_label_stripping_the_trailing_price_index(): void {
        $_POST['tmcp_checkbox_1_0'] = 'Gift wrap_0';

        $this->assertSame( 'Gift wrap', TmEpoSupport::extractOptionNames() );
    }

    public function test_sanitizes_html_script_content_out_of_the_raw_value(): void {
        $_POST['tmcp_checkbox_1_0'] = '<script>alert(1)</script>Gift wrap_0';

        $result = TmEpoSupport::extractOptionNames();

        $this->assertStringNotContainsString( '<script>', $result );
        $this->assertStringNotContainsString( '</script>', $result );
        $this->assertSame( 'alert(1)Gift wrap', $result );
    }

    public function test_joins_multiple_selected_options_with_comma_space(): void {
        $_POST['tmcp_checkbox_1_0'] = 'Gift wrap_0';
        $_POST['tmcp_checkbox_1_1'] = 'Express delivery_1';

        $result = TmEpoSupport::extractOptionNames();

        $this->assertSame( 'Gift wrap, Express delivery', $result );
    }

    public function test_ignores_post_fields_that_are_not_tmcp_checkbox_fields(): void {
        $_POST['tmcp_checkbox_1_0'] = 'Gift wrap_0';
        $_POST['unrelated_field']   = '<b>ignored</b>';

        $this->assertSame( 'Gift wrap', TmEpoSupport::extractOptionNames() );
    }

    public function test_returns_empty_string_when_no_tmcp_checkbox_fields_are_present(): void {
        $this->assertSame( '', TmEpoSupport::extractOptionNames() );
    }
}
