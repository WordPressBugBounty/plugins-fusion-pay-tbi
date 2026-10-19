Feature: Plugin Check round-2 fixes (CKT-3830) -- unslash-before-sanitize and debug-only logging
  A second WordPress.org Plugin Check pass flagged two more issues across the
  fusion-pay-tbi codebase:

  1. Several `sanitize_text_field( $_POST[...] )` calls were missing
     `wp_unslash()`. WordPress runs `wp_magic_quotes()` on every superglobal,
     backslash-escaping quotes and backslashes before PHP ever sees them.
     `sanitize_text_field()` does NOT strip that escaping -- only
     `wp_unslash()` does. Without it, any posted value containing a quote or
     backslash (a customer's billing address, an apostrophe in a delivery
     city, a reseller key) would be persisted/transmitted with a stray
     backslash baked in.

  2. Several `error_log()` calls fired unconditionally in production,
     writing debug detail (request bodies, exception messages) to the PHP
     error log on every shopper request that hit an error branch. They are
     now gated behind `defined('WP_DEBUG') && WP_DEBUG`, matching the
     pattern already used in src/Frontend/ProductButton.php.

  The billing/shipping/currency part of fix (1), inside
  PaymentGateway::process_payment(), and all of fix (2) are not covered by a
  PHPUnit test in this suite: process_payment() requires a real WC_Order,
  WC_Payment_Gateway base class and WC()->cart double that this minimal WP/WC
  stub harness (tests/bootstrap.php) does not provide, and asserting on
  PHP's global error_log() would require mocking a language primitive rather
  than plugin behaviour. The reseller_code/reseller_key/encryption_key/
  tbi_default_status/tbi_button_placement/promo-id part of fix (1) IS covered
  directly: see tests/Admin/SettingsSaverTest.php. The origin-header part of
  fix (1) is also covered directly: see tests/Helpers/SecurityHelperTest.php.

  Background:
    Given the fusion-pay-tbi plugin is loaded for a WooCommerce store

  Scenario Outline: PaymentGateway::process_payment unslashes a magic-quoted billing/shipping field before sanitizing
    Given a magic-quoted POSTed "<field>" of "<magic_quoted_value>"
    When PaymentGateway::process_payment builds the application_array sent to the TBI platform
    Then the "<field>" value in application_array is "<expected_value>", with no stray backslash

    Examples:
      | field               | magic_quoted_value      | expected_value        |
      | billing_city         | Sofia\'s District       | Sofia's District       |
      | billing_address_1    | 12 O\'Brien St.         | 12 O'Brien St.         |
      | billing_address_2    | Apt \"5\"                | Apt "5"                |
      | billing_postcode     | 1000\\                  | 1000\                 |
      | billing_first_name   | Mary\'Ann               | Mary'Ann               |
      | billing_last_name    | O\'Connor               | O'Connor               |
      | billing_email        | o\'brien@example.test    | o'brien@example.test   |
      | billing_phone        | +359\\888\\123456        | +359\888\123456        |
      | shipping_city        | Plovdiv\'s Center        | Plovdiv's Center       |

  Scenario: PaymentGateway::process_payment unslashes the posted currency before validating it
    Given a magic-quoted POSTed "currency" of "EUR" (no escaping needed, but exercised through the same wp_unslash() call as the other fields)
    When PaymentGateway::process_payment resolves the order currency
    Then the 3-letter currency regex validates against the unslashed value, not a raw magic-quoted one

  Scenario Outline: A debug-only error_log call stays silent unless WP_DEBUG is enabled
    Given WP_DEBUG is not defined, or is defined and false
    When the "<component>" error branch described below is reached
    Then no call is made to PHP's error_log()

    Examples:
      | component                                                              |
      | CartProcessor::handle -- "FusionPayTbi cart scheme debug" branch        |
      | CartProcessor::handle -- "FusionPayTbi iframe payload" branch           |
      | ProductProcessor::handle -- "FusionPayTbi iframe payload" branch        |
      | StatusUpdater::update -- order-meta-update catch block                  |
      | WapfSupport::processFields -- catch block                              |
      | PaymentGateway::process_payment -- RegisterApplication failure branch   |

  Scenario Outline: The same error branch logs once WP_DEBUG is enabled
    Given WP_DEBUG is defined and true
    When the "<component>" error branch described above is reached
    Then error_log() is called exactly once with the component's debug message

    Examples:
      | component                                                              |
      | CartProcessor::handle -- "FusionPayTbi cart scheme debug" branch        |
      | CartProcessor::handle -- "FusionPayTbi iframe payload" branch           |
      | ProductProcessor::handle -- "FusionPayTbi iframe payload" branch        |
      | StatusUpdater::update -- order-meta-update catch block                  |
      | WapfSupport::processFields -- catch block                              |
      | PaymentGateway::process_payment -- RegisterApplication failure branch   |
