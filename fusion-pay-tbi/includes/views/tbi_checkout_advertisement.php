<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div id="tbicheckout_advertisement">
    <div id="tbicheckout_small_popup">
        <img src="<?php echo esc_url( FUSIPATB_CDN_URL . FUSIPATB_BANK_LOGO ); ?>" alt="tbi bank">
    </div>
    <div id="tbicheckout_big_popup">
        <iframe id="tbicheckout_advertisement_iframe" data-url="<?php echo esc_url( $advertisement_url ); ?>" width="200" height="200"></iframe>
    </div>
</div>
