<?php

namespace FusionPayTbi\Config;

use FusionPayTbi\Config\Countries\BulgariaConfig;
use FusionPayTbi\Config\Countries\RomaniaConfig;
use FusionPayTbi\Config\Countries\GreeceConfig;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Singleton registry that returns the active country config.
 */
class CountryRegistry {

    /** @var CountryRegistry|null */
    private static $instance = null;

    /** @var CountryConfigInterface */
    private $config;

    /** @var array */
    private $features;

    private function __construct() {
        $code = \FusionPayTbi\CountryDetector::detect();

        switch ( $code ) {
            case 'RO':
                $this->config = new RomaniaConfig();
                break;
            case 'GR':
                $this->config = new GreeceConfig();
                break;
            case 'BG':
            default:
                $this->config = new BulgariaConfig();
                break;
        }

        $this->features = $this->config->getFeatureFlags();
    }

    /**
     * @return CountryRegistry
     */
    public static function getInstance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return CountryConfigInterface
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * Check whether a named feature is enabled for the active country.
     *
     * @param string $feature FeatureFlags constant value.
     * @return bool
     */
    public function hasFeature( $feature ) {
        return isset( $this->features[ $feature ] ) && (bool) $this->features[ $feature ];
    }

    /**
     * Reset singleton (for tests).
     */
    public static function reset() {
        self::$instance = null;
    }
}
