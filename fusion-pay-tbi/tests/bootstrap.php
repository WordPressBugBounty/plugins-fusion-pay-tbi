<?php
/**
 * Minimal WordPress/WooCommerce stand-ins for the fusion-pay-tbi PHPUnit suite.
 *
 * Scope: only the handful of WP/WC functions actually reached by the code paths under
 * test (nonce verification in CartProcessor/InstallmentCalculator/SessionDataHandler,
 * and Bootstrap::migrate_legacy_options()). This is deliberately NOT a full WordPress
 * test scaffold (no wp-phpunit, no real DB) — see fusion-pay-tbi/tests/*Test.php for
 * the exact boundary each test stops at.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/FusipatbHelpersFunctionOverrides.php';

if ( ! defined( 'ABSPATH' ) ) {
    // Source files only guard `if ( ! defined( 'ABSPATH' ) ) exit;` — any truthy path works.
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'DOING_AJAX' ) ) {
    // CartProcessor::handle() short-circuits unless this is true; every test in this
    // suite exercises code that runs only during a real AJAX request.
    define( 'DOING_AJAX', true );
}

if ( ! defined( 'FUSIPATB_API_LOCAL_ALLOWED_ORIGIN' ) ) {
    // Used by SecurityHelper::isLocalOriginAllowed(), the gate immediately after the
    // nonce check in CartProcessor::handle(). Fixed value so tests can assert whether
    // a request passed the nonce gate and reached this one, without needing an origin.
    define( 'FUSIPATB_API_LOCAL_ALLOWED_ORIGIN', 'https://shop.example.test' );
}

if ( ! defined( 'FUSIPATB_API_GATEWAY_ALLOWED_ORIGIN' ) ) {
    // Used by SecurityHelper::isOriginAllowed() (REST gateway origin allowlist).
    // Fixed value, distinct from the local-AJAX origin above, for SecurityHelperTest.
    define( 'FUSIPATB_API_GATEWAY_ALLOWED_ORIGIN', 'https://platform.example.test' );
}

if ( ! defined( 'FUSIPATB_API_WOO_SETTINGS' ) ) {
    // SettingsSaver::callSettingsApi() posts here; wp_remote_post() is stubbed
    // below so this never makes a real request, but the URL constant must exist.
    define( 'FUSIPATB_API_WOO_SETTINGS', 'https://platform.example.test/api/WooSettings2' );
}

if ( ! defined( 'FUSIPATB_API_WOO_SETTINGS_STATUS' ) ) {
    // SettingsSaver::pingStatus() posts here.
    define( 'FUSIPATB_API_WOO_SETTINGS_STATUS', 'https://platform.example.test/api/PluginStatusUpdate' );
}

if ( ! defined( 'FUSIPATB_PLUGIN_VERSION' ) ) {
    // Read by SettingsSaver::pingStatus().
    define( 'FUSIPATB_PLUGIN_VERSION', '0.0.0-test' );
}

/**
 * Thrown by the wp_send_json_* stubs below to halt execution the same way the real
 * WordPress implementations do (they call wp_die() internally, which never returns).
 * Tests catch this and assert on ->type / ->data / ->status instead of needing every
 * downstream WP/WC call the handler never reaches in the failure paths to be stubbed.
 */
class WpAjaxResponseException extends \Exception {
    /** @var string 'error'|'success' */
    public $type;
    /** @var mixed */
    public $data;
    /** @var int|null */
    public $status;

    public function __construct( $type, $data, $status ) {
        $this->type   = $type;
        $this->data   = $data;
        $this->status = $status;
        parent::__construct( sprintf( 'wp_send_json_%s called', $type ) );
    }
}

function wp_send_json_error( $data = null, $status_code = null ) {
    throw new WpAjaxResponseException( 'error', $data, $status_code );
}

function wp_send_json_success( $data = null, $status_code = null ) {
    throw new WpAjaxResponseException( 'success', $data, $status_code );
}

/**
 * Deterministic nonce verification: a nonce is "valid" iff it equals
 * "valid-nonce-for-{$action}". Tests only ever need to control $_POST['_wpnonce'],
 * never this function, to move between the pass/fail branches.
 */
