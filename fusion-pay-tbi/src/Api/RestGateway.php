<?php

namespace FusionPayTbi\Api;

use FusionPayTbi\Config\CountryRegistry;
use FusionPayTbi\Config\FeatureFlags;
use FusionPayTbi\Helpers\SecurityHelper;
use FusionPayTbi\Helpers\Cryptor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RestGateway {

    /**
     * Handle the REST API request.
     *
     * @param \WP_REST_Request $req
     * @return array
     */
    public static function handle( \WP_REST_Request $req ) {
        // Origin check
        if ( ! SecurityHelper::isOriginAllowed() ) {
            $response = new \WP_REST_Response( array( 'message' => 'Access denied' ) );
            $response->set_status( 403 );
            return array( 'response' => $response );
        }

        // IP check (RO only)
        if ( CountryRegistry::getInstance()->hasFeature( FeatureFlags::IP_WHITELIST ) ) {
            if ( ! SecurityHelper::isIpAllowed() ) {
                $response = new \WP_REST_Response( array( 'message' => 'Access denied' ) );
                $response->set_status( 403 );
                return array( 'response' => $response );
            }
        }

        $body = (array) json_decode( $req->get_body(), true );

        if ( count( $body ) !== 1 || ! isset( $body['data'] ) || strlen( $body['data'] ) < 50 ) {
            $response = new \WP_REST_Response( array( 'message' => 'Invalid parameters' ) );
            $response->set_status( 422 );
            return array( 'response' => $response );
        }

        $cryptor        = new Cryptor();
        $encryption_key = get_option( 'fusipatb_encryption_key' );

        try {
            $decrypted = $cryptor->decryptString( $body['data'], $encryption_key );
        } catch ( \Exception $e ) {
            $response = new \WP_REST_Response( array( 'message' => 'Invalid parameters' ) );
            $response->set_status( 422 );
            return array( 'response' => $response );
        }

        $json_body = json_decode( $decrypted );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $response = new \WP_REST_Response( array( 'message' => 'Invalid parameters' ) );
            $response->set_status( 422 );
            return array( 'response' => $response );
        }

        $data = array( 'order_id' => 0, 'key' => 0 );

        switch ( isset( $json_body->command ) ? $json_body->command : '' ) {
            case 'create_order':
                $data = OrderCreator::create( $json_body );
                break;
            case 'update_status':
                $data = StatusUpdater::update( $json_body );
                break;
        }

        $res = new \WP_REST_Response( array( 'order_id' => $data['order_id'], 'key' => $data['key'] ) );
        $res->set_status( 200 );
        return array( 'req' => $res );
    }
}


