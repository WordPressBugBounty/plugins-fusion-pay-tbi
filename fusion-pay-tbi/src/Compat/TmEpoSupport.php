<?php

namespace FusionPayTbi\Compat;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Support for TM Extra Product Options (TMCP) plugin.
 *
 * TMCP posts `cpf_product_price` with the correct per-unit price that
 * already includes all selected add-on option prices. We use this to
 * override the base WooCommerce product price when building the loan
 * application, so the financed amount reflects the actual order value.
 */
class TmEpoSupport {

    /**
     * Check whether the TM EPO backend helper API is available.
     *
     * @return bool
     */
    public static function hasBackendSupport() {
        return \function_exists( 'TM_EPO' )
            && is_object( TM_EPO() )
            && \method_exists( TM_EPO(), 'add_cart_item_data_helper' )
            && \method_exists( TM_EPO(), 'add_cart_item' );
    }

    /**
     * Whether TMCP data is present in the current request.
     *
     * @return bool
     */
    public static function isActive() {
        return isset( $_POST['cpf_product_price'] ) && is_numeric( $_POST['cpf_product_price'] );
    }

    /**
     * Return the TMCP per-unit total price if available, otherwise return the default.
     *
     * @param float $default_price
     * @return float
     */
    public static function applyPrice( $default_price ) {
        if ( self::isActive() ) {
            return floatval( $_POST['cpf_product_price'] );
        }
        return $default_price;
    }

    /**
     * Build normalized TM EPO cart data using the plugin's own backend pipeline.
     *
     * @param int               $product_id
     * @param \WC_Product       $product
     * @param int               $quantity
     * @param array<string,mixed>|null $post_data
     * @return array<string,mixed>
     */
    public static function buildCartItemData( $product_id, $product, $quantity = 1, $post_data = null ) {
        if ( ! self::isActive() || ! self::hasBackendSupport() ) {
            return array();
        }

        // Always sanitize before handing data to the third-party TM_EPO API,
        // regardless of whether the caller passed its own array or we fall
        // back to the raw request: neither is trustworthy as-is, and the
        // only real caller (ProductProcessor::handle()) always passes
        // $_POST directly rather than null.
        $post_data = self::sanitizePostData( is_array( $post_data ) ? $post_data : $_POST );

        $cart_item_meta = TM_EPO()->add_cart_item_data_helper( array(), $product_id, $post_data );
        if ( empty( $cart_item_meta['tmcartepo'] ) ) {
            return array();
        }

        $cart_item = array_merge(
            $cart_item_meta,
            array(
                'data'       => $product,
                'quantity'   => max( 1, intval( $quantity ) ),
                'product_id' => $product_id,
            )
        );

        if ( isset( $post_data['variation_id'] ) ) {
            $cart_item['variation_id'] = intval( $post_data['variation_id'] );
        }

        $cart_item = TM_EPO()->add_cart_item( $cart_item );

        $tmcartepo = isset( $cart_item['tmcartepo'] ) ? self::normalizeData( $cart_item['tmcartepo'] ) : array();

        return array(
            'tmcartepo'                   => $tmcartepo,
            'summary'                     => self::buildSummaryFromTmcartepo( $tmcartepo ),
            'original_price'              => isset( $cart_item['tm_epo_product_original_price'] ) ? floatval( $cart_item['tm_epo_product_original_price'] ) : 0.0,
            'options_price'               => isset( $cart_item['tm_epo_options_prices'] ) ? floatval( $cart_item['tm_epo_options_prices'] ) : 0.0,
            'product_price_with_options'  => isset( $cart_item['tm_epo_product_price_with_options'] ) ? floatval( $cart_item['tm_epo_product_price_with_options'] ) : 0.0,
        );
    }

    /**
     * Return the TMCP-based calculator total if available, otherwise return the supplied value.
     * Only applies when WCPA has not already overridden the total.
     *
     * @param float $current_total
     * @return float
     */
    public static function getCalculatorTotal( $current_total ) {
        if ( self::isActive() && ! isset( $_POST['wcpa_price'] ) ) {
            return floatval( $_POST['cpf_product_price'] );
        }
        return $current_total;
    }

    /**
     * Apply TM EPO order item metadata to a programmatically created order item.
     *
     * @param \WC_Order_Item_Product $order_item
     * @param array<string,mixed>|object $item
     * @return void
     */
    public static function applyOrderItemMeta( $order_item, $item ) {
        $item_data = self::normalizeData( $item );
        if ( empty( $item_data['tm_epo_data'] ) || ! is_array( $item_data['tm_epo_data'] ) ) {
            return;
        }

        $order_item->add_meta_data( '_tmcartepo_data', $item_data['tm_epo_data'] );

        $options_price = isset( $item_data['tm_epo_options_prices'] ) ? floatval( $item_data['tm_epo_options_prices'] ) : 0.0;
        if ( $options_price > 0 ) {
            $order_item->add_meta_data( '_tm_epo_options_prices', array( $options_price ) );
        }

        $original_price = isset( $item_data['tm_epo_original_price'] ) ? floatval( $item_data['tm_epo_original_price'] ) : 0.0;
        if ( $original_price <= 0 && isset( $item_data['price'], $item_data['tm_epo_options_prices'] ) ) {
            $original_price = floatval( $item_data['price'] ) - floatval( $item_data['tm_epo_options_prices'] );
        }
        if ( $original_price > 0 ) {
            $order_item->add_meta_data( '_tm_epo_product_original_price', array( $original_price ) );
        }

        foreach ( self::buildVisibleOrderMetaRows( $item_data['tm_epo_data'] ) as $meta_row ) {
            $order_item->add_meta_data( $meta_row['label'], $meta_row['value'] );
        }

        $order_item->add_meta_data( '_tm_epo', array( 1 ) );
    }

