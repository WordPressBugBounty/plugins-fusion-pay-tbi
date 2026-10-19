<?php

namespace FusionPayTbi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SecurityHelper {

    /**
     * Get the real visitor IP, respecting CloudFlare headers.
     *
     * @return string
     */
    public static function getRequestIP() {
        // Sanitize and validate Cloudflare IP header if present
        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $cf_ip = \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
            if ( \filter_var( $cf_ip, \FILTER_VALIDATE_IP ) ) {
                return $cf_ip;
            }
        }

        // Check HTTP_CLIENT_IP with validation
        if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $client_ip = \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
            if ( \filter_var( $client_ip, \FILTER_VALIDATE_IP ) ) {
                return $client_ip;
            }
        }

        // Check HTTP_X_FORWARDED_FOR with validation (take first IP if comma-separated)
        if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $forwarded_ips = \explode( ',', $forwarded );
            foreach ( $forwarded_ips as $ip ) {
                $ip = \trim( $ip );
                if ( \filter_var( $ip, \FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        // Fall back to REMOTE_ADDR with validation
        if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $remote_addr = \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( \filter_var( $remote_addr, \FILTER_VALIDATE_IP ) ) {
                return $remote_addr;
            }
        }

        return '';
    }

    /**
     * Validate that the request origin matches the allowed gateway origin.
     *
     * @return bool
     */
    public static function isOriginAllowed() {
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
        return $origin === FUSIPATB_API_GATEWAY_ALLOWED_ORIGIN;
    }

    /**
     * Validate that the request IP matches the allowed gateway IP.
     * Only called when ip_whitelist feature is enabled.
     *
     * @return bool
     */
    public static function isIpAllowed() {
        if ( ! defined( 'FUSIPATB_API_GATEWAY_ALLOWED_IP' ) ) {
            return true;
        }
        return self::getRequestIP() === FUSIPATB_API_GATEWAY_ALLOWED_IP;
    }

    /**
     * Validate that the request origin matches the local allowed origin (for AJAX endpoints).
     *
     * @return bool
     */
    public static function isLocalOriginAllowed() {
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
        return $origin === FUSIPATB_API_LOCAL_ALLOWED_ORIGIN;
    }
}



