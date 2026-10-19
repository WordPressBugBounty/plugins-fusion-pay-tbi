// tbi_advertising.js — advertisement expand/collapse banner

document.addEventListener("DOMContentLoaded", function() {
    if ('ontouchstart' in window) {
        if (exists('tbicheckout_small_popup')) {
            getByID('tbicheckout_small_popup').addEventListener("touchstart", expand_in_out);
        }
    } else {
        if (exists('tbicheckout_small_popup')) {
            getByID('tbicheckout_small_popup').addEventListener("click", expand_in_out);
        }
    }
});

var expand_in_out = function() {
    var adv_iframe = getByID('tbicheckout_advertisement_iframe');
    if (adv_iframe.src == "") {
        adv_iframe.src = adv_iframe.getAttribute('data-url');
    }
    var big_popup = getByID('tbicheckout_big_popup');
    if (!big_popup.classList.contains("tbicheckout_expand")) {
        big_popup.classList.add("tbicheckout_expand");
    } else {
        big_popup.classList.remove("tbicheckout_expand");
    }
};

function getByID(el) {
    return document.getElementById(el);
}

function exists(el) {
    return getByID(el) != null;
}
