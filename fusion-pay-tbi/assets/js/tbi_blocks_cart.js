// tbi_blocks_cart.js — TBI installment button for the WooCommerce Blocks cart.
//
// Strategy: DOM injection + MutationObserver.
// This avoids depending on ExperimentalOrderMeta (unreliable across WC versions).
// PHP passes button data via window.wc.wcSettings ('tbi-cart-button_data').
// wp.data.subscribe watches the cart store for total/quantity changes.

(function () {
    'use strict';

    var WRAPPER_ID     = 'tbi-blocks-cart-wrapper';
    var LABEL_ID       = 'tbi-blocks-cart-label';
    var CART_STORE_KEY = 'wc/store/cart';

    var settings, FPTBI_ORIGIN, ajaxUrl;
    var lastTotal = null;
    var rejectPending = false;
    var mutationObserver = null;
    var buttonEnabled = true; // tracks whether current total is within limits

    // -------------------------------------------------------------------------
    // Settings — passed by PHP via IntegrationInterface::get_script_data()
    // -------------------------------------------------------------------------
    function getSettings() {
        if (!window.wc || !window.wc.wcSettings) { return null; }
        return window.wc.wcSettings.getSetting('tbi-cart-button_data', null);
    }

    // -------------------------------------------------------------------------
    // Build button DOM nodes (mirrors CartButton::render() CSS classes)
    // -------------------------------------------------------------------------
    function buildButton() {
        var styleId   = parseInt(settings.style_id || 2, 10);
        var btnClass  = 'tbi_button' + styleId + (String(settings.reseller_code) === '2501' ? '_cart' : '');
        var bodyClass = 'tbi_button_body' + styleId;
        var logoClass = 'tbi_button_logo' + styleId;
        var contClass = 'tbi_button_container' + styleId;

        var wrapper = document.createElement('div');
        wrapper.id  = WRAPPER_ID;

        var contDiv = document.createElement('div');
        contDiv.className = contClass;

        var btnDiv = document.createElement('div');
        btnDiv.className  = btnClass;
        btnDiv.style.cursor = 'pointer';

        var bodyDiv = document.createElement('div');
        bodyDiv.className = bodyClass;

        // Line 1: "Buy with <logo>"
        var line1 = document.createElement('div');
        line1.className = 'tbi_button_line';
        line1.appendChild(document.createTextNode((settings.buy_with_text || 'Buy with') + '\u00a0'));
        var img = document.createElement('img');
        img.src       = settings.logo_url || '';
        img.alt       = 'tbi bank logo';
        img.width     = 89;
        img.height    = 40;
        img.className = logoClass;
        line1.appendChild(img);

        // Line 2: installment label (updated on cart changes)
        var line2 = document.createElement('div');
        line2.className   = 'tbi_button_line';
        line2.id          = LABEL_ID;
        line2.textContent = settings.label || '';

        bodyDiv.appendChild(line1);
        bodyDiv.appendChild(line2);
        btnDiv.appendChild(bodyDiv);
        contDiv.appendChild(btnDiv);
        wrapper.appendChild(contDiv);

        btnDiv.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openPopup();
        });

        return wrapper;
    }

    // -------------------------------------------------------------------------
    // Injection — find WC Blocks cart totals container and append button
    // -------------------------------------------------------------------------
    var TOTALS_SELECTORS = [
        '.wp-block-woocommerce-proceed-to-checkout-block',
        '.wc-block-cart__submit-container',
        '.wc-block-cart__submit',
        '.wp-block-woocommerce-cart-order-summary-totals-block',
        '.wc-block-components-totals-wrapper',
        '.wc-block-cart__totals',
        '.wp-block-woocommerce-cart-totals-block',
        '.wp-block-woocommerce-cart-order-summary-block'
    ];

    function findTotalsContainer() {
        for (var i = 0; i < TOTALS_SELECTORS.length; i++) {
            var el = document.querySelector(TOTALS_SELECTORS[i]);
            if (el) { return el; }
        }
        return null;
    }

    function injectButton() {
        if (document.getElementById(WRAPPER_ID)) { return; }
        var anchor = findTotalsContainer();
        if (!anchor) { return; }
        // Insert AFTER the anchor element (checkout button if found, else totals block).
        var btn = buildButton();
        if (anchor.nextSibling) {
            anchor.parentNode.insertBefore(btn, anchor.nextSibling);
        } else {
            anchor.parentNode.appendChild(btn);
        }
    }

    // -------------------------------------------------------------------------
    // Limit check — mirrors the PHP loan_max / bnpl_max / min_amount logic
    // -------------------------------------------------------------------------
    function checkLimits(total) {
        var loanMax   = parseFloat(settings.loan_max   || 0);
        var bnplMax   = parseFloat(settings.bnpl_max   || 0);
        var minAmount = parseFloat(settings.min_amount || 0);
        if (minAmount > 0 && total < minAmount) { return false; }
        if (loanMax > 0 && total > loanMax) {
            if (bnplMax <= 0 || total > bnplMax) { return false; }
        }
        return true;
    }

    function reinjectIfMissing() {
        if (!buttonEnabled) { return; }
        if (!document.getElementById(WRAPPER_ID)) { injectButton(); }
    }

    // -------------------------------------------------------------------------
    // MutationObserver — React re-renders the cart on quantity/coupon changes,
    // which wipes injected DOM. Detect and re-inject after each wipe.
    // -------------------------------------------------------------------------
    function setupObserver() {
        if (mutationObserver) { return; }
        mutationObserver = new MutationObserver(function () {
            if (!buttonEnabled) { return; }
            if (document.getElementById(WRAPPER_ID)) { return; }
            if (rejectPending) { return; }
            rejectPending = true;
            setTimeout(function () {
                rejectPending = false;
                reinjectIfMissing();
            }, 60);
        });
        mutationObserver.observe(document.body, { childList: true, subtree: true });
    }

    // -------------------------------------------------------------------------
    // Cart store subscription — update label when cart total changes
    // -------------------------------------------------------------------------
    function watchCartStore() {
        if (!window.wp || !window.wp.data) { return; }
        window.wp.data.subscribe(function () {
            try {
                var store = window.wp.data.select(CART_STORE_KEY);
                if (!store) { return; }
                var totals = store.getCartTotals();
                if (!totals || !totals.total_price) { return; }
                var minor = parseInt(totals.currency_minor_unit || 2, 10);
                var total = parseFloat(totals.total_price) / Math.pow(10, minor);
                if (total === lastTotal) { return; }
                lastTotal = total;

                var wasEnabled = buttonEnabled;
                buttonEnabled = checkLimits(total);

                if (!buttonEnabled) {
                    // Total is outside allowed range — hide the button immediately.
                    var w = document.getElementById(WRAPPER_ID);
                    if (w) { w.style.display = 'none'; }
                    return;
                }

                setTimeout(function () {
                    var wrapper = document.getElementById(WRAPPER_ID);
                    if (!wasEnabled && wrapper) {
                        // Was hidden; make visible again before refreshing label.
                        wrapper.style.display = '';
                    }
                    reinjectIfMissing();
                    if (total > 0) {
                        fetchLabel(total, totals.currency_code || settings.currency || '');
                    }
                }, 120);
            } catch (err) { /* ignore store errors */ }
        });
    }

    // -------------------------------------------------------------------------
    // Label refresh via installment calculator AJAX
    // -------------------------------------------------------------------------
    function fetchLabel(total, currency) {
        var labelParams = {
            action:   'fusipatb_calculate_installments_ajax',
            amount:   total.toFixed(2),
            currency: currency
        };
        if (settings.installments_nonce) {
            labelParams._wpnonce = settings.installments_nonce;
        }
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(labelParams).toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (!resp.success || !resp.data) { return; }
            var label = resp.data.button_text || '';
            if (!label && resp.data.installment_amount) {
                label = (resp.data.installments || 4) + '\u00a0\u00d7\u00a0' +
                        parseFloat(resp.data.installment_amount).toFixed(2);
            }
            if (label) {
                var el = document.getElementById(LABEL_ID);
                if (el) { el.textContent = label; }
            }
        })
        .catch(function () { /* keep existing label on network error */ });
    }

    // -------------------------------------------------------------------------
    // Popup — reuses same pattern as tbi_blocks_integration.js
    // -------------------------------------------------------------------------
    function openPopup() {
        var popup  = document.getElementById('tbi_popup_container');
        var iframe = document.getElementById('tbi_popup_iframe');

        if (!popup) {
            popup = document.createElement('div');
            popup.id = 'tbi_popup_container';
            popup.style.cssText =
                'display:flex;position:fixed;top:0;left:0;width:100%;height:100%;' +
                'background-color:rgba(0,0,0,0.5);justify-content:center;' +
                'align-items:center;z-index:9999;';

            iframe = document.createElement('iframe');
            iframe.id = 'tbi_popup_iframe';
            iframe.style.cssText = 'width:80%;height:80%;border:none;';
            iframe.setAttribute('src', '');

            popup.appendChild(iframe);
            document.body.appendChild(popup);

            popup.addEventListener('click', function (ev) {
                if (ev.target === popup) {
                    popup.style.display = 'none';
                    document.body.style.overflow = 'auto';
                    iframe.setAttribute('src', '');
                }
            });

            window.addEventListener('message', function (event) {
                if (event.origin !== FPTBI_ORIGIN) { return; }
                try {
                    var msg = JSON.parse(event.data);
                    if (msg.command === 'close_calculator' || msg.command === 'close') {
                        popup.style.display = 'none';
                        document.body.style.overflow = 'auto';
                        iframe.setAttribute('src', '');
                    }
                } catch (err) { /* ignore */ }
            });
        }

        popup.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        if (iframe.getAttribute('src')) { return; } // already loaded

        var popupParams = {
            action:   'fusipatb_process_cart',
            currency: settings.currency || ''
        };
        if (settings.cart_nonce) {
            popupParams._wpnonce = settings.cart_nonce;
        }
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams(popupParams).toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp.success && resp.data && resp.data.url) {
                iframe.setAttribute('src', resp.data.url);
            } else {
                iframe.setAttribute('src',
                    FPTBI_ORIGIN + '/application/error/error_notified/?merchant=' +
                    encodeURIComponent(window.location.href));
            }
        })
        .catch(function () {
            iframe.setAttribute('src',
                FPTBI_ORIGIN + '/application/error/error_notified/?merchant=' +
                encodeURIComponent(window.location.href));
        });
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------
    function init() {
        settings = getSettings();
        if (!settings || !settings.enabled) { return; }

        FPTBI_ORIGIN = settings.platform_url || 'https://beta.tbibank.support';
        ajaxUrl      = settings.ajax_url     || '/wp-admin/admin-ajax.php';

        injectButton();
        setupObserver();
        watchCartStore();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
