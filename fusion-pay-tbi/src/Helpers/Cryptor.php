<?php

namespace FusionPayTbi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AES-256-CTR encryption/decryption helper.
 * Wraps the legacy Cryptor class from classes/Cryptor.class.php.
 * That file is kept for backward compatibility; this is the namespaced wrapper.
 */
class Cryptor {

    private $cipher_algo;
    private $hash_algo;
    private $iv_num_bytes;
    private $format;

    const FORMAT_RAW = 0;
    const FORMAT_B64 = 1;
    const FORMAT_HEX = 2;

    public function __construct( $cipher_algo = 'aes-256-ctr', $hash_algo = 'sha256', $fmt = self::FORMAT_B64 ) {
        if ( ! extension_loaded( 'openssl' ) ) {
            throw new \Exception( 'Cryptor: the PHP openssl extension is required but not available.' );
        }

        $this->cipher_algo  = $cipher_algo;
        $this->hash_algo    = $hash_algo;
        $this->format       = $fmt;

        if ( ! in_array( $cipher_algo, openssl_get_cipher_methods( true ) ) ) {
            throw new \Exception( 'Cryptor: unknown cipher algo ' . esc_html( $cipher_algo ) );
        }
        if ( ! in_array( $hash_algo, openssl_get_md_methods( true ) ) ) {
            throw new \Exception( 'Cryptor: unknown hash algo ' . esc_html( $hash_algo ) );
        }
        $this->iv_num_bytes = openssl_cipher_iv_length( $cipher_algo );
    }

    public function encryptString( $in, $key, $fmt = null ) {
        if ( $fmt === null ) {
            $fmt = $this->format;
        }
        $iv      = openssl_random_pseudo_bytes( $this->iv_num_bytes, $strong );
        $keyhash = openssl_digest( $key, $this->hash_algo, true );
        $opts    = OPENSSL_RAW_DATA;
        $enc     = openssl_encrypt( $in, $this->cipher_algo, $keyhash, $opts, $iv );
        if ( $enc === false ) {
            throw new \Exception( 'Cryptor: encryption failed: ' . esc_html( openssl_error_string() ) );
        }
        $res = $iv . $enc;
        if ( $fmt === self::FORMAT_B64 ) {
            return base64_encode( $res );
        }
        if ( $fmt === self::FORMAT_HEX ) {
            return unpack( 'H*', $res )[1];
        }
        return $res;
    }

    public function decryptString( $in, $key, $fmt = null ) {
        if ( $fmt === null ) {
            $fmt = $this->format;
        }
        $raw = $in;
        if ( $fmt === self::FORMAT_B64 ) {
            $raw = base64_decode( $in );
        } elseif ( $fmt === self::FORMAT_HEX ) {
            $raw = pack( 'H*', $in );
        }
        if ( strlen( $raw ) < $this->iv_num_bytes ) {
            throw new \Exception( 'Cryptor: data too short' );
        }
        $iv      = substr( $raw, 0, $this->iv_num_bytes );
        $raw     = substr( $raw, $this->iv_num_bytes );
        $keyhash = openssl_digest( $key, $this->hash_algo, true );
        $res     = openssl_decrypt( $raw, $this->cipher_algo, $keyhash, OPENSSL_RAW_DATA, $iv );
        if ( $res === false ) {
            throw new \Exception( 'Cryptor: decryption failed: ' . esc_html( openssl_error_string() ) );
        }
        return $res;
    }

    public static function Encrypt( $in, $key, $fmt = null ) {
        $c = new self();
        return $c->encryptString( $in, $key, $fmt );
    }

    public static function Decrypt( $in, $key, $fmt = null ) {
        $c = new self();
        return $c->decryptString( $in, $key, $fmt );
    }
}
