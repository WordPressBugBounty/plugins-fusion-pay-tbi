// tbi_cart.js — merged BG + RO
// BG: cart hash monitoring, API-driven button text updates, modal config
// RO: no hardcoded fusipatbAjax (uses wp_localize_script)

(function() {
    var FPTBI_ORIGIN = (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.platformUrl)
        ? fusipatbConfig.platformUrl
        : 'https://beta.tbibank.support';

    // Server-provided cart total — updated via data-cart-total attribute on
    // #tbi_cart_content (re-rendered by WC on cart update) and fusipatbConfig (initial).
    // This eliminates the need for client-side price summing which can be wrong
    // when admin currency differs from display currency.
    var _cartTotal = (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.cartTotal)
        ? parseFloat(fusipatbConfig.cartTotal)
        : NaN;

    var lastCartHash     = null;
    var cartCheckInterval = null;

    document.addEventListener("DOMContentLoaded", function() {

        var iframe      = getByID('tbi_popup_iframe');
        var tbi_loading = getByID('tbi_loading');

        if (getByID('tbi_button')) {
            var tbi_button = getByID('tbi_button');
            var tbi_popup  = getByID('tbi_popup_container');

            initializeCartMonitoring();

            if (!tbi_button.listenerAdded) {
                tbi_button.removeEventListener("click", cart_button_function);
                tbi_button.addEventListener("click", cart_button_function);
                tbi_button.listenerAdded = true;
            }

            if (iframe && !iframe.listenerAdded) {
                iframe.addEventListener("click", function(e) { e.stopPropagation(); });
                iframe.listenerAdded = true;
            }

            if (!window.clickListenerAdded) {
                window.addEventListener("click", function() {
                    tbi_popup.style.display = "none";
                    document.body.style.overflow = "auto";
                });
                window.clickListenerAdded = true;
            }

            window.addEventListener('message', function(event) {
                if (event.origin !== FPTBI_ORIGIN) return;
                try {
                    var jsonObject    = JSON.parse(event.data);
                    var popupContainer = getByID('tbi_popup_container');
                    if (!popupContainer) return;
                    var redirect_base = popupContainer.getAttribute('data-redirect');
                    if (!redirect_base) return;

                    if (redirect_base.indexOf('?') > -1) {
                        redirect_base = redirect_base + '&order-received=' + jsonObject.order_id + '&key=' + jsonObject.wc_key;
                    } else {
                        redirect_base = redirect_base + '/order-received/' + jsonObject.order_id + '/?key=' + jsonObject.wc_key;
                    }

                    if (jsonObject.command === "allow_close") {
                        window.addEventListener('click', function() { window.location = redirect_base; });
                    }
                    if (jsonObject.command === "close" && jsonObject.order_id != undefined) {
                        window.location = redirect_base;
                    }
                    if (jsonObject.command === "close" && jsonObject.order_id == undefined) {
                        tbi_popup.style.display = 'none';
                        document.body.style.overflow = "auto";
                    }
                    if (jsonObject.command === "close_calculator" && jsonObject.order_id == undefined) {
                        tbi_popup.style.display = 'none';
                        document.body.style.overflow = "auto";
                    }
                } catch (error) {
                    console.error('Invalid JSON string:', error);
                }
            });
        }

        // When WooCommerce re-renders the cart totals section (quantity change,
        // coupon apply, bundle add, etc.) the tbi_cart_content hidden field is
        // already updated before this event fires — read it and refresh the label.
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('updated_cart_totals updated_wc_div', async function() {
                try {
                    var cartTotal = getServerCartTotal();
                    if (cartTotal > 0) {
                        lastCartHash = getCartHash();
                        await updateCartButtonWithAPI(cartTotal);
                    }
                } catch (e) {
                    console.warn('FusionPayTbi cart update error:', e);
                }
            });
        }

        // XHR intercept — rebind listeners after AJAX updates
        (function() {
            var send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function() {
                this.addEventListener('load', function() {
                    if (iframe) iframe.src = "";
                    if (tbi_loading) tbi_loading.style.display = 'block';
                    var btn = getByID('tbi_button');
                    var popup = getByID('tbi_popup_container');
                    if (btn && !btn.listenerAdded) {
                        btn.removeEventListener("click", cart_button_function);
                        btn.addEventListener("click", cart_button_function);
                        btn.listenerAdded = true;
                    }
                    if (iframe && !iframe.listenerAdded) {
                        iframe.addEventListener("click", function(e) { e.stopPropagation(); });
                        iframe.listenerAdded = true;
                    }
                    if (!window.clickListenerAdded) {
                        window.addEventListener("click", function() {
                            if (popup) popup.style.display = "none";
                        });
                        window.clickListenerAdded = true;
                    }
                    initializeCartMonitoring();
                });
                return send.apply(this, arguments);
            };
        })();
    });

    // Listen for WooCommerce cart page updates (quantity change, coupon, shipping method).
    // WC fires 'updated_cart_totals' after the cart totals section is re-rendered.
    jQuery(document.body).on('updated_cart_totals updated_wc_div', function() {
        fetchCartTotal();
    });

    /**
     * Fetch the converted cart total from the server via a lightweight AJAX call.
     * This is the cart-page equivalent of the checkout's fusipatb_checkout_total fragment.
     */
    function fetchCartTotal() {
        var ajaxUrl = (typeof fusipatbAjax !== 'undefined' && fusipatbAjax.ajaxurl)
            ? fusipatbAjax.ajaxurl
            : (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.ajaxurl ? fusipatbConfig.ajaxurl : '/wp-admin/admin-ajax.php');

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=fusipatb_get_cart_total'
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.success && resp.data && typeof resp.data.cart_total !== 'undefined') {
                var newTotal = parseFloat(resp.data.cart_total);
                console.log('FusionPayTbi updated_cart_totals total from server:', newTotal);
                if (!isNaN(newTotal) && newTotal > 0) {
                    _cartTotal = newTotal;
                    // Sync the popup calculator URL
                    var popup = getByID('tbi_popup_container');
                    if (popup) {
                        updateCalculatorAmount(popup, newTotal);
                    }
                    // Reset iframe so next open fetches fresh data
                    var iframe = getByID('tbi_popup_iframe');
                    if (iframe) {
                        iframe.src = '';
                    }
                }
            }
        })
        .catch(function(err) {
            console.warn('FusionPayTbi fetchCartTotal error:', err);
        });
    }

    function initializeCartMonitoring() {
        if (cartCheckInterval) clearInterval(cartCheckInterval);
        lastCartHash     = getCartHash();
        cartCheckInterval = setInterval(checkCartChanges, 500);
    }

    function getCartHash() {
        var cartContent = getByID('tbi_cart_content');
        return cartContent ? cartContent.value : '';
    }

    function getServerCartTotal() {
        var btn = getByID('tbi_button_body');
        if (btn) {
            var val = parseFloat(btn.getAttribute('data-cart-total'));
            if (!isNaN(val) && val > 0) return val;
        }
        return 0;
    }

    async function checkCartChanges() {
        var currentHash = getCartHash();
        if (currentHash === lastCartHash || currentHash === '') return;
        lastCartHash = currentHash;

        try {
            // Prefer the PHP-computed total (includes shipping) over item-sum
            var cartTotal = getServerCartTotal();
            if (cartTotal <= 0) {
                var cartData = JSON.parse(currentHash);
                cartData.forEach(function(item) {
                    cartTotal += parseFloat(item.price) * parseInt(item.quantity);
                });
            }
            if (cartTotal > 0) {
                await updateCartButtonWithAPI(cartTotal);
            }
        } catch (error) {
            console.error('Error parsing cart data:', error);
        }

        var iframe = getByID('tbi_popup_iframe');
        if (iframe) {
            iframe.src = "";
            var tbi_loading = getByID('tbi_loading');
            if (tbi_loading) tbi_loading.style.display = 'block';
        }
    }

    function getTbiCurrency() {
        var popupEl = getByID('tbi_popup_container');
        if (!popupEl) return null;
        var calcUrl = popupEl.getAttribute('data-calculator');
        if (!calcUrl || calcUrl.indexOf('?') === -1) return null;
        return new URLSearchParams(calcUrl.split('?')[1]).get('currency');
    }

    async function updateCartButtonWithAPI(totalAmount) {
        if (typeof fusipatbAjax === 'undefined' || !fusipatbAjax.ajaxurl) return;
        try {
            var formData = new URLSearchParams();
            formData.append('action', 'fusipatb_calculate_installments_ajax');
            formData.append('amount', totalAmount.toFixed(2));
            formData.append('currency', getActiveCurrency());
            if (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.installmentsNonce) {
                formData.append('_wpnonce', fusipatbConfig.installmentsNonce);
            }

            var response = await fetch(fusipatbAjax.ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            });
            if (!response.ok) return;
            var data = await response.json();
            if (data.success && data.data) {
                updateCartButtonWithAPIData(data.data);
            }
        } catch (error) {
            // Ignore.
        }
    }

    function updateCartButtonWithAPIData(apiData) {
        var buttonLine = getButtonLine();
        if (!buttonLine) return;

        var label = '';
        if (apiData.button_text) {
            label = apiData.button_text;
        } else {
            var installment = parseFloat(apiData.installment_amount || apiData.installment || 0).toFixed(2);
            var body = getByID('tbi_button_body');
            var tmpl = body ? (body.getAttribute('data-label') || '') : '';
            var eur  = body ? parseFloat(body.getAttribute('data-eur') || '0') : 0;
            var currency = body ? (body.getAttribute('data-currency') || 'BGN') : 'BGN';
            var period = apiData.installments || apiData.period || '';
            if (!isFinite(eur) || eur < 0) eur = 0;
            if (tmpl) {
                if (eur > 0) {
                    if (currency.toUpperCase() === 'EUR') {
                        // API returns EUR; {installment}=BGN, {eur_installment}=EUR
                        var bgnInstallment = (parseFloat(installment) * eur).toFixed(2);
                        label = tmpl
                            .replace('{installments}', period)
                            .replace('{eur_installment}', installment)
                            .replace('{installment}', bgnInstallment);
                    } else {
                        // API returns BGN; {installment}=BGN, {eur_installment}=EUR
                        var eurInstallment = (parseFloat(installment) / eur).toFixed(2);
                        label = tmpl
                            .replace('{installments}', period)
                            .replace('{eur_installment}', eurInstallment)
                            .replace('{installment}', installment);
                    }
                } else {
                    label = tmpl
                        .replace('{installments}', period)
                        .replace('{installment}', installment);
                }
            } else if (eur > 0) {
                if (currency.toUpperCase() === 'EUR') {
                    // API returns EUR; show EUR amount with EUR label, BGN with BGN label
                    var bgnInstallment = (parseFloat(installment) * eur).toFixed(2);
                    label = period + ' installments of ' + installment + ' EUR (' + bgnInstallment + ' BGN)';
                } else {
                    // API returns BGN; show EUR converted, BGN raw
                    var eurInstallment = (parseFloat(installment) / eur).toFixed(2);
                    label = period + ' installments of ' + eurInstallment + ' EUR (' + installment + ' BGN)';
                }
            } else {
                label = period + ' x ' + installment;
            }
        }

        buttonLine.innerHTML = '';
        buttonLine.innerText = label;

        storeModalConfig(apiData);
    }

    function cart_button_function(e) {
        e.preventDefault();
        e.stopPropagation();
        var iframe      = getByID('tbi_popup_iframe');
        var tbi_popup   = getByID('tbi_popup_container');
        var tbi_loading = getByID('tbi_loading');

        tbi_popup.style.display      = "flex";
        document.body.style.overflow = "hidden";

        if (iframe.getAttribute('src') !== "") {
            return;
        }

        // Don't send items from the hidden input — those are rendered at page
        // load time and can have stale/incorrect prices. Let CartProcessor build
        // fresh items from the WC session cart (items_from_post=false path).
        var requestData = {
            action: "fusipatb_process_cart",
            currency: getActiveCurrency()
        };
        if (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.cartNonce) {
            requestData._wpnonce = fusipatbConfig.cartNonce;
        }

        // Forward modal defaults selected for the cart widget so backend can
        // keep popup financing aligned with the button text.
        var schemeId = getModalConfigValue(iframe, 'data-scheme-id');
        var modalSection = getModalConfigValue(iframe, 'data-modal-section');
        var mode = getModalConfigValue(iframe, 'data-mode');
        var defaultInstallments = getModalConfigValue(iframe, 'data-default-installments');
        var defaultInstallmentAmount = getModalConfigValue(iframe, 'data-default-installment-amount');

        if (!defaultInstallments) {
            // Fallback: parse installments count from the visible label, e.g. "13 x ...".
            var labelNode = getButtonLine();
            var labelText = labelNode ? (labelNode.innerText || '') : '';
            var installmentsMatch = labelText.match(/(\d+)\s*[xX]/);
            if (installmentsMatch && installmentsMatch[1]) {
                defaultInstallments = installmentsMatch[1];
            }
        }

        if (schemeId) requestData.scheme_id = schemeId;
        if (modalSection) requestData.modal_section = modalSection;
        if (mode) requestData.mode = mode;
        if (defaultInstallments) requestData.default_installments = defaultInstallments;
        if (defaultInstallmentAmount) requestData.default_installment_amount = defaultInstallmentAmount;

        var fetchTimeout = function(url, options, timeout) {
            timeout = timeout || 30000;
            return Promise.race([
                fetch(url, options),
                new Promise(function(_, reject) {
                    setTimeout(function() { reject(new Error('Request timed out')); }, timeout);
                })
            ]);
        };

        var ajaxUrl = (typeof fusipatbAjax !== 'undefined' && fusipatbAjax.ajaxurl)
            ? fusipatbAjax.ajaxurl
            : '/wp-admin/admin-ajax.php';

        fetchTimeout(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams(requestData).toString()
        })
        .then(function(response) { return response.json(); })
        .then(function(response) {
            if (response.success) {
                if (response.data && response.data.debug) {
                    console.log('FusionPayTbi iframe payload', response.data.debug);
                }
                var iframeUrl = appendModalParams(response.data.url, iframe);
                iframe.src = iframeUrl;
            } else {
                throw new Error(response.data ? response.data.message : 'Error');
            }
            tbi_loading.style.display = 'none';
        })
        .catch(function(error) {
            var msg = error && error.message ? error.message : '';
            if (msg && (msg.indexOf('Minimum amount') > -1 || msg.indexOf('maximum amount') > -1 || msg.indexOf('loan') > -1)) {
                tbi_popup.style.display      = 'none';
                document.body.style.overflow = 'auto';
                alert(msg);
            } else {
                iframe.src = FPTBI_ORIGIN + '/application/error/error_notified/?merchant=' + encodeURIComponent(window.location.href);
            }
            tbi_loading.style.display = 'none';
        });
    }

    function getByID(el) {
        var exact = document.getElementById(el);
        if (exact) {
            return exact;
        }

        if (el === 'tbi_button') {
            return document.querySelector(
                '[id^="tbi_button"]:not([id^="tbi_button_"]), [class^="tbi_button"]:not([class^="tbi_button_"]), [class*=" tbi_button"]:not([class*=" tbi_button_"])'
            );
        }
        if (el === 'tbi_button_body') {
            return document.querySelector(
                '[id^="tbi_button_body"], [class^="tbi_button_body"], [class*=" tbi_button_body"]'
            );
        }
        if (el === 'tbi_button_container') {
            return document.querySelector(
                '[id^="tbi_button_container"], [class^="tbi_button_container"], [class*=" tbi_button_container"]'
            );
        }

        return null;
    }

    function getButtonLine() {
        var button = getByID('tbi_button');
        return button ? button.querySelector('.tbi_button_line:nth-of-type(2)') : null;
    }

    function appendModalParams(url, iframe) {
        var modalSection = getModalConfigValue(iframe, 'data-modal-section');
        var mode = getModalConfigValue(iframe, 'data-mode');
        var schemeId = getModalConfigValue(iframe, 'data-scheme-id');
        var defaultInstallments = getModalConfigValue(iframe, 'data-default-installments');
        var defaultInstallmentAmount = getModalConfigValue(iframe, 'data-default-installment-amount');
        var params = [];

        if (schemeId) {
            params.push('modal_section=selected');
            params.push('scheme_id=' + encodeURIComponent(schemeId));
        } else if (modalSection) {
            params.push('modal_section=' + encodeURIComponent(modalSection));
        }
        if (mode) params.push('mode=' + encodeURIComponent(mode));
        if (defaultInstallments) params.push('default_installments=' + encodeURIComponent(defaultInstallments));
        if (defaultInstallmentAmount) params.push('default_installment_amount=' + encodeURIComponent(defaultInstallmentAmount));

        if (params.length > 0) {
            url += (url.indexOf('?') > -1 ? '&' : '?') + params.join('&');
        }

        return url;
    }

    function storeModalConfig(apiData) {
        var iframe = getByID('tbi_popup_iframe');
        var buttonBody = getByID('tbi_button_body');
        var targets = [iframe, buttonBody];

        for (var i = 0; i < targets.length; i++) {
            var target = targets[i];
            if (!target) {
                continue;
            }

            setModalAttribute(target, 'data-modal-section', normalizeModalSection(apiData && apiData.modal_section));
            setModalAttribute(target, 'data-mode', apiData && apiData.mode ? apiData.mode : '');
            setModalAttribute(target, 'data-scheme-id', apiData && apiData.scheme_id ? apiData.scheme_id : '');
            setModalAttribute(target, 'data-default-installments', apiData && apiData.default_installments ? apiData.default_installments : '');
            setModalAttribute(target, 'data-default-installment-amount', apiData && apiData.default_installment_amount ? apiData.default_installment_amount : '');
        }
    }

    function getModalConfigValue(iframe, attr) {
        if (iframe) {
            var value = iframe.getAttribute(attr);
            if (value) {
                return value;
            }
        }

        var buttonBody = getByID('tbi_button_body');
        return buttonBody ? (buttonBody.getAttribute(attr) || '') : '';
    }

    function setModalAttribute(element, attr, value) {
        if (!element) {
            return;
        }
        if (value) {
            element.setAttribute(attr, value);
        } else {
            element.removeAttribute(attr);
        }
    }

    function normalizeModalSection(value) {
        if (!value) {
            return '';
        }
        return String(value).toLowerCase();
    }

    /**
     * Update the data-calculator URL on the popup with a new amount value.
     */
    function updateCalculatorAmount(popup, newAmount) {
        var calcUrl = popup.getAttribute('data-calculator');
        if (!calcUrl) return;
        if (calcUrl.match(/[?&]amount=/)) {
            calcUrl = calcUrl.replace(/([?&]amount=)[^&]*/, '$1' + newAmount);
        } else {
            var sep = calcUrl.indexOf('?') > -1 ? '&' : '?';
            calcUrl = calcUrl + sep + 'amount=' + newAmount;
        }
        popup.setAttribute('data-calculator', calcUrl);
    }

    function getActiveCurrency() {
        var currency = getCurrencyFromSearchOrCookies();
        if (currency) {
            return currency;
        }

        currency = getCurrencyFromVisiblePrice();
        if (currency) {
            return currency;
        }

        currency = getCurrencyFromDataset();
        if (currency) {
            return currency;
        }

        return normalizeCurrency(typeof fusipatbConfig !== 'undefined' ? fusipatbConfig.currency : '');
    }

    function getCurrencyFromSearchOrCookies() {
        var keys = ['currency', 'wmc-currency', 'wcmlc', 'aelia_cs_currency', 'woocs', 'alg_currency'];
        var params = new URLSearchParams(window.location.search || '');
        for (var i = 0; i < keys.length; i++) {
            var paramCurrency = normalizeCurrency(params.get(keys[i]));
            if (paramCurrency) {
                return paramCurrency;
            }
        }

        var cookies = document.cookie ? document.cookie.split(';') : [];
        for (var j = 0; j < cookies.length; j++) {
            var cookie = cookies[j].split('=');
            var name = cookie.shift();
            if (!name) {
                continue;
            }
            name = name.trim();
            if (keys.indexOf(name) === -1) {
                continue;
            }
            var value = cookie.join('=');
            var cookieCurrency = normalizeCurrency(decodeURIComponent(value || ''));
            if (cookieCurrency) {
                return cookieCurrency;
            }
        }

        return '';
    }

    function getCurrencyFromVisiblePrice() {
        var selectors = [
            '.cart_totals .order-total .woocommerce-Price-currencySymbol',
            '.cart_totals .woocommerce-Price-currencySymbol',
            '.woocommerce-Price-amount .woocommerce-Price-currencySymbol'
        ];

        for (var i = 0; i < selectors.length; i++) {
            var nodes = document.querySelectorAll(selectors[i]);
            for (var j = 0; j < nodes.length; j++) {
                var currency = mapCurrencySymbol(nodes[j].textContent || '');
                if (currency) {
                    return currency;
                }
            }
        }

        return '';
    }

    function getCurrencyFromDataset() {
        var selectors = ['#tbi_button_body', '#tbi_button_container', '#tbi_popup_container', '[data-currency]'];
        for (var i = 0; i < selectors.length; i++) {
            var element = document.querySelector(selectors[i]);
            if (!element) {
                continue;
            }
            var currency = normalizeCurrency(element.getAttribute('data-currency'));
            if (currency) {
                return currency;
            }
        }

        return '';
    }

    function mapCurrencySymbol(symbolText) {
        var symbol = (symbolText || '').trim().toUpperCase();
        if (!symbol) {
            return '';
        }
        if (symbol.indexOf('€') > -1 || symbol.indexOf('EUR') > -1) {
            return 'EUR';
        }
        if (symbol.indexOf('ЛВ') > -1 || symbol.indexOf('BGN') > -1) {
            return 'BGN';
        }
        if (symbol.indexOf('RON') > -1 || symbol.indexOf('LEI') > -1) {
            return 'RON';
        }
        return '';
    }

    function normalizeCurrency(value) {
        if (!value) {
            return '';
        }

        var normalized = String(value).trim().toUpperCase();
        return /^[A-Z]{3}$/.test(normalized) ? normalized : '';
    }

})();
