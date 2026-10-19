// tbi_blocks_integration.js — merged BG + RO
// Uses createElementFusionPay (RO's rename) to avoid conflict with Netopia plugin
// UI text comes from wp_set_script_translations (text domain: fusion-pay-tbi)

(function() {
    // Platform URL: prefer settings data (passed via get_payment_method_data),
    // fall back to wp_localize_script fusipatbConfig, then BG default.
    var settings_tbi = window.wc.wcSettings.getSetting('tbi_bank_data', {});
    var FPTBI_ORIGIN = (settings_tbi.platformUrl)
        ? settings_tbi.platformUrl
        : ( (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.platformUrl)
            ? fusipatbConfig.platformUrl
            : 'https://beta.tbibank.support' );

    var label_tbi = window.wp.htmlEntities.decodeEntities(settings_tbi.title) || 'tbi bank';

    // Renamed to avoid conflict with Netopia payment plugin (RO fix)
    var createElementFusionPay = window.wp.element.createElement;

    var Content_tbi = function() {
        var description    = window.wp.htmlEntities.decodeEntities(settings_tbi.description || '');
        var tbi_popup_url  = settings_tbi.popup_url || '';

        var showOptions = async function(e) {
            e.preventDefault();
            e.stopPropagation();

            var tbi_popup   = getByID('tbi_popup_container');
            var tbi_iframe  = getByID('tbi_popup_iframe');
            var tbi_loading = getByID('tbi_loading');

            if (!tbi_popup) {
                tbi_popup = document.createElement('div');
                tbi_popup.id                       = 'tbi_popup_container';
                tbi_popup.style.display            = 'flex';
                tbi_popup.style.position           = 'fixed';
                tbi_popup.style.top                = '0';
                tbi_popup.style.left               = '0';
                tbi_popup.style.width              = '100%';
                tbi_popup.style.height             = '100%';
                tbi_popup.style.backgroundColor    = 'rgba(0, 0, 0, 0.5)';
                tbi_popup.style.justifyContent     = 'center';
                tbi_popup.style.alignItems         = 'center';
                tbi_popup.style.zIndex             = '9999';

                tbi_iframe = document.createElement('iframe');
                tbi_iframe.id           = 'tbi_popup_iframe';
                tbi_iframe.style.width  = '80%';
                tbi_iframe.style.height = '80%';
                tbi_popup.appendChild(tbi_iframe);
                document.body.appendChild(tbi_popup);
            }

            var calculator_url = tbi_popup.getAttribute('data-calculator');

            try {
                var minAmount = parseFloat(tbi_popup.getAttribute('data-min-amount'));
                var maxAmount = parseFloat(tbi_popup.getAttribute('data-max-amount'));
                var bnplMin   = parseFloat(tbi_popup.getAttribute('data-bnpl-min-amount'));
                var bnplMax   = parseFloat(tbi_popup.getAttribute('data-bnpl-max-amount'));
                var amount    = NaN;
                if (calculator_url && calculator_url.indexOf('?') > -1) {
                    var urlParams = new URLSearchParams(calculator_url.split('?')[1]);
                    amount = parseFloat(urlParams.get('amount'));
                }
                var isBnplEligible = !isNaN(amount) && !isNaN(bnplMin) && !isNaN(bnplMax) && amount >= bnplMin && amount <= bnplMax;
                if (!isBnplEligible && !isNaN(amount) && ((!isNaN(minAmount) && amount < minAmount) || (!isNaN(maxAmount) && amount > maxAmount))) {
                    alert((tbi_popup.getAttribute('data-range-alert')) || 'Amount outside allowed loan range.');
                    return;
                }
            } catch (err) {
                // Ignore.
            }

            tbi_popup.style.display      = 'flex';
            document.body.style.overflow = 'hidden';
            if (!tbi_iframe.getAttribute('src') || tbi_iframe.getAttribute('src') === '') {
                if (tbi_loading) tbi_loading.style.display = 'block';
                var blocksAjaxUrl = (typeof fusipatbConfig !== 'undefined' && fusipatbConfig.ajaxurl)
                    ? fusipatbConfig.ajaxurl
                    : '/wp-admin/admin-ajax.php';
                try {
                    var blocksParams = { action: 'fusipatb_process_cart' };
                    if (calculator_url && calculator_url.indexOf('?') > -1) {
                        var blocksCurrency = new URLSearchParams(calculator_url.split('?')[1]).get('currency');
                        if (blocksCurrency) blocksParams.currency = blocksCurrency;
                    }
                    var blocksRequestData = { action: 'fusipatb_process_cart', currency: getActiveCurrency() };
                    if (settings_tbi.cartNonce) {
                        blocksRequestData._wpnonce = settings_tbi.cartNonce;
                    }
                    var blocksResponse = await fetch(blocksAjaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams(blocksRequestData).toString()
                    });
                    var blocksData = await blocksResponse.json();
                    if (blocksData.success && blocksData.data && blocksData.data.url) {
                        if (blocksData.data.debug) {
                            console.log('FusionPayTbi iframe payload', blocksData.data.debug);
                        }
                        tbi_iframe.src = blocksData.data.url;
                    } else {
                        tbi_iframe.src = FPTBI_ORIGIN + '/application/error/error_notified/?merchant=' + encodeURIComponent(window.location.href);
                    }
                } catch (err) {
                    tbi_iframe.src = FPTBI_ORIGIN + '/application/error/error_notified/?merchant=' + encodeURIComponent(window.location.href);
                }
                if (tbi_loading) tbi_loading.style.display = 'none';
            }

            tbi_iframe.addEventListener('click', function(e) { e.stopPropagation(); });
            window.addEventListener('click', function() {
                tbi_popup.style.display      = 'none';
                document.body.style.overflow = 'auto';
            });
        };

        window.addEventListener('message', function(event) {
            if (event.origin !== FPTBI_ORIGIN) return;
            try {
                var jsonObject = JSON.parse(event.data);
                if (jsonObject.command === "close_calculator" || jsonObject.command === "close") {
                    var popup = getByID('tbi_popup_container');
                    if (popup) popup.style.display  = 'none';
                    document.body.style.overflow = 'auto';
                }
            } catch (error) {
                console.error('Invalid JSON string:', error);
            }
        });

        return createElementFusionPay(
            'div',
            { className: 'payment_box payment_method_tbi_bank' },
            createElementFusionPay(
                'p',
                null,
                description + ' ',
                createElementFusionPay(
                    'a',
                    {
                        href: '#',
                        id: 'tbi_check_plans',
                        onClick: showOptions,
                        style: { color: '#0073aa', textDecoration: 'underline', cursor: 'pointer' }
                    },
                    'Check options'
                )
            ),
            createElementFusionPay('input', {
                type: 'hidden',
                name: '_wp_http_referer',
                value: '/?wc-ajax=update_order_review'
            })
        );
    };

    var Block_Gateway_Tbi = {
        name: 'tbi_bank',
        label: label_tbi,
        content: Object(window.wp.element.createElement)(Content_tbi, null),
        edit: Object(window.wp.element.createElement)(Content_tbi, null),
        canMakePayment: function() { return true; },
        ariaLabel: label_tbi,
        supports: {
            features: settings_tbi.supports,
        },
        paymentMethodId: 'tbi_bank',
        createPaymentMethod: function(opts) {
            return {
                type: 'tbi_bank',
                data: {
                    tbi_gateway_nonce: settings_tbi.nonce || '',
                },
            };
        },
        submitPayment: function(opts) {
            return new Promise(function(resolve) {
                resolve();
            });
        },
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway_Tbi);

    function getByID(el) {
        return document.getElementById(el);
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

        currency = normalizeCurrency(settings_tbi.currency || '');
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
            '.wc-block-components-totals-footer-item .woocommerce-Price-currencySymbol',
            '.wc-block-formatted-money-amount .woocommerce-Price-currencySymbol',
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
