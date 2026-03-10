<?php
/**
 * Repository — CRUD operations for the wp_lm_bookings table.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Repository {

    /**
     * Get the table name.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'lm_bookings';
    }

    /**
     * Create a new booking.
     *
     * @param array $data {
     *     @type int    $product_id
     *     @type int    $order_id
     *     @type int    $order_item_id
     *     @type int    $customer_id
     *     @type string $start_datetime  (Y-m-d H:i:s in UTC)
     *     @type string $end_datetime    (Y-m-d H:i:s in UTC)
     *     @type string $status          (pending|confirmed|cancelled|completed)
     * }
     * @return int|false  Inserted booking ID, or false on failure.
     */
    public static function create( array $data ) {
        global $wpdb;

        $defaults = [
            'product_id'     => 0,
            'order_id'       => null,
            'order_item_id'  => null,
            'customer_id'    => null,
            'start_datetime' => '',
            'end_datetime'   => '',
            'status'         => 'pending',
        ];

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert(
            self::table_name(),
            [
                'product_id'     => absint( $data['product_id'] ),
                'order_id'       => $data['order_id'] ? absint( $data['order_id'] ) : null,
                'order_item_id'  => $data['order_item_id'] ? absint( $data['order_item_id'] ) : null,
                'customer_id'    => $data['customer_id'] ? absint( $data['customer_id'] ) : null,
                'start_datetime' => sanitize_text_field( $data['start_datetime'] ),
                'end_datetime'   => sanitize_text_field( $data['end_datetime'] ),
                'status'         => sanitize_key( $data['status'] ),
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get a booking by ID.
     *
     * @return object|null
     */
    public static function get( int $id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE id = %d",
                self::table_name(),
                $id
            )
        );
    }

    /**
     * Get all bookings for a given order.
     *
     * @return array<object>
     */
    public static function get_by_order( int $order_id ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE order_id = %d ORDER BY start_datetime ASC",
                self::table_name(),
                $order_id
            )
        );
    }

    /**
     * Get all bookings for a given product within a date range.
     *
     * @return array<object>
     */
    public static function get_by_product( int $product_id, string $from, string $to ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE product_id = %d
                   AND status IN ('pending', 'confirmed')
                   AND start_datetime < %s
                   AND end_datetime > %s
                 ORDER BY start_datetime ASC",
                self::table_name(),
                $product_id,
                $to,
                $from
            )
        );
    }

    /**
     * Count overlapping bookings for a given slot — the core availability query.
     *
     * @param int    $product_id
     * @param string $start  Start datetime (Y-m-d H:i:s UTC).
     * @param string $end    End datetime (Y-m-d H:i:s UTC).
     * @return int   Number of active bookings overlapping the slot.
     */
    public static function count_overlapping( int $product_id, string $start, string $end ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                 WHERE product_id = %d
                   AND status IN ('pending', 'confirmed')
                   AND start_datetime < %s
                   AND end_datetime > %s",
                self::table_name(),
                $product_id,
                $end,
                $start
            )
        );
    }

    /**
     * Count overlapping bookings using a lock (FOR UPDATE) to prevent race conditions.
     */
    public static function count_overlapping_locked( int $product_id, string $start, string $end ): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                 WHERE product_id = %d
                   AND status IN ('pending', 'confirmed')
                   AND start_datetime < %s
                   AND end_datetime > %s
                 FOR UPDATE",
                self::table_name(),
                $product_id,
                $end,
                $start
            )
        );
    }

    /**
     * Update the status of a booking.
     */
    public static function update_status( int $booking_id, string $status ): bool {
        global $wpdb;

        $allowed = [ 'pending', 'confirmed', 'cancelled', 'completed' ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update(
            self::table_name(),
            [ 'status' => $status ],
            [ 'id' => $booking_id ],
            [ '%s' ],
            [ '%d' ]
        );

        return false !== $result;
    }

    /**
     * Update all bookings for a given order to a new status.
     */
    public static function update_status_by_order( int $order_id, string $status ): int {
        global $wpdb;

        $allowed = [ 'pending', 'confirmed', 'cancelled', 'completed' ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = %s WHERE order_id = %d",
                self::table_name(),
                $status,
                $order_id
            )
        );
    }

    /**
     * Delete a booking (use sparingly — prefer status changes).
     */
    public static function delete( int $booking_id ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->delete(
            self::table_name(),
            [ 'id' => $booking_id ],
            [ '%d' ]
        );
    }
}
