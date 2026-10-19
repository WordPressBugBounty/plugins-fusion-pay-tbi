<?php

namespace FusionPayTbi\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Machine-to-machine authentication for the /api/tbi/v1/tbi-api-gateway REST
 * route. Verifies an HMAC-SHA256 signature over timestamp+nonce+body,
 * bounds the request to a clock-skew window, and rejects a nonce that has
 * already been consumed within that window (replay protection).
 *
 * The signing key is derived from the existing `fusipatb_encryption_key` shared
 * secret (already exchanged with the calling system for AES) via a
 * domain-separated HMAC, rather than requiring a new secret to be
 * provisioned/coordinated with the caller.
 */
class RequestAuthenticator {

    const HEADER_TIMESTAMP = 'X-Tbi-Timestamp';
    const HEADER_NONCE     = 'X-Tbi-Nonce';
    const HEADER_SIGNATURE = 'X-Tbi-Signature';

    const MAX_CLOCK_SKEW_SECONDS = 300;

    const NONCE_PATTERN = '/^[A-Za-z0-9_-]{8,128}$/';

    /**
     * REST permission_callback for the tbi-api-gateway route.
     *
     * @param \WP_REST_Request $req
     * @return true|\WP_Error
     */
    public static function authenticate( \WP_REST_Request $req ) {
        $timestamp = $req->get_header( self::HEADER_TIMESTAMP );
        $nonce     = $req->get_header( self::HEADER_NONCE );
        $signature = $req->get_header( self::HEADER_SIGNATURE );

        if ( ! $timestamp || ! ctype_digit( (string) $timestamp ) ) {
            return new \WP_Error( 'fusipatb_auth_missing_timestamp', 'Missing or invalid timestamp header', array( 'status' => 401 ) );
        }

        if ( ! $nonce || ! preg_match( self::NONCE_PATTERN, (string) $nonce ) ) {
            return new \WP_Error( 'fusipatb_auth_missing_nonce', 'Missing or invalid nonce header', array( 'status' => 401 ) );
        }

        if ( ! $signature ) {
            return new \WP_Error( 'fusipatb_auth_missing_signature', 'Missing signature header', array( 'status' => 401 ) );
        }

        if ( abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW_SECONDS ) {
            return new \WP_Error( 'fusipatb_auth_stale_timestamp', 'Timestamp outside the allowed window', array( 'status' => 401 ) );
        }

        $signing_key = self::derive_signing_key();
        if ( '' === $signing_key ) {
            return new \WP_Error( 'fusipatb_auth_not_configured', 'Gateway authentication is not configured', array( 'status' => 500 ) );
        }

        $expected = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $req->get_body(), $signing_key );
        if ( ! hash_equals( $expected, (string) $signature ) ) {
            return new \WP_Error( 'fusipatb_auth_invalid_signature', 'Invalid signature', array( 'status' => 401 ) );
        }

        $replay_key = 'fusipatb_gw_nonce_' . hash( 'sha256', $nonce );
        if ( false !== get_transient( $replay_key ) ) {
            return new \WP_Error( 'fusipatb_auth_replayed', 'Request has already been used', array( 'status' => 401 ) );
        }
        set_transient( $replay_key, 1, self::MAX_CLOCK_SKEW_SECONDS );

        return true;
    }

    /**
     * Derives a purpose-specific HMAC signing key from the existing shared
     * `fusipatb_encryption_key` option, so signing and encryption never use the
     * same raw key material.
     *
     * @return string Empty string if no shared secret is configured.
     */
    private static function derive_signing_key() {
        $secret = get_option( 'fusipatb_encryption_key' );
        if ( ! is_string( $secret ) || '' === $secret ) {
            return '';
        }
        return hash_hmac( 'sha256', 'fusipatb-api-gateway-signature', $secret );
    }
}
