<?php
/**
 * Product data meta box — adds the "Réservation" tab and handles save.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Meta_Boxes {

    public function __construct() {
        add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_booking_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'booking_panel' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_booking_meta' ] );

        // Show/hide standard WC tabs for booking products.
        add_filter( 'woocommerce_product_data_tabs', [ $this, 'adjust_tabs' ], 50 );
    }

    /**
     * Add the "Réservation" tab.
     */
    public function add_booking_tab( array $tabs ): array {
        $tabs['lm_booking'] = [
            'label'    => __( 'Réservation', 'lm-booking' ),
            'target'   => 'lm_booking_options',
            'priority' => 25,
            'class'    => [ 'show_if_booking' ],
        ];
        return $tabs;
    }

    /**
     * Hide irrelevant tabs for booking products.
     */
    public function adjust_tabs( array $tabs ): array {
        // Hide shipping tab — bookable products are virtual.
        if ( isset( $tabs['shipping'] ) ) {
            $tabs['shipping']['class'][] = 'hide_if_booking';
        }
        return $tabs;
    }

    /**
     * Output the booking panel content.
     */
    public function booking_panel(): void {
        include LM_BOOKING_PATH . 'admin/views/html-product-data-booking.php';
    }

    /**
     * Save booking meta on product save.
     */
    public function save_booking_meta( int $post_id ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing — WC handles the nonce.

        // Booking type — one select in the UI, stored as two metas (mode + unit).
        if ( isset( $_POST['_lm_booking_type'] ) ) {
            $type = sanitize_text_field( wp_unslash( $_POST['_lm_booking_type'] ) );

            if ( 'fixed' === $type ) {
                update_post_meta( $post_id, '_lm_booking_mode', 'fixed' );
                delete_post_meta( $post_id, '_lm_booking_unit' );
            } elseif ( in_array( $type, WC_Product_Booking::UNITS, true ) ) {
                update_post_meta( $post_id, '_lm_booking_mode', 'flexible' );
                update_post_meta( $post_id, '_lm_booking_unit', $type );
            }
        }

        // Simple integer fields.
        $int_fields = [
            '_lm_booking_duration',
            '_lm_booking_capacity',
            '_lm_booking_buffer',
            '_lm_booking_min_advance',
            '_lm_booking_max_advance',
        ];

        foreach ( $int_fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, $field, absint( $_POST[ $field ] ) );
            }
        }

        // JSON fields (weekly hours, date overrides, price rules).
        $json_fields = [
            '_lm_booking_weekly_hours',
            '_lm_booking_date_overrides',
            '_lm_booking_price_rules',
        ];

        foreach ( $json_fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $raw     = wp_unslash( $_POST[ $field ] );
                $decoded = json_decode( $raw, true );
                if ( json_last_error() === JSON_ERROR_NONE ) {
                    update_post_meta( $post_id, $field, $decoded );
                }
            }
        }

        // Add-ons.
        if ( isset( $_POST['_lm_booking_addons'] ) ) {
            $raw     = wp_unslash( $_POST['_lm_booking_addons'] );
            $decoded = json_decode( $raw, true );

            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                // Get previously saved add-ons to compare.
                $old_addons = get_post_meta( $post_id, '_lm_booking_addons', true );
                $old_ids    = is_array( $old_addons )
                    ? array_column( $old_addons, 'product_id' )
                    : [];

                $new_ids = array_column( $decoded, 'product_id' );

                // Sanitize add-on entries.
                $sanitized = array_map( function ( $addon ) {
                    return [
                        'product_id'     => absint( $addon['product_id'] ?? 0 ),
                        'type'           => in_array( $addon['type'] ?? '', [ 'optional', 'included' ], true )
                            ? $addon['type']
                            : 'optional',
                        'max_qty'        => max( 1, absint( $addon['max_qty'] ?? 1 ) ),
                        'price_override' => isset( $addon['price_override'] ) && '' !== $addon['price_override']
                            ? (float) $addon['price_override']
                            : null,
                    ];
                }, $decoded );

                $sanitized = array_filter( $sanitized, fn( $a ) => $a['product_id'] > 0 );

                update_post_meta( $post_id, '_lm_booking_addons', array_values( $sanitized ) );

                // Mark all current add-ons as addon-only.
                foreach ( $new_ids as $addon_id ) {
                    update_post_meta( $addon_id, '_lm_addon_only', 'yes' );
                    if ( ! has_term( 'exclude-from-catalog', 'product_visibility', $addon_id ) ) {
                        wp_set_object_terms( $addon_id, [ 'exclude-from-catalog', 'exclude-from-search' ], 'product_visibility', true );
                    }
                }

                // Unmark removed add-ons (only if not used by another bookable product).
                $removed = array_diff( $old_ids, $new_ids );
                foreach ( $removed as $addon_id ) {
                    if ( ! self::is_addon_of_another_product( $addon_id, $post_id ) ) {
                        delete_post_meta( $addon_id, '_lm_addon_only' );
                        wp_remove_object_terms( $addon_id, [ 'exclude-from-catalog', 'exclude-from-search' ], 'product_visibility' );
                    }
                }
            }
        }

        // phpcs:enable
    }

    /**
     * Check if a product is an add-on of any bookable product other than $exclude_id.
     */
    private static function is_addon_of_another_product( int $addon_id, int $exclude_id ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lm_booking_addons' AND post_id != %d",
                $exclude_id
            )
        );

        foreach ( $results as $product_id ) {
            $addons = get_post_meta( $product_id, '_lm_booking_addons', true );
            if ( is_array( $addons ) ) {
                $ids = array_column( $addons, 'product_id' );
                if ( in_array( $addon_id, $ids, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }
}