function wp_verify_nonce( $nonce, $action = -1 ) {
    return ( $nonce === ( 'valid-nonce-for-' . $action ) ) ? 1 : false;
}

function sanitize_text_field( $value ) {
    if ( ! is_string( $value ) ) {
        return $value;
    }
    return trim( strip_tags( $value ) );
}

function wp_unslash( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'wp_unslash', $value );
    }
    return is_string( $value ) ? stripslashes( $value ) : $value;
}

function esc_url_raw( $url ) {
    return is_string( $url ) ? trim( $url ) : '';
}

function absint( $value ) {
    return abs( (int) $value );
}

/**
 * Minimal WP i18n/escaping stand-ins needed by StatusColumnTest (StatusColumn
 * renders translated, escaped status labels via the real country configs).
 * No translation/escaping behaviour is under test here, so these are
 * deliberately pass-through/simple.
 */
function __( $text, $domain = 'default' ) {
    return $text;
}

function esc_html( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * Deterministic admin-referer check for SettingsSaver::save(), which gates its
 * whole body on this before touching $_POST. Controllable via
 * $GLOBALS['__fusipatb_test_check_admin_referer_result'] (defaults to true) so
 * tests can also exercise the "nonce fails" early-return path if ever needed.
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
    return array_key_exists( '__fusipatb_test_check_admin_referer_result', $GLOBALS )
        ? $GLOBALS['__fusipatb_test_check_admin_referer_result']
        : true;
}

/**
 * Minimal stand-in for WordPress's global WP_Error class.
 */
class WP_Error {
    private $code;
    private $message;

    public function __construct( $code = '', $message = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_code() {
        return $this->code;
    }
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

/**
 * Stubbed HTTP POST: never makes a real network call. Controllable via
 * $GLOBALS['__fusipatb_test_wp_remote_post_response'] (defaults to a WP_Error,
 * matching "the settings API is unreachable in tests" for SettingsSaver/
 * PaymentGateway callers that don't set a specific response). Every call is
 * recorded in $GLOBALS['__fusipatb_test_wp_remote_post_calls'] so tests can
 * assert on the outgoing request body/url if needed.
 */
function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['__fusipatb_test_wp_remote_post_calls'][] = array(
        'url'  => $url,
        'args' => $args,
    );
    return array_key_exists( '__fusipatb_test_wp_remote_post_response', $GLOBALS )
        ? $GLOBALS['__fusipatb_test_wp_remote_post_response']
        : new WP_Error( 'http_request_failed', 'stubbed: no network access in tests' );
}

$GLOBALS['__fusipatb_test_options'] = array();

function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['__fusipatb_test_options'] )
        ? $GLOBALS['__fusipatb_test_options'][ $name ]
        : $default;
}

function update_option( $name, $value ) {
    $GLOBALS['__fusipatb_test_options'][ $name ] = $value;
    return true;
}

function delete_option( $name ) {
    unset( $GLOBALS['__fusipatb_test_options'][ $name ] );
    return true;
}

/**
 * Minimal WC()->session double — only the two methods SessionDataHandler::handle() calls.
 */
class FusipatbTestWcSession {
    private $data = array();

    public function get( $key, $default = null ) {
        return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
    }

    public function set( $key, $value ) {
        $this->data[ $key ] = $value;
    }
}

class FusipatbTestWc {
    /** @var FusipatbTestWcSession */
    public $session;

    /**
     * @var FusipatbTestWcCart|null Null by default (matches "no cart in this
     * request" for tests that never touch it); CartButtonTest assigns a
     * FusipatbTestWcCart double before calling CartButton::render()/getBlocksData().
     */
    public $cart;

    public function __construct() {
        $this->session = new FusipatbTestWcSession();
        $this->cart    = null;
    }
}

function WC() {
    if ( ! isset( $GLOBALS['__fusipatb_test_wc'] ) ) {
        $GLOBALS['__fusipatb_test_wc'] = new FusipatbTestWc();
    }
    return $GLOBALS['__fusipatb_test_wc'];
}