    /**
     * Extract TM EPO payload fields from a WooCommerce cart item.
     *
     * @param array<string,mixed> $cart_item
     * @return array<string,mixed>
     */
    public static function extractPayloadFromCartItem( $cart_item ) {
        $payload = array(
            'tm_epo_data'           => array(),
            'tm_epo_original_price' => 0.0,
            'tm_epo_options_prices' => 0.0,
        );

        if ( ! is_array( $cart_item ) ) {
            return $payload;
        }

        if ( ! empty( $cart_item['tmcartepo'] ) && is_array( $cart_item['tmcartepo'] ) ) {
            $payload['tm_epo_data'] = self::normalizeData( $cart_item['tmcartepo'] );
        }

        if ( isset( $cart_item['tm_epo_product_original_price'] ) ) {
            $payload['tm_epo_original_price'] = floatval( $cart_item['tm_epo_product_original_price'] );
        }

        if ( isset( $cart_item['tm_epo_options_prices'] ) ) {
            $payload['tm_epo_options_prices'] = floatval( $cart_item['tm_epo_options_prices'] );
        }

        return $payload;
    }

    /**
     * Extract selected option display names from TMCP checkbox fields.
     *
     * TMCP submits every checked option as `tmcp_checkbox_{group}_{index}` with a
     * value of `{label}_{priceIndex}`. Only fields that are present in POST are
     * selected, so we strip the trailing `_N` suffix to recover the human-readable
     * label and return them as a comma-separated string.
     *
     * @return string  e.g. "Зашиване на треси с клипси, Закачалка"
     */
    public static function extractOptionNames() {
        $names = array();
        foreach ( $_POST as $key => $value ) {
            if ( strpos( $key, 'tmcp_checkbox_' ) !== 0 ) {
                continue;
            }
            $raw   = sanitize_text_field( wp_unslash( trim( (string) $value ) ) );
            // Strip the trailing underscore-and-number price index: "Label_0" -> "Label"
            $label = preg_replace( '/_\d+$/', '', $raw );
            if ( $label !== '' ) {
                $names[] = $label;
            }
        }
        return implode( ', ', $names );
    }

    /**
     * Recursively sanitize a POST-shaped array before handing it to
     * third-party code (TM_EPO()->add_cart_item_data_helper()/add_cart_item()).
     *
     * Every scalar leaf is unslashed and run through sanitize_text_field(),
     * matching the convention already used by extractOptionNames(). Nested
     * arrays (e.g. TMCP checkbox/select groups posted as arrays) are
     * recursed into instead of being passed to sanitize_text_field()
     * directly -- that function expects a string, and PHP 8's internal
     * string-typed functions throw a TypeError when given an array.
     *
     * @param array<int|string,mixed> $data
     * @return array<int|string,mixed>
     */
    public static function sanitizePostData( array $data ) {
        $sanitized = array();

        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $sanitized[ $key ] = self::sanitizePostData( $value );
            } elseif ( is_string( $value ) ) {
                $sanitized[ $key ] = sanitize_text_field( wp_unslash( $value ) );
            } else {
                $sanitized[ $key ] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Convert nested stdClass structures into arrays for JSON/order meta usage.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeData( $value ) {
        if ( is_object( $value ) ) {
            $value = \get_object_vars( $value );
        }

        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = self::normalizeData( $item );
            }
        }

        return $value;
    }

    /**
     * Build a compact label list from normalized TM EPO option rows.
     *
     * @param array<int,array<string,mixed>> $tmcartepo
     * @return string
     */
    private static function buildSummaryFromTmcartepo( $tmcartepo ) {
        $labels = array();

        foreach ( $tmcartepo as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }

            $value = isset( $option['value'] ) ? $option['value'] : '';
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
            }

            $value = trim( (string) $value );
            if ( $value !== '' ) {
                $labels[] = $value;
            }
        }

        return implode( ', ', $labels );
    }

    /**
     * Build visible WooCommerce order-item meta rows from TM EPO option data.
     *
     * @param array<int,array<string,mixed>> $tm_epo_data
     * @return array<int,array{label:string,value:string}>
     */
    private static function buildVisibleOrderMetaRows( $tm_epo_data ) {
        $rows = array();

        foreach ( $tm_epo_data as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }

            $label = '';
            foreach ( array( 'name', 'section_label', 'element', 'title' ) as $label_key ) {
                if ( ! empty( $option[ $label_key ] ) ) {
                    $label = trim( (string) $option[ $label_key ] );
                    break;
                }
            }

            $value = '';
            foreach ( array( 'display', 'value', 'value_to_show' ) as $value_key ) {
                if ( empty( $option[ $value_key ] ) ) {
                    continue;
                }

                $raw_value = $option[ $value_key ];
                if ( is_array( $raw_value ) ) {
                    $raw_value = implode( ', ', array_filter( array_map( 'strval', $raw_value ) ) );
                }

                $value = trim( (string) $raw_value );
                if ( $value !== '' ) {
                    break;
                }
            }

            if ( $label === '' || $value === '' ) {
                continue;
            }

            $rows[] = array(
                'label' => $label,
                'value' => $value,
            );
        }

        return $rows;
    }
}
