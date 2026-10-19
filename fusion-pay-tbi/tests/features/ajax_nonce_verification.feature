Feature: AJAX nonce verification for fusion-pay-tbi endpoints
  As flagged by the WordPress.org Plugin Check review (CKT-3830), every AJAX
  handler that mutates state or triggers a server-side call to the TBI/BNPL
  platform on behalf of a shopper must verify a WordPress nonce before doing
  any work, and must fail closed with a 403 response when the nonce is
  missing or invalid. This complements (does not replace) the current-user
  origin/permission checks each handler already performs.

  Each scenario below is exercised as a PHPUnit characterization test in
  tests/Api/*Test.php against the real handler classes.

  Background:
    Given the fusion-pay-tbi plugin is loaded for a WooCommerce store
    And DOING_AJAX is true, as it would be for a real admin-ajax.php request

  Scenario: Cart AJAX handler rejects a request with no nonce
    Given no "_wpnonce" field is present in the POST request
    When the shopper's browser calls the "fusipatb_process_cart" AJAX action handled by CartProcessor::handle
    Then the response is a JSON error with message "Security verification failed"
    And the HTTP status code is 403
    And no cart application is registered with the TBI platform

  Scenario: Cart AJAX handler rejects a request with a nonce that fails verification
    Given a "_wpnonce" field is present but does not verify against the "fusipatb_process_cart" action
    When the shopper's browser calls the "fusipatb_process_cart" AJAX action handled by CartProcessor::handle
    Then the response is a JSON error with message "Security verification failed"
    And the HTTP status code is 403

  Scenario: Cart AJAX handler accepts a request with a valid nonce
    Given a "_wpnonce" field that verifies against the "fusipatb_process_cart" action
    When the shopper's browser calls the "fusipatb_process_cart" AJAX action handled by CartProcessor::handle
    Then request handling proceeds past the nonce check into the origin-allowlist check
    And the nonce-failure message "Security verification failed" is never returned

  Scenario: Installment calculator AJAX handler rejects a request with no nonce
    Given no "_wpnonce" field is present in the POST request
    When the product or cart widget calls the "fusipatb_calculate_installments_ajax" AJAX action handled by InstallmentCalculator::handleAjax
    Then the response is a JSON error with message "Security verification failed"
    And the HTTP status code is 403
    And the TBI installment-calculation API is never called

  Scenario: Installment calculator AJAX handler rejects a request with an invalid nonce
    Given a "_wpnonce" field is present but does not verify against the "fusipatb_calculate_installments_ajax" action
    When the product or cart widget calls the "fusipatb_calculate_installments_ajax" AJAX action handled by InstallmentCalculator::handleAjax
    Then the response is a JSON error with message "Security verification failed"
    And the HTTP status code is 403

  Scenario: Installment calculator AJAX handler accepts a request with a valid nonce
    Given a "_wpnonce" field that verifies against the "fusipatb_calculate_installments_ajax" action
    And a POSTed "amount" of "0"
    When the product or cart widget calls the "fusipatb_calculate_installments_ajax" AJAX action handled by InstallmentCalculator::handleAjax
    Then request handling proceeds past the nonce check into the amount-validation check
    And the response is a JSON error with message "Invalid amount provided"

  Scenario: Session data AJAX handler rejects a request with no nonce
    Given no "_wpnonce" field is present in the POST request
    When the popup calls the "fusipatb_set_session_data" AJAX action handled by SessionDataHandler::handle
    Then the response is a JSON error with message "Security verification failed"
    And the HTTP status code is 403
    And the WooCommerce session is left untouched

  Scenario: Session data AJAX handler rejects a request with an invalid nonce
    Given a "_wpnonce" field is present but does not verify against the "fusipatb_set_session_data" action
    When the popup calls the "fusipatb_set_session_data" AJAX action handled by SessionDataHandler::handle
    Then the response is a JSON error with message "Security verification failed"
    And the HTTP status code is 403
    And the WooCommerce session is left untouched

  Scenario: Session data AJAX handler accepts a request with a valid nonce and stores the posted values
    Given a "_wpnonce" field that verifies against the "fusipatb_set_session_data" action
    And a POSTed "scheme_id" of "42"
    And a POSTed "downpayment" of "150.50"
    When the popup calls the "fusipatb_set_session_data" AJAX action handled by SessionDataHandler::handle
    Then the WooCommerce session key "tbi_scheme_id" is set to "42"
    And the WooCommerce session key "tbi_downpayment" is set to "150.50"
    And the response is a JSON success with message "Session updated"