/**
 * Minimal WP_REST_Request stand-in for RequestAuthenticatorTest: only the two
 * accessors RequestAuthenticator::authenticate() calls (get_header/get_body).
 * Headers are matched case-insensitively, as WP_REST_Request does.
 */
class WP_REST_Request {
    private $headers;
    private $body;

    public function __construct( array $headers = array(), $body = '' ) {
        $this->headers = array();
        foreach ( $headers as $name => $value ) {
            $this->headers[ strtolower( $name ) ] = $value;
        }
        $this->body = $body;
    }

    public function get_header( $name ) {
        $key = strtolower( $name );
        return array_key_exists( $key, $this->headers ) ? $this->headers[ $key ] : null;
    }

    public function get_body() {
        return $this->body;
    }
}

$GLOBALS['__fusipatb_test_transients'] = array();

function get_transient( $key ) {
    return array_key_exists( $key, $GLOBALS['__fusipatb_test_transients'] )
        ? $GLOBALS['__fusipatb_test_transients'][ $key ]
        : false;
}

function set_transient( $key, $value, $expiration = 0 ) {
    $GLOBALS['__fusipatb_test_transients'][ $key ] = $value;
    return true;
}

function delete_transient( $key ) {
    unset( $GLOBALS['__fusipatb_test_transients'][ $key ] );
    return true;
}

/**
 * Minimal WC_Order double for StatusColumnTest: only get_meta(), matching
 * what StatusColumn::output()/renderLegacy() actually call. Named WC_Order
 * (global namespace, no `class WC_Order` exists elsewhere in this stub) so
 * StatusColumn's `is_a( $order, 'WC_Order' )` guard passes in tests.
 */
class WC_Order {
    private $meta;

    public function __construct( array $meta = array() ) {
        $this->meta = $meta;
    }

    public function get_meta( $key ) {
        return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
    }
}

/**
 * Controllable wc_get_order()/get_post_meta() doubles for StatusColumnTest.
 * wc_get_order() returns whatever WC_Order (or false) a test registers under
 * an order id; get_post_meta() reads from a separate flat post-meta store so
 * the legacy (non-HPOS) fallback path in StatusColumn::renderLegacy() can be
 * exercised independently of the order's own get_meta().
 */
$GLOBALS['__fusipatb_test_wc_orders']    = array();
$GLOBALS['__fusipatb_test_post_meta']    = array();

function wc_get_order( $id ) {
    return isset( $GLOBALS['__fusipatb_test_wc_orders'][ $id ] )
        ? $GLOBALS['__fusipatb_test_wc_orders'][ $id ]
        : false;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
    if ( ! isset( $GLOBALS['__fusipatb_test_post_meta'][ $post_id ][ $key ] ) ) {
        return $single ? '' : array();
    }
    return $GLOBALS['__fusipatb_test_post_meta'][ $post_id ][ $key ];
}

/**
 * Stubs added for WooCommerceRequirementTest (fusion-pay-tbi.php's "WooCommerce
 * inactive" admin-notice fix). Deliberately minimal, matching the rest of this
 * harness: only what WooCommerceRequirement::isWooCommerceActive()/notifyMissing()/
 * renderMissingNotice() actually call.
 */

/**
 * Controllable via $GLOBALS['__fusipatb_test_network_active_plugins'] (defaults to
 * none network-active) so tests can exercise the network-activation branch.
 */
function is_plugin_active_for_network( $plugin ) {
    return array_key_exists( '__fusipatb_test_network_active_plugins', $GLOBALS )
        && in_array( $plugin, $GLOBALS['__fusipatb_test_network_active_plugins'], true );
}

/**
 * Pass-through stand-in: the only filter this codebase applies through it in a
 * tested path ('active_plugins') has no registered callbacks in tests, so
 * returning $value unchanged matches real WordPress behaviour there.
 */
function apply_filters( $tag, $value ) {
    return $value;
}

/**
 * Records every call instead of actually hooking anything, so tests can assert
 * on what a method registered without a real WP hook loop.
 */
$GLOBALS['__fusipatb_test_add_action_calls'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['__fusipatb_test_add_action_calls'][] = array(
        'hook'     => $hook,
        'callback' => $callback,
        'priority' => $priority,
    );
}

