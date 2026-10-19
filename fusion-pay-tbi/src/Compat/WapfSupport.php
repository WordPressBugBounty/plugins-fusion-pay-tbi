<?php

namespace FusionPayTbi\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Support for SW Advanced Product Fields (WAPF) plugin.
 */
class WapfSupport {

    /**
     * Process WAPF fields from POST data and return price addition + name suffix.
     *
     * @param float $base_price
     * @param string $base_name
     * @return array{price: float, name: string, options: array}
     */
    public static function processFields( $base_price, $base_name ) {
        $result = array(
            'price'   => $base_price,
            'name'    => $base_name,
            'options' => array(),
        );

        if ( ! isset( $_POST['wapf'] ) || ! is_array( $_POST['wapf'] ) || ! isset( $_POST['wapf_field_groups'] ) ) {
            return $result;
        }

        if ( ! class_exists( 'SW_WAPF\Includes\Classes\Field_Groups' ) ) {
            return $result;
        }

        try {
            $field_groups     = \SW_WAPF\Includes\Classes\Field_Groups::get_by_ids( explode( ',', sanitize_text_field( $_POST['wapf_field_groups'] ) ) );
            $fields           = array();
            $price_addition   = 0;
            $wapf_options     = array();

            foreach ( $field_groups as $group ) {
                if ( isset( $group->fields ) ) {
                    $fields = array_merge( $fields, $group->fields );
                }
            }

            $wapf_data = isset( $_POST['wapf'] ) ? wp_unslash( (array) $_POST['wapf'] ) : array();
            foreach ( $wapf_data as $raw_field_id => $field_value ) {
                if ( $field_value === '' ) {
                    continue;
                }

                $field_id = str_replace( 'field_', '', $raw_field_id );
                $field    = null;
                foreach ( $fields as $f ) {
                    if ( $f->id === $field_id ) {
                        $field = $f;
                        break;
                    }
                }

                if ( ! $field ) {
                    continue;
                }

                $display_value  = '';
                $choice_price   = 0;

                if ( method_exists( $field, 'is_choice_field' ) && $field->is_choice_field() && isset( $field->options['choices'] ) ) {
                    $selected = is_array( $field_value ) ? $field_value : array( $field_value );
                    $parts    = array();
                    foreach ( $selected as $slug ) {
                        foreach ( $field->options['choices'] as $choice ) {
                            if ( $choice['slug'] === $slug ) {
                                $parts[] = $choice['label'];
                                if ( isset( $choice['pricing_type'] ) && $choice['pricing_type'] !== 'none' && isset( $choice['pricing_amount'] ) ) {
                                    $choice_price += floatval( $choice['pricing_amount'] );
                                }
                                break;
                            }
                        }
                    }
                    $display_value = implode( ', ', $parts );
                } else {
                    $display_value = is_array( $field_value )
                        ? implode( ', ', array_map( 'sanitize_text_field', $field_value ) )
                        : sanitize_text_field( $field_value );
                    if ( isset( $field->pricing ) && $field->pricing && isset( $field->pricing->amount ) ) {
                        $choice_price += floatval( $field->pricing->amount );
                    }
                }

                if ( ! empty( $display_value ) ) {
                    $wapf_options[] = array(
                        'label' => $field->label,
                        'value' => $display_value,
                        'price' => $choice_price,
                    );
                    $price_addition += $choice_price;
                }
            }

            if ( ! empty( $wapf_options ) ) {
                $option_parts = array();
                foreach ( $wapf_options as $option ) {
                    $text = $option['label'] . ': ' . $option['value'];
                    if ( $option['price'] > 0 && function_exists( 'wc_price' ) ) {
                        $text .= ' (+' . wc_price( $option['price'] ) . ')';
                    } elseif ( $option['price'] > 0 ) {
                        $text .= ' (+' . number_format( $option['price'], 2 ) . ')';
                    }
                    $option_parts[] = $text;
                }
                $result['name'] .= ' [' . implode( ', ', $option_parts ) . ']';
            }

            $result['price']   = $base_price + $price_addition;
            $result['options'] = $wapf_options;

        } catch ( \Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'WAPF processing error: ' . $e->getMessage() );
            }
        }

        return $result;
    }
}
