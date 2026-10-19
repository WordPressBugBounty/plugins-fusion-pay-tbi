=== Fusion Pay by tbi bank ===
Contributors: tbibank, gbizimtbibank
Tags: woocommerce, payments, installments, bnpl, financing
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 3.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offer tbi bank installment and BNPL payments in WooCommerce with financing options on product, cart, and checkout pages.

== Description ==

Fusion Pay by tbi bank adds tbi bank financing to WooCommerce stores, letting eligible customers apply for installment or Buy Now, Pay Later payments during the shopping journey.

The integration is designed for merchants working with tbi bank and supports the full purchase flow, from product discovery to checkout.

Key features:

* tbi bank payment gateway for WooCommerce checkout
* Financing buttons on product and cart pages
* Installment calculator popup for available payment plans
* Support for installment loans and BNPL flows
* Multi-country setup for Bulgaria, Romania, and Greece
* Country-specific configuration and feature flags
* API-based merchant credential validation and status sync
* WooCommerce Blocks checkout compatibility
* Compatibility with selected WooCommerce product option and bundle extensions

This plugin requires an active WooCommerce installation and valid tbi bank merchant credentials.

== Installation ==

1. Upload the `fusion-pay-tbi` folder to the `/wp-content/plugins/` directory.
2. Activate **Fusion Pay by tbi bank** through the **Plugins** screen in WordPress.
3. Make sure WooCommerce is installed and active.
4. Open the **Fusion Pay** settings page in WordPress admin.
5. Enter the merchant credentials provided by tbi bank.
6. Save the settings and confirm the connection is active.

== Frequently Asked Questions ==

= Who can use this plugin? =

Merchants who use WooCommerce and have an active commercial relationship with tbi bank.

= Does it require WooCommerce? =

Yes. WooCommerce must be installed and active for the plugin to work.

= Which payment flows are supported? =

Depending on country and merchant setup, the plugin supports installment financing and Buy Now, Pay Later flows.

= Which countries are supported? =

The plugin includes support for Bulgaria, Romania, and Greece, with country-specific configuration and availability.

= Does it work with the WooCommerce block checkout? =

Yes. The plugin includes WooCommerce Blocks checkout support.

== External Services ==

This plugin communicates with the following external services.

= tbi Bank API =

Used to validate merchant credentials, fetch installment configuration, register financing applications, and report plugin status to tbi bank.

Data sent: store URL, merchant ID, merchant key, cart and order details (product IDs, quantities, amounts), plugin version, and country/currency context.

Triggered on: settings save, product/cart/checkout page load for eligible merchants, and order placement.

* Service URL: https://tbibank.bg
* Terms of Service: https://tbibank.com/terms-and-conditions/
* Privacy Policy: https://tbibank.bg/legal/privacy-policy

= tbi CDN =

Used to load the tbi bank logo image displayed on product, cart, checkout, and admin settings pages.

Data sent: standard browser HTTP request — no personal data is transmitted.

Triggered on: any page where the financing button or payment gateway is displayed.

* CDN URL: https://cdn.tbibankgroup.com
* Terms of Service: https://tbibank.com/terms-and-conditions/
* Privacy Policy: https://tbibank.bg/legal/privacy-policy

= Google Fonts =

Used to load the "Inter" webfont for the checkout page styling.

Data sent: standard browser HTTP request to Google's font servers, including the visitor's IP address, as part of loading the font file.

Triggered on: any WooCommerce checkout page where the plugin's styling is active.

* Service URL: https://fonts.googleapis.com
* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

== Changelog ==

= 3.3 =
* Removed the bundled SuisseIntl font files (unused, and not licensed for redistribution); the plugin already uses the GPL-compatible "Inter" webfont for checkout styling.
* Disclosed the Google Fonts request in the External Services section of this readme.
* Fixed mismatched Stable tag in readme.txt (WordPress.org automated check).
* Added nonce verification to the cart, installment calculator, and session-data AJAX handlers.
* Renamed remaining unprefixed options, functions, and hooks to the `fusipatb_` prefix, with a one-time migration of existing merchant settings.
* Replaced the open `permission_callback` on the `/api/tbi/v1/tbi-api-gateway` REST route with signed-request authentication: callers must provide a timestamp, a nonce, and an HMAC-SHA256 signature over the request; requests outside the clock-skew window, with an invalid signature, or with an already-used nonce (replay) are rejected before decryption. The Origin allowlist and IP allowlist remain as additional defense-in-depth layers.
* Fixed the activation/deactivation status ping incorrectly firing on a fresh install with no merchant credentials configured yet (a `false`-vs-`''` comparison bug meant the "no credentials" guard never triggered), so the plugin no longer contacts the tbi bank status endpoint before the merchant has entered credentials.
* Sanitized TM Global Extra Product Options checkbox labels before they are appended to the product name and sent in the financing payload.
* The plugin now checks for WooCommerce before loading its autoloader/bootstrap and shows a dismissible admin notice (instead of a fatal error) when WooCommerce is missing or inactive.



= 3.2.1 =
* Update the flexible fusion pay logic to properly cehck for qty changes on page.


= 3.2 =
* Updated references from `TBI_TEST_ENV` to `FUSIPATB_TEST_ENV` and `TBI_PLATFORM_URL` to `FUSIPATB_PLATFORM_URL` across all country configs.
* Replaced `TBI_ADVERTISEMENT_PAGE` and `TBI_WC_PLUGIN_PATH` with FUSIPATB equivalents in `Advertisement` and `BlocksCartIntegration`.
* Updated logo and CDN references in `CartButton` and `ProductButton`.
* Refactored `FrontendAssets` and `Popup` to use FUSIPATB paths.
* Adjusted `BlocksGateway` and `PaymentGateway` for FUSIPATB constants.
* Enhanced `SecurityHelper` for IP validation and origin checks.
* Cleaned up `StatusColumn` and `uninstall.php`.
* Bug fix for Romania.

= 3.1 =
* Added WooCommerce Blocks cart support: TBI installment button now renders inside Blocks-based cart themes via `BlocksCartIntegration`.
* Added add-to-cart handling for Romania (RO) in CartProcessor and ProductProcessor.
* WordPress.org plugin review compliance: prefixed AJAX action slugs (`fusipatb_*`), sanitized `$_POST`/`$_COOKIE`/`$_SERVER` inputs, replaced inline `<script>` with `wp_add_inline_script()`.
* Renamed gateway classes to `Fusipatb_PaymentGateway` and `Fusipatb_BlocksGateway`; backward-compat aliases retained for one release cycle.
* Removed `build/build-branch-zips.sh` (not permitted on WordPress.org).

= 3.0 =
* Added the current Fusion Pay by tbi bank release documentation and WordPress.org metadata.
* Supports WooCommerce financing flows for tbi bank merchants.

== Upgrade Notice ==

= 3.1 =
Update to 3.1 for WooCommerce Blocks cart support, Romania add-to-cart handling, WordPress.org compliance fixes, and improved input sanitization.

= 3.0 =
Update to the latest Fusion Pay by tbi bank release for the current WooCommerce integration, financing flow support, and metadata cleanup.