/**
 * Controllable via $GLOBALS['__fusipatb_test_current_user_can_result'] (defaults
 * to true), same convention as check_admin_referer() above.
 */
function current_user_can( $capability ) {
    return array_key_exists( '__fusipatb_test_current_user_can_result', $GLOBALS )
        ? $GLOBALS['__fusipatb_test_current_user_can_result']
        : true;
}

function esc_html__( $text, $domain = 'default' ) {
    return esc_html( __( $text, $domain ) );
}

/**
 * Stubs added for StatusPingConsentNoticeTest (WP.org review follow-up: dismissible
 * admin notice nudging merchants toward the status-ping consent checkbox).
 */

function esc_url( $url ) {
    return is_string( $url ) ? trim( $url ) : '';
}

/**
 * Deterministic admin_url(): just prefixes the path onto a fixed fake admin base,
 * matching the "fixed test value" convention used for the other URL constants
 * above -- tests only need to assert the path fragment is present, not a real URL.
 */
function admin_url( $path = '' ) {
    return 'https://shop.example.test/wp-admin/' . ltrim( $path, '/' );
}

/**
 * Deterministic nonce creation, paired with wp_verify_nonce() above: always
 * "valid-nonce-for-{$action}" so tests can drive both the render path (which
 * embeds the nonce) and the AJAX dismiss handler (which verifies it) without
 * needing to know a real WP nonce algorithm.
 */
function wp_create_nonce( $action = -1 ) {
    return 'valid-nonce-for-' . $action;
}

/**
 * $pagenow is a real WordPress global (set by wp-admin/admin.php on every
 * request); reset to an empty string here so no test leaks a screen value into
 * the next one, mirroring the superglobal reset in TestCase::setUp().
 */
$GLOBALS['pagenow'] = '';

/**
 * Stubs added to characterize the "Button period" tier-default fix
 * (fusipatb_tier1/2/3 now fall back to $config->getMaxMonths()/getMaxMonths()/
 * getMaxMonthsHigh() instead of getMinMonths()/getMinMonths()/getMaxMonths())
 * across AdminPage::render(), ProductButton::render() and CartButton's two
 * near-identical methods. These four call sites are only reachable through
 * their real, unrefactored render paths, so this section fills in the handful
 * of WP/WC functions those paths touch that no earlier test needed yet.
 * Deliberately minimal/pass-through, matching the rest of this harness.
 */

if ( ! defined( 'FUSIPATB_CDN_URL' ) ) {
    define( 'FUSIPATB_CDN_URL', 'https://cdn.example.test/' );
}
if ( ! defined( 'FUSIPATB_BANK_LOGO' ) ) {
    define( 'FUSIPATB_BANK_LOGO', 'logo/tbi-bank.svg' );
}
if ( ! defined( 'FUSIPATB_BANK_LOGO_WHITE' ) ) {
    define( 'FUSIPATB_BANK_LOGO_WHITE', 'logo/tbi-bank-white.svg' );
}
if ( ! defined( 'FUSIPATB_BANK_LOGO_LIGHT' ) ) {
    define( 'FUSIPATB_BANK_LOGO_LIGHT', 'logo/tbi-bank-light.svg' );
}
if ( ! defined( 'FUSIPATB_PLATFORM_URL' ) ) {
    // Read unconditionally by CurrencyHelper::getApiUrl() (called from
    // AdminPage::render()'s dual-currency branch even when reseller
    // credentials are unset), so it must exist regardless of force_env.
    define( 'FUSIPATB_PLATFORM_URL', 'https://platform.example.test' );
}

function wc_get_order_statuses() {
    return array(
        'wc-pending'    => 'Pending payment',
        'wc-processing' => 'Processing',
        'wc-completed'  => 'Completed',
    );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
    $field = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce( $action ) . '">';
    if ( $echo ) {
        echo $field;
    }
    return $field;
}

function esc_attr_e( $text, $domain = 'default' ) {
    echo esc_attr( __( $text, $domain ) );
}

function esc_html_e( $text, $domain = 'default' ) {
    echo esc_html( __( $text, $domain ) );
}

