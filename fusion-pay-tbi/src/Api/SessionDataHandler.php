<?php

namespace FusionPayTbi\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SessionDataHandler {

    public static function handle() {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'fusipatb_set_session_data' ) ) {
            wp_send_json_error( array( 'message' => 'Security verification failed' ), 403 );
            return;
        }

        if ( isset( $_POST['scheme_id'] ) ) {
            WC()->session->set( 'fusipatb_scheme_id', sanitize_text_field( wp_unslash( $_POST['scheme_id'] ) ) );
        }
        if ( isset( $_POST['downpayment'] ) ) {
            WC()->session->set( 'fusipatb_downpayment', sanitize_text_field( wp_unslash( $_POST['downpayment'] ) ) );
        }
        wp_send_json_success( array( 'message' => 'Session updated' ) );
    }
}


