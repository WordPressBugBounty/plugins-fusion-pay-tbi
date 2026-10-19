// tbi_product.js — merged BG + RO
// BG: API-driven button updates, event delegation, MutationObserver, WCPA support, session data, loading animation
// RO: dynamic tier matching, analysis fees
// All hardcoded origins/URLs replaced with fusipatbConfig (wp_localize_script)

(function() {
    var FPTBI_ORIGIN = (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.platformUrl)
        ? fusipatbConfig.platformUrl
        : 'https://beta.tbibank.support';

    var ajaxUrl = (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.ajaxurl)
        ? fusipatbConfig.ajaxurl
        : '/wp-admin/admin-ajax.php';

    var fptbi_maxChecks    = 10;
    var fptbi_intervalTime = 1000;
    var fptbi_buttonEvent        = false;
    var fptbi_windowEvent        = false;
    var fptbi_formEvent          = false;
    var fptbi_bundleObserverSet  = false;
    var fptbi_checkCount         = 0;

    var fptbi_interval = setInterval(function() {
        fptbi_checkCount++;
        if (fptbi_buttonEvent && fptbi_windowEvent && fptbi_formEvent) {
            clearInterval(fptbi_interval);
        } else if (fptbi_checkCount >= fptbi_maxChecks) {
            clearInterval(fptbi_interval);
        } else {
            tbiHandler();
        }
    }, fptbi_intervalTime);

    document.addEventListener('DOMContentLoaded', function() {
        tbiHandler();
        setupEventDelegation();
        setupMutationObserver();
    });

    function fptbiGetMaxAmount() {
        try {
            var el = getByID('tbi_button_body');
            var currencyFallback = (el && (el.getAttribute('data-currency') || '').toUpperCase() === 'EUR') ? 15000 : 30000;
            if (!el) return currencyFallback;
            var v = parseFloat(el.getAttribute('data-max-amount') || el.getAttribute('data-max'));
            return (isNaN(v) || v <= 0) ? currencyFallback : v;
        } catch (e) {
            return 30000;
        }
    }

    function tbiHandler() {
        var iframe = getByID('tbi_popup_iframe');

        if (getByID('tbi_button') == null) return;

        var tbi_container = getByID('tbi_button_container');
        var tbi_button    = getByID('tbi_button');
        var tbi_popup     = getByID('tbi_popup_container');
        var tbi_loading   = getByID('tbi_loading');

        // BG: detect woobt-wrap for bundle products
        var form = document.querySelector('form.cart');
        if (document.querySelector('.woobt-wrap') !== null) {
            form = document.querySelector('.entry-summary');

            function woobtCalculateTotal() {
                var mainPrice = parseFloat(getByID('tbi_button_body').getAttribute('data-price')) || 0;
                var bundleExtra = 0;
                document.querySelectorAll('.woobt-product.woobt-product-together').forEach(function(item) {
                    var checkbox = item.querySelector('.woobt-checkbox');
                    if (!checkbox || !checkbox.checked) return;
                    var rawPrice     = parseFloat(item.getAttribute('data-price')) || 0;
                    var newPriceAttr = item.getAttribute('data-new-price') || '';
                    if (newPriceAttr.indexOf('%') !== -1) {
                        rawPrice = rawPrice * (parseFloat(newPriceAttr) / 100);
                    } else if (newPriceAttr !== '') {
                        rawPrice = parseFloat(newPriceAttr) || rawPrice;
                    }
                    bundleExtra += rawPrice;
                });
                return mainPrice + bundleExtra;
            }

            document.querySelectorAll('.woobt-checkbox').forEach(function(cb) {
                cb.addEventListener('change', delay(function() {
                    var total = woobtCalculateTotal();
                    if (total <= 0) return;
                    updateButtonWithAPI(total, 1, 0);
                }, 500));
            });
        }

        var tbi_current_cart      = getFormData('form.cart');
        var tbi_current_price     = getByID('tbi_button_body').getAttribute('data-price');
        var tbi_current_quantity  = tbi_current_cart.quantity;
        var tbi_current_variation = tbi_current_cart.variation_id || 0;
        var tbi_current_cpf_price = tbi_current_cart.cpf_product_price ? parseFloat(tbi_current_cart.cpf_product_price) : null;
        var tbi_delivery_limit    = getByID('tbi_button_body').getAttribute('data-delivery-limit');
        var tbi_delivery_fee      = getByID('tbi_button_body').getAttribute('data-delivery-fee');

        // WooCommerce Product Bundles can have base product price = 0 and a live
        // total in the bundle widget. Seed the button from that live total.
        var initialBundleTotal = getWooCommerceBundleTotal(form);
        if (initialBundleTotal !== null && initialBundleTotal > 0) {
            // Also add any WCPA option extras already selected on page load
            var initWcpaOptEl = document.querySelector('.wcpa_options_total');
            var initWcpaExtra = 0;
            if (initWcpaOptEl) {
                initWcpaExtra = parseLocalePrice(initWcpaOptEl.textContent) || 0;
                initialBundleTotal += initWcpaExtra;
            }
            console.log('[TBI] Initial seed — bundleBase:', (initialBundleTotal - initWcpaExtra).toFixed(2), '| wcpaExtra:', initWcpaExtra.toFixed(2), '| total:', initialBundleTotal.toFixed(2));
            tbi_current_price = initialBundleTotal;
            setButtonDataPrice(initialBundleTotal);
            applyButtonVisibility(initialBundleTotal, tbi_container);
            updateButtonWithAPI(initialBundleTotal, 1, 0);
        }

        // WooCommerce Product Bundles (SomewhereWarm) + WCPA Add-Ons:
        // Watch for bundle optional-item checkbox changes (price updated via WC Bundles AJAX)
        // and WCPA radio/option changes (price updated synchronously by WCPA JS).
        if (form && form.classList && form.classList.contains('bundle_form') && !fptbi_bundleObserverSet) {
            fptbi_bundleObserverSet = true;

            var readBundleWcpaTotal = function() {
                // Prefer the <ins> (sale) price from .bundle_price; fall back to getWooCommerceBundleTotal
                var bundleBase = 0;
                var insEl = document.querySelector('.bundle_price ins .woocommerce-Price-amount.amount, .bundle_price ins .woocommerce-Price-amount');
                if (insEl) {
                    bundleBase = parseLocalePrice(insEl.textContent) || 0;
                    console.log('[TBI] readBundleWcpaTotal — .bundle_price <ins>:', insEl.textContent.trim(), '→', bundleBase);
                }
                if (!bundleBase || bundleBase <= 0) {
                    var fallback = getWooCommerceBundleTotal(form);
                    bundleBase = (fallback !== null && fallback > 0) ? fallback : 0;
                    console.log('[TBI] readBundleWcpaTotal — fallback getWooCommerceBundleTotal:', bundleBase);
                }
                // Add WCPA option extras (e.g. personalisation add-ons)
                var wcpaExtra = 0;
                var wcpaOptEl = document.querySelector('.wcpa_options_total');
                if (wcpaOptEl) {
                    wcpaExtra = parseLocalePrice(wcpaOptEl.textContent) || 0;
                    console.log('[TBI] readBundleWcpaTotal — .wcpa_options_total text:', wcpaOptEl.textContent.trim(), '→', wcpaExtra);
                }
                var combined = bundleBase + wcpaExtra;
                console.log('[TBI] readBundleWcpaTotal — TOTAL:', combined.toFixed(2), '(base', bundleBase.toFixed(2), '+ wcpa', wcpaExtra.toFixed(2), ')');
                return combined;
            };

            var onBundleOrWcpaChange = delay(function() {
                console.log('[TBI] onBundleOrWcpaChange fired (DOM mutation)');
                var total = readBundleWcpaTotal();
                if (!total || total <= 0) { console.log('[TBI] onBundleOrWcpaChange — total <= 0, skipping'); return; }
                var currentPrice = parseFloat((getByID('tbi_button_body') || {}).getAttribute && getByID('tbi_button_body').getAttribute('data-price') || '0');
                console.log('[TBI] onBundleOrWcpaChange — newTotal:', total.toFixed(2), '| currentDataPrice:', currentPrice.toFixed(2), '| delta:', (total - currentPrice).toFixed(2));
                if (Math.abs(total - currentPrice) < 0.01) { console.log('[TBI] onBundleOrWcpaChange — price unchanged, skipping API call'); return; }
                setButtonDataPrice(total);
                applyButtonVisibility(total, tbi_container);
                iframe.src = '';
                updateButtonWithAPI(total, 1, 0);
            }, 350);

            if (typeof MutationObserver !== 'undefined') {
                // WooCommerce Bundles AJAX updates .bundle_price after optional items change
                var bundlePriceEl = document.querySelector('.bundle_price');
                if (bundlePriceEl) {
                    console.log('[TBI] MutationObserver attached to .bundle_price');
                    new MutationObserver(function(mutations) {
                        console.log('[TBI] .bundle_price DOM mutation detected', mutations.length, 'mutation(s)');
                        onBundleOrWcpaChange();
                    }).observe(bundlePriceEl, { childList: true, subtree: true, characterData: true });
                } else {
                    console.warn('[TBI] .bundle_price not found — observer NOT attached');
                }
                // WCPA updates .wcpa_total synchronously when options change
                var wcpaTotalEl = document.querySelector('.wcpa_total');
                if (wcpaTotalEl) {
                    console.log('[TBI] MutationObserver attached to .wcpa_total');
                    new MutationObserver(function(mutations) {
                        console.log('[TBI] .wcpa_total DOM mutation detected', mutations.length, 'mutation(s)');
                        onBundleOrWcpaChange();
                    }).observe(wcpaTotalEl, { childList: true, subtree: true, characterData: true });
                } else {
                    console.warn('[TBI] .wcpa_total not found — observer NOT attached');
                }
            }
        }

        if (form && !fptbi_formEvent) {

            form.addEventListener('click', delay(function() {
                var new_cart      = getFormData('form.cart');
                var new_cpf_price = new_cart.cpf_product_price ? parseFloat(new_cart.cpf_product_price) : null;
                var tbi_actual_delivery = 0;
                var currentTotalForDelivery = resolveDisplayTotal(form, tbi_current_price, new_cart.quantity, 0, tbi_delivery_limit, tbi_delivery_fee);
                if (currentTotalForDelivery < parseFloat(tbi_delivery_limit || 0)) {
                    tbi_actual_delivery = tbi_delivery_fee;
                }
                var effective_price = new_cpf_price !== null ? new_cpf_price : parseFloat(tbi_current_price);
                var calculate_price = resolveDisplayTotal(form, effective_price, new_cart.quantity, tbi_actual_delivery, tbi_delivery_limit, tbi_delivery_fee);
                applyButtonVisibility(calculate_price, tbi_container);

                // Always reset so the button triggers a fresh calculation on next click
                iframe.src = '';
                tbi_loading.style.display = 'block';

                if (new_cart.variation_id == undefined || new_cart.variation_id == 0) {
                    if (new_cpf_price !== null && new_cpf_price !== tbi_current_cpf_price) {
                        updateButtonWithAPI(calculate_price, 1, 0);
                        tbi_current_cpf_price = new_cpf_price;
                        tbi_current_quantity  = new_cart.quantity;
                    } else if (new_cart.quantity != tbi_current_quantity) {
                        updateButtonWithAPI(calculate_price, 1, 0);
                        tbi_current_quantity = new_cart.quantity;
                    }
                } else {
                    if (new_cart.variation_id != tbi_current_variation) {
                        tbi_current_price = getByID('tbi_button_body').getAttribute('data-' + new_cart.variation_id);
                        calculate_price = resolveDisplayTotal(form, tbi_current_price, new_cart.quantity, tbi_actual_delivery, tbi_delivery_limit, tbi_delivery_fee);
                        updateButtonWithAPI(calculate_price, 1, 0);
                        tbi_current_quantity  = new_cart.quantity;
                        tbi_current_variation = new_cart.variation_id;
                    } else if (new_cpf_price !== null && new_cpf_price !== tbi_current_cpf_price) {
                        updateButtonWithAPI(calculate_price, 1, 0);
                        tbi_current_cpf_price = new_cpf_price;
                        tbi_current_quantity  = new_cart.quantity;
                    }
                }

                setButtonDataPrice(calculate_price);
            }, 500));

            form.addEventListener('change', delay(function() {
                var new_cart      = getFormData('form.cart');
                var new_cpf_price = new_cart.cpf_product_price ? parseFloat(new_cart.cpf_product_price) : null;
                var tbi_actual_delivery = 0;
                var currentTotalForDelivery = resolveDisplayTotal(form, tbi_current_price, new_cart.quantity, 0, tbi_delivery_limit, tbi_delivery_fee);
                if (currentTotalForDelivery < parseFloat(tbi_delivery_limit || 0)) {
                    tbi_actual_delivery = tbi_delivery_fee;
                }
                var effective_price = new_cpf_price !== null ? new_cpf_price : parseFloat(tbi_current_price);
                var calculate_price = resolveDisplayTotal(form, effective_price, new_cart.quantity, tbi_actual_delivery, tbi_delivery_limit, tbi_delivery_fee);
                applyButtonVisibility(calculate_price, tbi_container);

                // Always reset so the button triggers a fresh calculation on next click
                iframe.src = '';
                tbi_loading.style.display = 'block';

                if (new_cart.variation_id == undefined || new_cart.variation_id == 0) {
                    if (new_cpf_price !== null && new_cpf_price !== tbi_current_cpf_price) {
                        updateButtonWithAPI(calculate_price, 1, 0);
                        tbi_current_cpf_price = new_cpf_price;
                        tbi_current_quantity  = new_cart.quantity;
                    } else if (new_cart.quantity != tbi_current_quantity) {
                        updateButtonWithAPI(calculate_price, 1, 0);
                        tbi_current_quantity = new_cart.quantity;
                    }
                } else {
                    if (new_cart.variation_id != tbi_current_variation || new_cart.quantity != tbi_current_quantity) {
                        tbi_current_price = getByID('tbi_button_body').getAttribute('data-' + new_cart.variation_id);
                        calculate_price = resolveDisplayTotal(form, tbi_current_price, new_cart.quantity, tbi_actual_delivery, tbi_delivery_limit, tbi_delivery_fee);
                        updateButtonWithAPI(calculate_price, 1, 0);
                        tbi_current_quantity  = new_cart.quantity;
                        tbi_current_variation = new_cart.variation_id;
                    } else if (new_cpf_price !== null && new_cpf_price !== tbi_current_cpf_price) {
                        updateButtonWithAPI(calculate_price, 1, 0);
                        tbi_current_cpf_price = new_cpf_price;
                        tbi_current_quantity  = new_cart.quantity;
                    }
                }

                setButtonDataPrice(calculate_price);
            }, 500));

            fptbi_formEvent = true;
        }

        if (fptbi_buttonEvent === false) {
            tbi_button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                try {
                    var buttonBody   = getByID('tbi_button_body');
                    var minAmount    = buttonBody ? parseFloat(buttonBody.getAttribute('data-min-amount')) : NaN;
                    var maxAmount    = buttonBody ? parseFloat(buttonBody.getAttribute('data-max-amount')) : NaN;
                    var alertMsg     = buttonBody ? (buttonBody.getAttribute('data-range-alert') || '') : '';
                    var bnplMin      = buttonBody ? parseFloat(buttonBody.getAttribute('data-visible')) : NaN;
                    var bnplMax      = buttonBody ? parseFloat(buttonBody.getAttribute('data-hide-bnpl')) : NaN;
                    var dataTmp      = getFormData('form.cart');
                    var qtyTmp       = dataTmp && dataTmp.quantity ? parseFloat(dataTmp.quantity) : 1;
                    var priceTmp     = parseFloat(buttonBody ? buttonBody.getAttribute('data-price') : '0') / (qtyTmp || 1);
                    var delLimitTmp  = parseFloat(buttonBody ? buttonBody.getAttribute('data-delivery-limit') : '0');
                    var delFeeTmp    = parseFloat(buttonBody ? buttonBody.getAttribute('data-delivery-fee') : '0');
                    var total        = resolveDisplayTotal(form, priceTmp, qtyTmp, 0, delLimitTmp, delFeeTmp);
                    var isBnplEligible = !isNaN(total) && !isNaN(bnplMin) && !isNaN(bnplMax) && total >= bnplMin && total <= bnplMax;
                    if (!isBnplEligible && !isNaN(total) && ((!isNaN(minAmount) && total < minAmount) || (!isNaN(maxAmount) && total > maxAmount))) {
                        alert(alertMsg || 'Amount outside allowed loan range.');
                        return;
                    }
                } catch (err) {
                    // Ignore.
                }

                if (hasBlockingOutOfStock(form)) {
                    alert(getByID('tbi_button_body').getAttribute('data-alert1'));
                    return;
                }

                if (iframe.getAttribute('src') === '') {
                    var data = getFormData('form.cart');
                    // BG: WCPA plugin price override
                    if (document.querySelector('.wcpa_total .price_value') != null) {
                        data.wcpa_price = document.querySelector('.wcpa_total .price_value').textContent.trim().replace(',', '.');
                    }
                    // Filter out unnecessary keys
                    var filteredData = {};
                    for (var key in data) {
                        if (!key.startsWith('fishinggear') && !key.startsWith('xts') && !key.startsWith('wd')) {
                            filteredData[key] = data[key];
                        }
                    }
                    filteredData.action = 'fusipatb_process_product';
                    filteredData.currency = getActiveCurrency();

                    if (getByID('tbi_button_body').getAttribute('data-variable') == 1 && (!filteredData.variation_id || filteredData.variation_id == 0)) {
                        alert(getByID('tbi_button_body').getAttribute('data-alert'));
                    } else {
                        tbi_popup.style.display      = 'flex';
                        document.body.style.overflow = 'hidden';

                        var formParams = new URLSearchParams();
                        for (var k in filteredData) {
                            if (Array.isArray(filteredData[k])) {
                                filteredData[k].forEach(function(v) { formParams.append(k, v); });
                            } else {
                                formParams.append(k, filteredData[k]);
                            }
                        }

                        var bundleTotalForRequest = getWooCommerceBundleTotal(form);
                        if (bundleTotalForRequest !== null && bundleTotalForRequest > 0) {
                            var wcpaOptForRequest = document.querySelector('.wcpa_options_total');
                            var wcpaExtraForRequest = 0;
                            if (wcpaOptForRequest) {
                                wcpaExtraForRequest = parseLocalePrice(wcpaOptForRequest.textContent) || 0;
                                bundleTotalForRequest += wcpaExtraForRequest;
                            }
                            formParams.append('bundle_total', bundleTotalForRequest.toFixed(2));
                        }
                        
                        // Add nonce for AJAX security
                        if (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.nonce) {
                            formParams.append('_wpnonce', fusipatbConfig.nonce);
                        }

                        fetchWithTimeout(ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: formParams.toString()
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(response) {
                            if (response.success) {
                                if (response.data && response.data.debug) {
                                    console.log('[TBI] Server debug payload (direct click):', response.data.debug);
                                }
                                var iframeUrl = response.data.url;
                                iframeUrl = patchIframeUrlAmount(iframeUrl);
                                iframeUrl = appendModalParams(iframeUrl, iframe);
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
                } else {
                    tbi_popup.style.display      = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            });
            fptbi_buttonEvent = true;
        }

        if (!fptbi_windowEvent) {
            iframe.addEventListener('click', function(e) { e.stopPropagation(); });

            window.addEventListener('click', function() {
                tbi_popup.style.display      = 'none';
                document.body.style.overflow = 'auto';
            });

            window.addEventListener('message', function(e) {
                if (e.origin !== FPTBI_ORIGIN) return;
                try {
                    var jsonObject    = JSON.parse(e.data);
                    var redirect_base = getByID('tbi_popup_container').getAttribute('data-redirect');

                    if (redirect_base.indexOf('?') > -1) {
                        redirect_base = redirect_base + '&order-received=' + jsonObject.order_id + '&key=' + jsonObject.wc_key;
                    } else {
                        redirect_base = redirect_base + '/order-received/' + jsonObject.order_id + '/?key=' + jsonObject.wc_key;
                    }

                    if (jsonObject.command === 'allow_close') {
                        window.addEventListener('click', function() {
                            window.location = redirect_base;
                        });
                    }
                    if (jsonObject.command === 'close' && jsonObject.order_id != undefined) {
                        window.location = redirect_base;
                    }
                    if (jsonObject.command === 'close' && jsonObject.order_id == undefined) {
                        tbi_popup.style.display      = 'none';
                        document.body.style.overflow = 'auto';
                    }
                    if (jsonObject.command === 'close_calculator' && jsonObject.order_id == undefined) {
                        tbi_popup.style.display      = 'none';
                        document.body.style.overflow = 'auto';
                    }

                    // BG: scheme_id / downpayment — store via AJAX then submit add-to-cart
                    if (jsonObject.scheme_id !== undefined || jsonObject.downpayment !== undefined) {

                        const params = new URLSearchParams({
                            action: 'fusipatb_set_session_data'
                        });

                        if (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.sessionNonce) {
                            params.append('_wpnonce', fusipatbConfig.sessionNonce);
                        }

                        if (jsonObject.scheme_id !== undefined) {
                            params.append('scheme_id', jsonObject.scheme_id);
                        }

                        if (jsonObject.downpayment !== undefined) {
                            params.append('downpayment', jsonObject.downpayment);
                        }

                        fetch(ajaxUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: params
                        })
                        .then(function(res) {
                            if (!res.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return res.json();
                        })
                        .then(function(response) {
                            if (response.success) {
                                tbi_popup.style.display = 'none';
                                document.body.style.overflow = 'auto';

                                triggerAddToCartSubmission();

                            } else {
                                alert('Failed to store financing info.');
                            }
                        })
                        .catch(function() {
                            alert('An error occurred while storing financing info.');
                        });
                    }
                } catch (error) {
                    console.error('Invalid JSON string:', error);
                }
            });
            fptbi_windowEvent = true;
        }
    }

    // -----------------------------------------------------------------------
    // API-driven button update (BG approach) with local calculation fallback
    // -----------------------------------------------------------------------

    function updateButtonWithAPI(price, quantity, delivery) {
        var totalAmount = parseFloat(price * quantity) + parseFloat(delivery);
        showTBIButtonLoading();

        tbiCalculateInstallments(totalAmount).then(function(apiData) {
            if (apiData && apiData.success) {
                updateButtonWithAPIData(apiData);
            } else {
                var bnplLimit = parseFloat(getByID('tbi_button_body').getAttribute('data-hide-bnpl'));
                if (!isNaN(bnplLimit) && totalAmount <= bnplLimit) {
                    bnplLabel(price, quantity, delivery);
                } else {
                    posLabel(price, quantity, delivery);
                }
            }
        }).catch(function() {
            posLabel(price, quantity, delivery);
        }).then(function() {
            hideTBIButtonLoading();
        });
    }

    function getTbiCurrency() {
        var popupEl = getByID('tbi_popup_container');
        if (!popupEl) return null;
        var calcUrl = popupEl.getAttribute('data-calculator');
        if (!calcUrl || calcUrl.indexOf('?') === -1) return null;
        return new URLSearchParams(calcUrl.split('?')[1]).get('currency');
    }

    function tbiCalculateInstallments(amount) {
        if (typeof ajaxUrl === 'undefined' || !ajaxUrl) {
            return Promise.resolve(null);
        }
        var formData = new URLSearchParams();
        formData.append('action', 'fusipatb_calculate_installments_ajax');
        formData.append('amount', parseFloat(amount).toFixed(2));
        formData.append('currency', getActiveCurrency());
        if (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.installmentsNonce) {
            formData.append('_wpnonce', fusipatbConfig.installmentsNonce);
        }

        return fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.data) {
                return {
                    success:                    true,
                    button_text:                data.data.button_text,
                    installment:                data.data.installment_amount,
                    period:                     data.data.installments,
                    modal_section:              data.data.modal_section,
                    mode:                       data.data.mode,
                    scheme_id:                  data.data.scheme_id,
                    default_installments:       data.data.default_installments,
                    default_installment_amount: data.data.default_installment_amount
                };
            }
            return null;
        })
        .catch(function() { return null; });
    }

    function updateButtonWithAPIData(apiData) {
        var buttonLine = getButtonLine();
        if (!buttonLine) return;

        var label = '';
        if (apiData.button_text) {
            label = apiData.button_text;
        } else {
            var body = getByID('tbi_button_body');
            var tmpl = body ? (body.getAttribute('data-label') || '') : '';
            var eur  = body ? parseFloat(body.getAttribute('data-eur') || '0') : 0;
            if (!isFinite(eur) || eur < 0) eur = 0;
            var currency = body ? (body.getAttribute('data-currency') || 'BGN') : 'BGN';
            var installment = parseFloat(apiData.installment || apiData.installment_amount || 0).toFixed(2);
            if (tmpl) {
                if (eur > 0) {
                    if (currency.toUpperCase() === 'EUR') {
                        // API returns EUR; {installment}=BGN, {eur_installment}=EUR
                        var bgnInstallment = (parseFloat(installment) * eur).toFixed(2);
                        label = tmpl
                            .replace('{installments}', apiData.period || '')
                            .replace('{eur_installment}', installment)
                            .replace('{installment}', bgnInstallment);
                    } else {
                        // API returns BGN; {installment}=BGN, {eur_installment}=EUR
                        var eurInstallment = (parseFloat(installment) / eur).toFixed(2);
                        label = tmpl
                            .replace('{installments}', apiData.period || '')
                            .replace('{eur_installment}', eurInstallment)
                            .replace('{installment}', installment);
                    }
                } else {
                    label = tmpl
                        .replace('{installments}', apiData.period || '')
                        .replace('{installment}', installment);
                }
            } else {
                label = (apiData.period || '') + ' x ' + installment;
            }
        }

        buttonLine.innerHTML = '';
        buttonLine.innerText = label;
        buttonLine.removeAttribute('data-original-text');

        storeModalConfig(apiData);
    }

    // -----------------------------------------------------------------------
    // Local label calculation (fallback when API unavailable)
    // Merged: BG EUR support + RO dynamic tiers + RO analysis fees
    // -----------------------------------------------------------------------

    // Standard (non-BNPL) installment label — uses dynamic tiers (RO approach)
    function posLabel(price, quantity, delivery) {
        var body       = getByID('tbi_button_body');
        var new_total  = parseFloat(price * quantity) + parseFloat(delivery);

        // Analysis fees (RO feature, optional — 0 when not set)
        var analysisFeeStd = parseFloat(body.getAttribute('data-standard-analysis-fee') || '0') || 0;
        new_total += analysisFeeStd;

        // EUR rate for dual-currency display (BG feature)
        var eur = parseFloat(body.getAttribute('data-eur') || '1');
        if (!isFinite(eur) || eur <= 0) eur = 1;

        var new_label  = body.getAttribute('data-label') || '';
        var multiplier = 0;
        var period     = 0;
        var tier_count = parseInt(body.getAttribute('data-tier-count') || '0') || 0;
        var bnplLimit  = parseFloat(body.getAttribute('data-hide-bnpl') || '0');

        // Dynamic tier matching (RO approach — works for all countries)
        var prev_tier_limit = bnplLimit;
        for (var i = 1; i <= tier_count; i++) {
            var current_tier_limit = parseFloat(body.getAttribute('data-t' + i + '-limit') || '0');
            if (new_total > prev_tier_limit && new_total <= current_tier_limit) {
                period     = body.getAttribute('data-t' + i);
                multiplier = body.getAttribute('data-f' + i);
                break;
            }
            prev_tier_limit = current_tier_limit;
        }

        // Fallback to data-f2 / period 12 if no tier matched (BG legacy behavior)
        if (!multiplier || multiplier <= 0) {
            multiplier = parseFloat(body.getAttribute('data-f2') || '0') || (1 / 12);
            period     = 12;
        }

        var new_installment    = parseFloat(multiplier * new_total).toFixed(2);
        var currency = body ? (body.getAttribute('data-currency') || 'BGN') : 'BGN';
        if (currency.toUpperCase() === 'EUR') {
            // Store is EUR: new_installment is EUR; {installment}=BGN, {eur_installment}=EUR
            var bgn_installment = (parseFloat(new_installment) * eur).toFixed(2);
            new_label = new_label.replace('{eur_installment}', new_installment);
            new_label = new_label.replace('{installment}', bgn_installment);
        } else {
            // Store is BGN: new_installment is BGN; {installment}=BGN, {eur_installment}=EUR
            var eur_installment = parseFloat(new_installment / eur).toFixed(2);
            new_label = new_label.replace('{installment}', new_installment);
            new_label = new_label.replace('{eur_installment}', eur_installment);
        }
        new_label = new_label.replace('{installments}', period);

        var buttonLine = getButtonLine();
        if (buttonLine) {
            buttonLine.innerHTML = '';
            buttonLine.innerText = new_label;
            buttonLine.removeAttribute('data-original-text');
        }
    }

    // BNPL installment label (BG approach with analysis fee support from RO)
    function bnplLabel(price, quantity, delivery) {
        var body      = getByID('tbi_button_body');
        var new_price = parseFloat(price * quantity) + parseFloat(delivery);

        // Analysis fee for BNPL (RO feature, optional)
        var analysisFee = parseFloat(body.getAttribute('data-bnpl-analysis-fee') || '0') || 0;
        new_price += analysisFee;

        // Check for data-multiplier-bnpl / data-period-bnpl (dynamic approach)
        var multiplier_bnpl = parseFloat(body.getAttribute('data-multiplier-bnpl') || '0');
        var period_bnpl     = body.getAttribute('data-period-bnpl') || '4';
        var new_installment;
        if (multiplier_bnpl > 0) {
            new_installment = parseFloat(multiplier_bnpl * new_price).toFixed(2);
        } else {
            // BG fallback: ceil(price/4 - 0.5)
            new_installment = parseFloat(Math.ceil(new_price / 4 - 0.5)).toFixed(2);
        }

        var eur = parseFloat(body.getAttribute('data-eur') || '1');
        if (!isFinite(eur) || eur <= 0) eur = 1;
        var currency = body ? (body.getAttribute('data-currency') || 'BGN') : 'BGN';

        var new_label = body.getAttribute('data-label-bnpl') || '';
        if (currency.toUpperCase() === 'EUR') {
            // Store is EUR: new_installment is EUR; {installment}=BGN, {eur_installment}=EUR
            var bgn_installment = (parseFloat(new_installment) * eur).toFixed(2);
            new_label = new_label.replace('{eur_installment}', new_installment);
            new_label = new_label.replace('{installment}', bgn_installment);
        } else {
            // Store is BGN: new_installment is BGN; {installment}=BGN, {eur_installment}=EUR
            var eur_installment = parseFloat(new_installment / eur).toFixed(2);
            new_label = new_label.replace('{installment}', new_installment);
            new_label = new_label.replace('{eur_installment}', eur_installment);
        }
        new_label = new_label.replace('{installments}', period_bnpl);

        // BG: fix legacy label format ("X BGN (Y)" -> "Y € (X BGN)")
        if (new_label && new_label.indexOf('BGN') > -1 && new_label.indexOf('(') > -1 && new_label.indexOf('€') === -1) {
            new_label = new_label.replace(/([0-9][0-9.,]*)\s*BGN\s*\(([0-9][0-9.,]*)\)/, '$2 € ($1 BGN)');
        }

        var buttonLine = getButtonLine();
        if (buttonLine) {
            buttonLine.innerHTML = '';
            buttonLine.innerText = new_label;
            buttonLine.removeAttribute('data-original-text');
        }
    }

    // -----------------------------------------------------------------------
    // Loading animation (BG braille dots)
    // -----------------------------------------------------------------------

    function showTBIButtonLoading() {
        var buttonLine = getButtonLine();
        var tbiButton  = getByID('tbi_button');
        if (buttonLine) {
            buttonLine.setAttribute('data-original-text', buttonLine.innerText);
            buttonLine.innerHTML = '<span class="tbi-loading">Calculating... <span class="tbi-dots">\u280B</span></span>';
            if (tbiButton) tbiButton.classList.add('loading');
            startLoadingAnimation();
        }
    }

    function hideTBIButtonLoading() {
        var buttonLine = getButtonLine();
        var tbiButton  = getByID('tbi_button');
        if (buttonLine) {
            stopLoadingAnimation();
            if (tbiButton) tbiButton.classList.remove('loading');
            var originalText = buttonLine.getAttribute('data-original-text');
            if (originalText && buttonLine.innerHTML.indexOf('tbi-loading') > -1) {
                buttonLine.innerText = originalText;
            }
            buttonLine.removeAttribute('data-original-text');
        }
    }

    function startLoadingAnimation() {
        var dots  = ['\u280B', '\u2819', '\u2839', '\u2838', '\u283C', '\u2834', '\u2826', '\u2827', '\u2807', '\u280F'];
        var index = 0;
        stopLoadingAnimation();
        window.tbiLoadingInterval = setInterval(function() {
            var dotsElement = document.querySelector('.tbi-dots');
            if (dotsElement) {
                dotsElement.textContent = dots[index];
                index = (index + 1) % dots.length;
            } else {
                stopLoadingAnimation();
            }
        }, 100);
    }

    function stopLoadingAnimation() {
        if (window.tbiLoadingInterval) {
            clearInterval(window.tbiLoadingInterval);
            window.tbiLoadingInterval = null;
        }
    }

    // -----------------------------------------------------------------------
    // Event delegation — works with Elementor / dynamic content (BG)
    // -----------------------------------------------------------------------

    function setupEventDelegation() {
        document.body.addEventListener('click', function(e) {
            var tbiButton = e.target.closest ? e.target.closest(
                '#tbi_button, #tbi_button_body, [id^="tbi_button"]:not([id^="tbi_button_"]), [id^="tbi_button_body"], [class^="tbi_button"]:not([class^="tbi_button_"]), [class^="tbi_button_body"]'
            ) : null;
            if (!tbiButton) return;

            e.preventDefault();
            e.stopPropagation();

            var iframe    = getByID('tbi_popup_iframe');
            var tbi_popup = getByID('tbi_popup_container');
            var tbi_loading = getByID('tbi_loading');
            var form = document.querySelector('form.cart');

            if (!iframe || !tbi_popup) {
                tbiHandler();
                return;
            }

            if (hasBlockingOutOfStock(form)) {
                var alertMsg1 = getByID('tbi_button_body') ? getByID('tbi_button_body').getAttribute('data-alert1') : 'Product is out of stock';
                alert(alertMsg1 || 'Product is out of stock');
                return;
            }

            try {
                var buttonBody   = getByID('tbi_button_body');
                var minAmount    = buttonBody ? parseFloat(buttonBody.getAttribute('data-min-amount')) : NaN;
                var maxAmount    = buttonBody ? parseFloat(buttonBody.getAttribute('data-max-amount')) : NaN;
                var rangeAlert   = buttonBody ? (buttonBody.getAttribute('data-range-alert') || '') : '';
                var bnplMin      = buttonBody ? parseFloat(buttonBody.getAttribute('data-visible')) : NaN;
                var bnplMax      = buttonBody ? parseFloat(buttonBody.getAttribute('data-hide-bnpl')) : NaN;
                var dataTmp      = getFormData('form.cart');
                var qtyTmp       = dataTmp && dataTmp.quantity ? parseFloat(dataTmp.quantity) : 1;
                var priceTmp     = parseFloat(buttonBody ? buttonBody.getAttribute('data-price') : '0');
                var delLimitTmp  = parseFloat(buttonBody ? buttonBody.getAttribute('data-delivery-limit') : '0');
                var delFeeTmp    = parseFloat(buttonBody ? buttonBody.getAttribute('data-delivery-fee') : '0');
                var total           = resolveDisplayTotal(form, priceTmp, qtyTmp, 0, delLimitTmp, delFeeTmp);
                var isBnplEligible  = !isNaN(total) && !isNaN(bnplMin) && !isNaN(bnplMax) && total >= bnplMin && total <= bnplMax;
                if (!isBnplEligible && !isNaN(total) && ((!isNaN(minAmount) && total < minAmount) || (!isNaN(maxAmount) && total > maxAmount))) {
                    alert(rangeAlert || 'Amount outside allowed loan range.');
                    return;
                }
            } catch (err) {
                // Ignore.
            }

            if (iframe.getAttribute('src') === '' || !iframe.getAttribute('src')) {
                var data = getFormData('form.cart');

                // WCPA plugin price override (BG)
                if (document.querySelector('.wcpa_total .price_value') != null) {
                    data.wcpa_price = document.querySelector('.wcpa_total .price_value').textContent.trim().replace(',', '.');
                }

                var filteredData = {};
                for (var key in data) {
                    if (!key.startsWith('fishinggear') && !key.startsWith('xts') && !key.startsWith('wd')) {
                        filteredData[key] = data[key];
                    }
                }
                filteredData.action = 'fusipatb_process_product';
                filteredData.currency = getActiveCurrency();

                var buttonBodyEl = getByID('tbi_button_body');
                if (buttonBodyEl && buttonBodyEl.getAttribute('data-variable') == 1 && (!filteredData.variation_id || filteredData.variation_id == 0)) {
                    alert(buttonBodyEl.getAttribute('data-alert') || 'Please select product variation');
                    return;
                }

                tbi_popup.style.display      = 'flex';
                document.body.style.overflow = 'hidden';
                if (tbi_loading) tbi_loading.style.display = 'block';

                var formParams = new URLSearchParams();
                for (var k in filteredData) {
                    if (Array.isArray(filteredData[k])) {
                        filteredData[k].forEach(function(v) { formParams.append(k, v); });
                    } else {
                        formParams.append(k, filteredData[k]);
                    }
                }

                var bundleTotalForRequest = getWooCommerceBundleTotal(form);
                if (bundleTotalForRequest !== null && bundleTotalForRequest > 0) {
                    var wcpaOptForReq = document.querySelector('.wcpa_options_total');
                    var wcpaExtraForReq = 0;
                    if (wcpaOptForReq) {
                        wcpaExtraForReq = parseLocalePrice(wcpaOptForReq.textContent) || 0;
                        bundleTotalForRequest += wcpaExtraForReq;
                    }
                    console.log('[TBI] Button click (delegated) — POSTing bundle_total:', bundleTotalForRequest.toFixed(2), '(wcpaExtra:', wcpaExtraForReq.toFixed(2), ')');
                    formParams.append('bundle_total', bundleTotalForRequest.toFixed(2));
                }

                if (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.nonce) {
                    formParams.append('_wpnonce', fusipatbConfig.nonce);
                }

                fetchWithTimeout(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: formParams.toString()
                })
                .then(function(response) { return response.json(); })
                .then(function(response) {
                    if (response.success) {
                        if (response.data && response.data.debug) {
                            console.log('[TBI] Server debug payload (delegated click):', response.data.debug);
                        }
                        var iframeUrl = response.data.url;
                        iframeUrl = patchIframeUrlAmount(iframeUrl);
                        iframeUrl = appendModalParams(iframeUrl, iframe);
                        console.log('[TBI] Calculator URL (delegated click):', iframeUrl);
                        iframe.src = iframeUrl;
                    } else {
                        throw new Error(response.data ? response.data.message : 'Error');
                    }
                    if (tbi_loading) tbi_loading.style.display = 'none';
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
                    if (tbi_loading) tbi_loading.style.display = 'none';
                });
            } else {
                tbi_popup.style.display      = 'flex';
                document.body.style.overflow = 'hidden';
            }
        });
    }

    // -----------------------------------------------------------------------
    // MutationObserver — for Elementor / late-rendered buttons (BG)
    // -----------------------------------------------------------------------

    function setupMutationObserver() {
        if (getByID('tbi_button') || getByID('tbi_button_body')) return;

        var observer = new MutationObserver(function(mutations) {
            for (var m = 0; m < mutations.length; m++) {
                var mutation = mutations[m];
                if (mutation.addedNodes.length > 0) {
                    for (var n = 0; n < mutation.addedNodes.length; n++) {
                        var node = mutation.addedNodes[n];
                        if (node.nodeType === 1) {
                            if (node.id === 'tbi_button' || node.id === 'tbi_button_body' ||
                                /^tbi_button\d/.test(node.id) || /^tbi_button_body\d/.test(node.id) ||
                                (node.querySelector && (
                                    node.querySelector('#tbi_button, [id^="tbi_button"]:not([id^="tbi_button_"])') ||
                                    node.querySelector('#tbi_button_body, [id^="tbi_button_body"]')
                                ))) {
                                tbiHandler();
                                break;
                            }
                        }
                    }
                }
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Replace the `amount` query-param in a calculator URL with the correct
     * current total (data-price on tbi_button_body).
     * This compensates for the server building the URL with the base product
     * price before WooCommerce Bundles or WCPA add-ons are factored in.
     */
    function patchIframeUrlAmount(url) {
        var body = getByID('tbi_button_body');
        if (!body || !url) return url;
        var correctAmount = parseFloat(body.getAttribute('data-price') || '0');
        if (!isFinite(correctAmount) || correctAmount <= 0) return url;
        try {
            var parts = url.split('?');
            if (parts.length < 2) return url;
            var params = new URLSearchParams(parts[1]);
            var serverAmount = parseFloat(params.get('amount') || '0');
            if (Math.abs(serverAmount - correctAmount) > 0.01) {
                console.log('[TBI] patchIframeUrlAmount — replacing server amount', serverAmount, '→', correctAmount.toFixed(2));
                params.set('amount', correctAmount.toFixed(2));
                return parts[0] + '?' + params.toString();
            }
        } catch (e) {
            // URLSearchParams not available (very old browser) — fall back to regex
            var patched = url.replace(/([?&]amount=)[^&]+/, '$1' + correctAmount.toFixed(2));
            console.log('[TBI] patchIframeUrlAmount (regex) — url patched:', patched);
            return patched;
        }
        return url;
    }

    function appendModalParams(url, iframe) {
        if (!iframe) return url;
        var modalSection  = getModalConfigValue(iframe, 'data-modal-section');
        var mode          = getModalConfigValue(iframe, 'data-mode');
        var schemeId      = getModalConfigValue(iframe, 'data-scheme-id');
        var defInst       = getModalConfigValue(iframe, 'data-default-installments');
        var defInstAmt    = getModalConfigValue(iframe, 'data-default-installment-amount');
        var params = [];
        if (schemeId) {
            params.push('modal_section=selected');
            params.push('scheme_id=' + encodeURIComponent(schemeId));
        } else if (modalSection) {
            params.push('modal_section=' + encodeURIComponent(modalSection));
        }
        if (mode)       params.push('mode=' + encodeURIComponent(mode));
        if (defInst)    params.push('default_installments=' + encodeURIComponent(defInst));
        if (defInstAmt) params.push('default_installment_amount=' + encodeURIComponent(defInstAmt));
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

    function triggerAddToCartSubmission() {
        var form = document.querySelector('form.cart') || document.querySelector('.entry-summary form.cart');
        var addToCartButton = form
            ? form.querySelector('button.single_add_to_cart_button[type="submit"], button.single_add_to_cart_button, input.single_add_to_cart_button[type="submit"], input.single_add_to_cart_button')
            : document.querySelector('button.single_add_to_cart_button[type="submit"], button.single_add_to_cart_button, input.single_add_to_cart_button[type="submit"], input.single_add_to_cart_button');

        if (addToCartButton && !addToCartButton.disabled) {
            addToCartButton.click();
            return;
        }

        if (form && typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        if (form) {
            form.submit();
        }
    }


    function fetchWithTimeout(url, options, timeout) {
        timeout = timeout || 30000;
        return Promise.race([
            fetch(url, options),
            new Promise(function(_, reject) {
                setTimeout(function() { reject(new Error('Request timed out')); }, timeout);
            })
        ]);
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

    function hasBlockingOutOfStock(form) {
        if (!form) {
            return document.querySelector('form.cart .out-of-stock') !== null;
        }

        var outOfStockNodes = form.querySelectorAll('.out-of-stock');
        if (!outOfStockNodes.length) {
            return false;
        }

        for (var i = 0; i < outOfStockNodes.length; i++) {
            var node = outOfStockNodes[i];

            // Ignore optional bundled items that are not selected by the customer.
            var optionalBundleItem = node.closest('.bundled_item_optional');
            if (optionalBundleItem) {
                var optionalToggle = optionalBundleItem.querySelector('input[name^="bundle_selected_optional_"]');
                if (optionalToggle && !optionalToggle.checked) {
                    continue;
                }
            }

            // Any non-optional bundled out-of-stock node (or selected optional)
            // blocks opening the financing flow.
            return true;
        }

        return false;
    }

    function setButtonDataPrice(price) {
        var body = getByID('tbi_button_body');
        var p = parseFloat(price);
        if (!body || !isFinite(p) || p <= 0) return;
        body.setAttribute('data-price', p.toFixed(2));
    }

    function applyButtonVisibility(totalAmount, container) {
        var body = getByID('tbi_button_body');
        if (!body || !container) return;

        var visible = parseFloat(body.getAttribute('data-visible') || '0');
        var max = fptbiGetMaxAmount();
        var total = parseFloat(totalAmount || '0');
        if (!isFinite(total)) total = 0;

        if (total > max || total < visible) {
            container.style.maxHeight = '0';
            container.style.display = 'none';
        } else {
            container.style.maxHeight = '250px';
            container.style.display = 'flex';
        }
    }

    function resolveDisplayTotal(form, basePrice, quantity, forcedDelivery, deliveryLimit, deliveryFee) {
        var bundleTotal = getWooCommerceBundleTotal(form);
        if (bundleTotal !== null && bundleTotal > 0) {
            return bundleTotal;
        }

        var q = parseFloat(quantity || '1');
        if (!isFinite(q) || q <= 0) q = 1;

        var base = parseFloat(basePrice || '0');
        if (!isFinite(base)) base = 0;

        var delivery = parseFloat(forcedDelivery || '0');
        if (!isFinite(delivery) || delivery < 0) {
            delivery = 0;
        }

        var limit = parseFloat(deliveryLimit || '0');
        var fee = parseFloat(deliveryFee || '0');
        if (delivery <= 0 && isFinite(limit) && isFinite(fee) && limit > 0 && fee > 0 && base * q < limit) {
            delivery = fee;
        }

        return base * q + delivery;
    }

    function getWooCommerceBundleTotal(form) {
        if (!form) {
            form = document.querySelector('form.cart');
        }
        if (!form) {
            return null;
        }

        if (!(form.classList.contains('bundle_form') || form.querySelector('.bundle_data') || form.querySelector('.bundle_wrap'))) {
            return null;
        }

        var amountNodes = form.querySelectorAll('.bundle_wrap .bundle_price .woocommerce-Price-amount.amount bdi, .bundle_wrap .bundle_price .woocommerce-Price-amount.amount');
        for (var i = amountNodes.length - 1; i >= 0; i--) {
            var parsed = parseLocalePrice(amountNodes[i].textContent || amountNodes[i].innerText || '');
            if (isFinite(parsed) && parsed > 0) {
                return parsed;
            }
        }

        var bundleData = form.querySelector('.bundle_data');
        if (bundleData) {
            var rawData = bundleData.getAttribute('data-bundle_form_data');
            if (rawData) {
                try {
                    var parsedData = JSON.parse(rawData);
                    if (parsedData && parsedData.raw_bundle_price_min && parseFloat(parsedData.raw_bundle_price_min) > 0) {
                        return parseFloat(parsedData.raw_bundle_price_min);
                    }
                } catch (e) {
                    // Ignore parse errors.
                }
            }
        }

        return null;
    }

    function parseLocalePrice(text) {
        if (!text) {
            return NaN;
        }

        var normalized = String(text)
            .replace(/\u00a0/g, ' ')
            .replace(/[^0-9.,-]/g, '')
            .trim();

        if (normalized === '') {
            return NaN;
        }

        // RO locale: dots as thousand separators, comma as decimal separator.
        normalized = normalized.replace(/\./g, '').replace(',', '.');
        return parseFloat(normalized);
    }

    function getFormData(className) {
        var form     = document.querySelector(className);
        var formData = {};
        for (var i = 0; i < form.elements.length; i++) {
            var element = form.elements[i];
            if (element.name && element.value) {
                // Skip unchecked checkboxes and radios — mirrors native form submit behaviour
                if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) continue;
                if (element.name.endsWith('[]')) {
                    if (!formData[element.name]) formData[element.name] = [];
                    formData[element.name].push(element.value);
                } else {
                    formData[element.name] = element.value;
                }
            }
        }
        // BG: TMCP extra fields (WAPF/extra addons)
        var extras = getExtraDetails();
        if (extras[0] !== '' || extras[1] > 0) {
            formData.extra_name  = extras[0];
            formData.extra_price = extras[1];
        }
        return formData;
    }

    // BG: TMCP/WAPF checked inputs — accumulate extra name + price
    function getExtraDetails() {
        var checkedInputs    = document.querySelectorAll('.tmcp-field-wrap input:checked');
        var accumulatedLabels = '';
        var totalExtraPrice   = 0;
        for (var i = 0; i < checkedInputs.length; i++) {
            var parentWrap   = checkedInputs[i].closest('.tmcp-field-wrap');
            if (parentWrap) {
                var priceElement = parentWrap.querySelector('.price');
                var labelElement = parentWrap.querySelector('.tm-label');
                if (priceElement && labelElement) {
                    accumulatedLabels += labelElement.textContent.trim() + ', ';
                    totalExtraPrice   += parseFloat(priceElement.textContent.trim()) || 0;
                }
            }
        }
        accumulatedLabels = accumulatedLabels.replace(/, $/, '');
        return [accumulatedLabels, totalExtraPrice];
    }

    function delay(callback, ms) {
        var timer = 0;
        return function() {
            var context = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function() {
                callback.apply(context, args);
            }, ms || 0);
        };
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
            '.summary .price .woocommerce-Price-currencySymbol',
            '.product .price .woocommerce-Price-currencySymbol',
            '.woocommerce-variation-price .woocommerce-Price-currencySymbol',
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

    function fptbi_updateButton() {}

})();
