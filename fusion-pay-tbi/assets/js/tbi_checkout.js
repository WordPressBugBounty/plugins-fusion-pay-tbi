// tbi_checkout.js — merged BG + RO, platform URL driven by fusipatbConfig
// Uses native XMLHttpRequest (BG approach - more reliable than jQuery.ajax)

(function() {
    var FPTBI_ORIGIN = (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.platformUrl)
        ? fusipatbConfig.platformUrl
        : 'https://beta.tbibank.support';

    // Server-provided checkout total — updated via fusipatbConfig (initial) and
    // woocommerce_update_order_review_response (on every checkout refresh).
    // This eliminates the need for fragile DOM-selector scraping.
    var _checkoutTotal = (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.checkoutTotal)
        ? parseFloat(fusipatbConfig.checkoutTotal)
        : NaN;

    document.addEventListener("DOMContentLoaded", function() {

        var tbi_popup  = getByID('tbi_popup_container');
        var iframe     = getByID('tbi_popup_iframe');
        var tbi_loading = getByID('tbi_loading');

        jQuery("form.woocommerce-checkout").on('checkout_place_order', function(e) {
            if (document.querySelector('#payment_method_tbi_bank:checked') == null) {
                return;
            }

            try {
                var popupEl       = getByID('tbi_popup_container');
                var calculatorUrl = popupEl ? popupEl.getAttribute('data-calculator') : null;
                var minAmount     = popupEl ? parseFloat(popupEl.getAttribute('data-min-amount')) : NaN;
                var maxAmount     = popupEl ? parseFloat(popupEl.getAttribute('data-max-amount')) : NaN;
                var bnplMin       = popupEl ? parseFloat(popupEl.getAttribute('data-bnpl-min-amount')) : NaN;
                var bnplMax       = popupEl ? parseFloat(popupEl.getAttribute('data-bnpl-max-amount')) : NaN;
                // Use server-provided total (always in display currency, matching the limits)
                var amount = _checkoutTotal;
                if (isNaN(amount) && calculatorUrl && calculatorUrl.indexOf('?') > -1) {
                    var urlParams = new URLSearchParams(calculatorUrl.split('?')[1]);
                    amount = parseFloat(urlParams.get('amount'));
                }
                console.log('FusionPayTbi checkout place_order range check:', { amount: amount, minAmount: minAmount, maxAmount: maxAmount, bnplMin: bnplMin, bnplMax: bnplMax });
                var isBnplEligible = !isNaN(amount) && !isNaN(bnplMin) && !isNaN(bnplMax) && amount >= bnplMin && amount <= bnplMax;
                if (!isBnplEligible && !isNaN(amount) && ((!isNaN(minAmount) && amount < minAmount) || (!isNaN(maxAmount) && amount > maxAmount))) {
                    alert((popupEl && popupEl.getAttribute('data-range-alert')) || 'Amount outside allowed loan range.');
                    return false;
                }
            } catch (err) {
                console.warn('FusionPayTbi checkout place_order range check error:', err);
            }

            e.preventDefault();
            var order_sent = false;
            jQuery('.woocommerce-notices-wrapper:first-of-type').html("");
            tbi_popup.style.display = 'flex';
            iframe.src = "";
            tbi_loading.style.display = "block";

            var frm      = jQuery('form.checkout.woocommerce-checkout');
            var formData = new FormData(frm[0]);
            formData.set('currency', getActiveCurrency());

            if (order_sent == false) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/?wc-ajax=checkout', true);

                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 400) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.result == 'failure') {
                                tbi_popup.style.display = 'none';
                                jQuery('.woocommerce-notices-wrapper:first-of-type').append(response.messages);
                                jQuery('html, body').animate({
                                    scrollTop: jQuery('.woocommerce-notices-wrapper:first-of-type').offset().top - 40
                                }, 1000);
                            } else {
                                if (response.redirect) {
                                    window.tbi_redirect_url = response.redirect;
                                }
                                order_sent = true;
                                iframe.src = response.url;
                                tbi_loading.style.display = 'none';
                                document.body.style.overflow = "hidden";
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                        }
                    } else {
                        console.error('An error occurred during the request.');
                    }
                };

                xhr.onerror = function() {
                    console.error('An error occurred during the request.');
                };

                xhr.send(formData);
            }
            return false;
        });

        window.addEventListener('message', function(event) {
            if (event.origin !== FPTBI_ORIGIN) return;
            try {
                var jsonObject    = JSON.parse(event.data);
                var redirect_base = window.tbi_redirect_url || getByID('tbi_popup_container').getAttribute('data-redirect');

                if (redirect_base.indexOf('?') > -1) {
                    redirect_base = redirect_base + '&order-received=' + jsonObject.order_id + '&key=' + jsonObject.wc_key;
                } else {
                    redirect_base = redirect_base + '/order-received/' + jsonObject.order_id + '/?key=' + jsonObject.wc_key;
                }

                if (jsonObject.command === "allow_close") {
                    window.addEventListener('click', function() {
                        window.location = redirect_base;
                    });
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

    });

    // Refresh popup amount when WooCommerce recalculates the checkout (shipping change, coupon, etc.)
    // The server injects fusipatb_checkout_total via woocommerce_update_order_review_response filter,
    // so we never need to scrape the DOM.
    jQuery(document.body).on('updated_checkout', function(event, data) {
        var popup = getByID('tbi_popup_container');
        if (!popup) return;

        var newTotal = NaN;
        if (data && data.fragments && typeof data.fragments.fusipatb_checkout_total !== 'undefined') {
            newTotal = parseFloat(data.fragments.fusipatb_checkout_total);
        } else {
            console.warn('FusionPayTbi updated_checkout: fusipatb_checkout_total missing from response — PHP filter may not be registered');
        }
        console.log('FusionPayTbi updated_checkout total from server:', newTotal);
        if (isNaN(newTotal) || newTotal <= 0) return;

        _checkoutTotal = newTotal;
        updateCalculatorAmount(popup, newTotal);

        // Reset iframe so next open fetches fresh data for the new amount
        var iframe = getByID('tbi_popup_iframe');
        if (iframe) {
            iframe.src = '';
            iframe.removeAttribute('data-modal-config-loaded');
        }
    });

    // XHR intercept to rebind tbi_check_plans after dynamic DOM updates
    (function() {
        var send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function() {
            this.addEventListener('load', function() {
                retry(function() {
                    return addEventListenerIfExists('tbi_check_plans', 'click', showOptions);
                }, 500, 20);
            });
            return send.apply(this, arguments);
        };
    })();

    async function showOptions(e) {
        e.preventDefault();
        e.stopPropagation();
        var tbi_popup   = getByID('tbi_popup_container');
        var tbi_iframe  = getByID('tbi_popup_iframe');
        var tbi_loading = getByID('tbi_loading');
        var calculator_url = tbi_popup.getAttribute('data-calculator');

        try {
            var minAmount = parseFloat(tbi_popup.getAttribute('data-min-amount'));
            var maxAmount = parseFloat(tbi_popup.getAttribute('data-max-amount'));
            var bnplMin   = parseFloat(tbi_popup.getAttribute('data-bnpl-min-amount'));
            var bnplMax   = parseFloat(tbi_popup.getAttribute('data-bnpl-max-amount'));
            // Use server-provided total (always in display currency, matching the limits)
            var amount = _checkoutTotal;
            if (isNaN(amount) && calculator_url && calculator_url.indexOf('?') > -1) {
                var urlParams = new URLSearchParams(calculator_url.split('?')[1]);
                amount = parseFloat(urlParams.get('amount'));
            }
            console.log('FusionPayTbi checkout showOptions range check:', { amount: amount, minAmount: minAmount, maxAmount: maxAmount, bnplMin: bnplMin, bnplMax: bnplMax });
            var isBnplEligible = !isNaN(amount) && !isNaN(bnplMin) && !isNaN(bnplMax) && amount >= bnplMin && amount <= bnplMax;
            if (!isBnplEligible && !isNaN(amount) && ((!isNaN(minAmount) && amount < minAmount) || (!isNaN(maxAmount) && amount > maxAmount))) {
                alert(tbi_popup.getAttribute('data-range-alert') || 'Amount outside allowed loan range.');
                return;
            }
        } catch (err) {
            console.warn('FusionPayTbi checkout showOptions range check error:', err);
        }

        if (!tbi_iframe.getAttribute('data-modal-config-loaded')) {
            var modalConfig = await fetchCheckoutModalConfig();
            if (modalConfig) {
                storeModalConfigOnIframe(tbi_iframe, modalConfig);
                tbi_iframe.setAttribute('data-modal-config-loaded', 'true');
            }
        }

        // Always sync the calculator URL with _checkoutTotal so the iframe receives
        // the exact same amount that passed the range check (includes shipping).
        if (!isNaN(_checkoutTotal) && _checkoutTotal > 0) {
            updateCalculatorAmount(tbi_popup, _checkoutTotal);
        }
        calculator_url = tbi_popup.getAttribute('data-calculator');
        calculator_url = buildCalculatorUrlWithParams(calculator_url, tbi_iframe);

        tbi_popup.style.display = "flex";
        document.body.style.overflow = "hidden";
        if (tbi_iframe.getAttribute('src') == "") {
            tbi_iframe.src = calculator_url;
            tbi_loading.style.display = 'none';
        }

        tbi_iframe.addEventListener("click", function(e) { e.stopPropagation(); });
        window.addEventListener("click", function() {
            tbi_popup.style.display = "none";
            document.body.style.overflow = "auto";
        });

        window.addEventListener('message', function(event) {
            if (event.origin !== FPTBI_ORIGIN) return;
            try {
                var jsonObject = JSON.parse(event.data);
                if (jsonObject.command === "close_calculator" || jsonObject.command === "close") {
                    tbi_popup.style.display = "none";
                    document.body.style.overflow = "auto";
                }
            } catch (error) {
                console.error('Invalid JSON string:', error);
            }
        });
    }

    async function fetchCheckoutModalConfig() {
        try {
            // Use the live _checkoutTotal (which includes shipping) rather than
            // the potentially stale amount embedded in the data-calculator URL.
            var amount = (!isNaN(_checkoutTotal) && _checkoutTotal > 0)
                ? String(_checkoutTotal)
                : (function() {
                    var u = getByID('tbi_popup_container');
                    if (!u) return null;
                    var p = new URLSearchParams((u.getAttribute('data-calculator') || '').split('?')[1] || '');
                    return p.get('amount');
                  })();
            if (!amount) return null;
            if (typeof fusipatbAjax === 'undefined' || !fusipatbAjax.ajaxurl) return null;

            var formData = new URLSearchParams();
            formData.append('action', 'fusipatb_calculate_installments_ajax');
            formData.append('amount', amount);
            formData.append('currency', getActiveCurrency());
            if (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.installmentsNonce) {
                formData.append('_wpnonce', fusipatbConfig.installmentsNonce);
            }

            var response = await fetch(fusipatbAjax.ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            });
            if (!response.ok) return null;
            var data = await response.json();
            if (data.success && data.data) {
                return {
                    modal_section: data.data.modal_section,
                    mode: data.data.mode,
                    scheme_id: data.data.scheme_id,
                    default_installments: data.data.default_installments,
                    default_installment_amount: data.data.default_installment_amount
                };
            }
            return null;
        } catch (error) {
            return null;
        }
    }

    function storeModalConfigOnIframe(iframe, config) {
        if (!iframe || !config) return;
        if (config.modal_section) iframe.setAttribute('data-modal-section', config.modal_section.toLowerCase());
        if (config.mode) iframe.setAttribute('data-mode', config.mode);
        if (config.scheme_id) iframe.setAttribute('data-scheme-id', config.scheme_id);
        if (config.default_installments) iframe.setAttribute('data-default-installments', config.default_installments);
        if (config.default_installment_amount) iframe.setAttribute('data-default-installment-amount', config.default_installment_amount);
    }

    function buildCalculatorUrlWithParams(baseUrl, iframe) {
        if (!iframe) return baseUrl;
        var modalSection             = iframe.getAttribute('data-modal-section');
        var mode                     = iframe.getAttribute('data-mode');
        var schemeId                 = iframe.getAttribute('data-scheme-id');
        var defaultInstallments      = iframe.getAttribute('data-default-installments');
        var defaultInstallmentAmount = iframe.getAttribute('data-default-installment-amount');
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
            var sep = baseUrl.indexOf('?') > -1 ? '&' : '?';
            return baseUrl + sep + params.join('&');
        }
        return baseUrl;
    }

    function retry(fn, interval, maxAttempts) {
        return new Promise(function(resolve) {
            var attempts   = 0;
            var intervalId = setInterval(function() {
                attempts++;
                var result = fn();
                if (result || attempts >= maxAttempts) {
                    clearInterval(intervalId);
                    resolve(result);
                }
            }, interval);
        });
    }

    function addEventListenerIfExists(elementId, eventType, eventHandler) {
        var element = document.getElementById(elementId);
        if (element) {
            element.removeEventListener(eventType, eventHandler);
            element.addEventListener(eventType, eventHandler);
            return true;
        }
        return false;
    }

    function getByID(el) {
        return document.getElementById(el);
    }

    /**
     * Update the data-calculator URL with a new amount value.
     */
    function updateCalculatorAmount(popup, newAmount) {
        var calcUrl = popup.getAttribute('data-calculator');
        if (!calcUrl) return;
        // Replace or append the amount parameter
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
            '.order-total .woocommerce-Price-currencySymbol',
            '.cart-subtotal .woocommerce-Price-currencySymbol',
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
        var selectors = ['#tbi_popup_container', '[data-currency]'];
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
