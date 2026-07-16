<?php
/**
 * Plugin installation — creates the bookings table.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Install {

    /**
     * Run on plugin activation.
     */
    public static function activate(): void {
        self::create_tables();
        self::set_default_options();
        flush_rewrite_rules();
    }

    /**
     * Create the wp_lm_bookings table via dbDelta.
     */
    private static function create_tables(): void {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'lm_bookings';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            order_item_id BIGINT UNSIGNED DEFAULT NULL,
            customer_id BIGINT UNSIGNED DEFAULT NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY product_availability (product_id, start_datetime, end_datetime, status),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'lm_booking_db_version', LM_BOOKING_VERSION );
    }

    /**
     * Set default plugin options.
     */
    private static function set_default_options(): void {
        add_option( 'lm_booking_db_version', LM_BOOKING_VERSION );
        add_option( 'lm_booking_default_type', 'fixed' );
        add_option( 'lm_booking_half_day_hours', [
            'morning_start'   => '09:00',
            'morning_end'     => '13:00',
            'afternoon_start' => '14:00',
            'afternoon_end'   => '18:00',
        ] );
    }
}
