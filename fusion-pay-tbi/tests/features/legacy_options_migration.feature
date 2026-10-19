Feature: One-time migration of legacy unprefixed plugin options
  WordPress.org Plugin Check flagged unprefixed option keys (bnpl_min_amount,
  bnpl_max_amount, etc.) as a namespace-collision risk with the other 60,000+
  plugins in the directory. Bootstrap::migrate_legacy_options() copies each
  legacy value to its fusipatb_-prefixed replacement exactly once per site, so
  existing merchant settings survive the rename instead of silently reverting
  to defaults.

  Each scenario below is exercised as a PHPUnit characterization test in
  tests/BootstrapMigrateLegacyOptionsTest.php, invoking the private method via
  reflection against the real Bootstrap class and its real legacy_option_map.

  Background:
    Given the "fusipatb_legacy_options_migrated" flag option is not yet set

  Scenario Outline: A legacy option with a stored value is copied to its prefixed key
    Given the legacy option "<old_key>" is set to "<value>"
    And the prefixed option "<new_key>" has no value
    When Bootstrap::migrate_legacy_options runs
    Then the prefixed option "<new_key>" is set to "<value>"

    Examples:
      | old_key             | new_key                      | value |
      | bnpl_min_amount      | fusipatb_bnpl_min_amount      | 100   |
      | bnpl_max_amount      | fusipatb_bnpl_max_amount      | 5000  |
      | bnpl_min_amount_eur  | fusipatb_bnpl_min_amount_eur  | 50    |
      | bnpl_analysis_fee    | fusipatb_bnpl_analysis_fee    | 2.5   |
      | tier_currency        | fusipatb_tier_currency        | BGN   |

  Scenario: A legacy option with no stored value is not copied
    Given the legacy option "bnpl_min_amount" has no value
    And the prefixed option "fusipatb_bnpl_min_amount" has no value
    When Bootstrap::migrate_legacy_options runs
    Then the prefixed option "fusipatb_bnpl_min_amount" still has no value

  Scenario: A prefixed option that already has a value is never overwritten
    Given the legacy option "bnpl_min_amount" is set to "100"
    And the prefixed option "fusipatb_bnpl_min_amount" is already set to "999"
    When Bootstrap::migrate_legacy_options runs
    Then the prefixed option "fusipatb_bnpl_min_amount" is still set to "999"

  Scenario: The migration only runs once per site
    Given the legacy option "bnpl_min_amount" is set to "100"
    And Bootstrap::migrate_legacy_options has already run once, copying the value
    When the legacy option "bnpl_min_amount" is later changed to "200"
    And Bootstrap::migrate_legacy_options runs again
    Then the prefixed option "fusipatb_bnpl_min_amount" is still set to "100"
    And the "fusipatb_legacy_options_migrated" flag option is set to "1"