/**
 * Pass-through stand-in: no HTML-filtering behaviour is under test at any of
 * this stub's call sites (AdminPage/ProductButton/CartButton render paths).
 */
function wp_kses_post( $content ) {
    return $content;
}

/**
 * Halts execution the same way the real wp_die() does (never returns), so a
 * test that reaches an unexpected wp_die() fails loudly instead of the render
 * path silently continuing.
 */
function wp_die( $message = '' ) {
    throw new \RuntimeException( 'wp_die called: ' . ( is_scalar( $message ) ? (string) $message : '' ) );
}

/**
 * Controllable via $GLOBALS['__fusipatb_test_is_product'] (defaults to true)
 * so ProductButtonTest can exercise the "not a product page" early return too.
 */
function is_product() {
    return array_key_exists( '__fusipatb_test_is_product', $GLOBALS )
        ? $GLOBALS['__fusipatb_test_is_product']
        : true;
}

function wc_get_price_including_tax( $product ) {
    return method_exists( $product, 'get_price_including_tax' ) ? (float) $product->get_price_including_tax() : 0.0;
}

function wc_get_price_excluding_tax( $product ) {
    return method_exists( $product, 'get_price_excluding_tax' ) ? (float) $product->get_price_excluding_tax() : 0.0;
}

function wc_get_coupon_id_by_code( $code ) {
    return 0;
}

function wp_add_inline_script( $handle, $data, $position = 'after' ) {
    return true;
}

function get_the_ID() {
    return 123;
}

function get_permalink( $post = 0 ) {
    return 'https://shop.example.test/product/dummy-product/';
}

function esc_js( $text ) {
    return addslashes( (string) $text );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
    return json_encode( $data, $options, $depth );
}

/**
 * Minimal WC_Product double for ProductButtonTest: only what
 * ProductButton::render() calls on the global $product (is_type(),
 * has_child(), get_available_variations(), plus get_price_including_tax()
 * consumed via the wc_get_price_including_tax() stub above).
 */
class FusipatbTestWcProduct {
    private $type;
    private $price;
    private $hasChild;

    public function __construct( $type = 'simple', $price = 0.0, $hasChild = false ) {
        $this->type     = $type;
        $this->price    = $price;
        $this->hasChild = $hasChild;
    }

    public function is_type( $type ) {
        return $this->type === $type;
    }

    public function get_price_including_tax() {
        return $this->price;
    }

    public function get_price_excluding_tax() {
        return $this->price;
    }

    public function has_child() {
        return $this->hasChild;
    }

    public function get_available_variations() {
        return array();
    }
}

/**
 * Minimal WC_Cart double for CartButtonTest: only what CartButton::render()/
 * getBlocksData() call. `total` backs get_total('edit'); the other properties
 * are read directly by the real code (WC()->cart->cart_contents_total etc.),
 * matching WooCommerce's own public-property access pattern.
 */
class FusipatbTestWcCart {
    public $cart_contents_total = 0.0;
    public $cart_contents_tax   = 0.0;
    public $total               = 0.0;
    private $shippingTotal;
    private $shippingTax;
    private $discountTotal;
    private $discountTax;
    private $items;
    private $appliedCoupons;

    public function __construct(
        $shippingTotal = 0.0,
        $shippingTax = 0.0,
        $discountTotal = 0.0,
        $discountTax = 0.0,
        array $items = array(),
        array $appliedCoupons = array()
    ) {
        $this->shippingTotal  = $shippingTotal;
        $this->shippingTax    = $shippingTax;
        $this->discountTotal  = $discountTotal;
        $this->discountTax    = $discountTax;
        $this->items          = $items;
        $this->appliedCoupons = $appliedCoupons;
    }

    public function calculate_totals() {
    }

    public function get_shipping_total() {
        return $this->shippingTotal;
    }

    public function get_shipping_tax() {
        return $this->shippingTax;
    }

    public function get_total( $context = '' ) {
        return $this->total;
    }

    public function get_discount_total() {
        return $this->discountTotal;
    }

    public function get_discount_tax() {
        return $this->discountTax;
    }

    public function get_cart() {
        return $this->items;
    }

    public function get_applied_coupons() {
        return $this->appliedCoupons;
    }
}
