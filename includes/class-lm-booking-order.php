<?php
/**
 * Order integration — synchronize booking status with order status.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Order {

    public function __construct() {
        add_action( 'woocommerce_order_status_changed', [ $this, 'sync_booking_status' ], 10, 4 );

        // Display booking info in admin order view.
        add_action( 'woocommerce_order_item_meta_end', [ $this, 'display_booking_in_order' ], 10, 4 );

        // Hide internal meta keys from order display.
        add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_internal_meta' ] );
    }

    /**
     * Sync booking status when the order status changes.
     *
     * @param int      $order_id
     * @param string   $old_status
     * @param string   $new_status
     * @param WC_Order $order
     */
    public function sync_booking_status( int $order_id, string $old_status, string $new_status, WC_Order $order ): void {
        $booking_status = match ( $new_status ) {
            'processing', 'completed' => 'confirmed',
            'cancelled', 'refunded', 'failed' => 'cancelled',
            'on-hold', 'pending' => 'pending',
            default => null,
        };

        if ( null === $booking_status ) {
            return;
        }

        LM_Booking_Repository::update_status_by_order( $order_id, $booking_status );

        // If order is completed and the booking dates have passed, mark as completed.
        if ( 'completed' === $new_status ) {
            $bookings = LM_Booking_Repository::get_by_order( $order_id );
            $now_utc  = gmdate( 'Y-m-d H:i:s' );

            foreach ( $bookings as $booking ) {
                if ( $booking->end_datetime <= $now_utc ) {
                    LM_Booking_Repository::update_status( (int) $booking->id, 'completed' );
                }
            }
        }
    }

    /**
     * Display booking details in the admin order item view.
     */
    public function display_booking_in_order( int $item_id, WC_Order_Item $item, WC_Order $order, bool $plain_text ): void {
        if ( 'yes' !== $item->get_meta( '_lm_booking' ) ) {
            return;
        }

        $booking_id = $item->get_meta( '_lm_booking_id' );
        if ( ! $booking_id ) {
            return;
        }

        $booking = LM_Booking_Repository::get( (int) $booking_id );
        if ( ! $booking ) {
            return;
        }

        $status_labels = [
            'pending'   => __( 'En attente', 'lm-booking' ),
            'confirmed' => __( 'Confirmée', 'lm-booking' ),
            'cancelled' => __( 'Annulée', 'lm-booking' ),
            'completed' => __( 'Terminée', 'lm-booking' ),
        ];

        $label = $status_labels[ $booking->status ] ?? $booking->status;

        if ( $plain_text ) {
            printf( "\n%s: %s", esc_html__( 'Statut réservation', 'lm-booking' ), esc_html( $label ) );
        } else {
            printf(
                '<div class="lm-booking-order-status" style="margin-top:8px;"><strong>%s :</strong> <mark class="order-status status-%s"><span>%s</span></mark></div>',
                esc_html__( 'Réservation', 'lm-booking' ),
                esc_attr( $booking->status ),
                esc_html( $label )
            );
        }
    }

    /**
     * Hide internal meta keys from the order item display.
     */
    public function hide_internal_meta( array $keys ): array {
        return array_merge( $keys, [
            '_lm_booking',
            '_lm_booking_start',
            '_lm_booking_end',
            '_lm_booking_id',
            '_lm_booking_addon',
            '_lm_booking_parent_id',
            '_lm_booking_parent_key',
            '_lm_booking_price_override',
        ] );
    }
}
