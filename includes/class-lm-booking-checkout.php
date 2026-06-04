<?php
/**
 * Checkout integration — validates availability at payment, creates bookings.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Checkout {

    public function __construct() {
        // Final validation before payment — classic checkout.
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_at_checkout' ] );

        // Final validation before payment — block checkout (Store API).
        add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'validate_at_checkout_block' ] );

        // Save booking data to order line items (fires for both classic and block checkout).
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_order_item_meta' ], 10, 4 );

        // Create booking records after order is created — classic checkout.
        add_action( 'woocommerce_checkout_order_created', [ $this, 'create_bookings' ] );

        // Create booking records after order is created — block checkout (Store API).
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'create_bookings' ] );
    }

    /**
     * Re-validate all bookings in cart at checkout time.
     * Uses SQL FOR UPDATE to prevent race conditions.
     */
    public function validate_at_checkout(): void {
        if ( ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['_lm_booking'] ) ) {
                continue;
            }

            $product_id = $cart_item['product_id'];
            $start_utc  = $cart_item['_lm_booking_start'];
            $end_utc    = $cart_item['_lm_booking_end'];

            // Best-effort pre-payment check for a friendly error message. The
            // race-safe reservation is done atomically in create_bookings().
            $available = LM_Booking_Availability::is_slot_available( $product_id, $start_utc, $end_utc );

            if ( ! $available ) {
                $product = wc_get_product( $product_id );
                $name    = $product ? $product->get_name() : '#' . $product_id;

                $wp_tz = wp_timezone();
                $start = new DateTime( $start_utc, new DateTimeZone( 'UTC' ) );
                $start->setTimezone( $wp_tz );

                wc_add_notice(
                    sprintf(
                        /* translators: 1: product name, 2: date, 3: time */
                        __( 'Le créneau pour « %1$s » le %2$s à %3$s n\'est plus disponible. Veuillez modifier votre panier.', 'lm-booking' ),
                        esc_html( $name ),
                        wp_date( get_option( 'date_format' ), $start->getTimestamp() ),
                        $start->format( 'H:i' )
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * Re-validate all bookings in cart at checkout time — block checkout (Store API).
     * Throws a RouteException to prevent checkout if a slot is no longer available.
     *
     * @param WC_Order $order
     * @throws \Exception On unavailable slot.
     */
    public function validate_at_checkout_block( WC_Order $order ): void {
        if ( ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['_lm_booking'] ) ) {
                continue;
            }

            $product_id = $cart_item['product_id'];
            $start_utc  = $cart_item['_lm_booking_start'];
            $end_utc    = $cart_item['_lm_booking_end'];

            // Best-effort pre-payment check for a friendly error message. The
            // race-safe reservation is done atomically in create_bookings().
            $available = LM_Booking_Availability::is_slot_available( $product_id, $start_utc, $end_utc );

            if ( ! $available ) {
                $product = wc_get_product( $product_id );
                $name    = $product ? $product->get_name() : '#' . $product_id;

                $wp_tz = wp_timezone();
                $start = new DateTime( $start_utc, new DateTimeZone( 'UTC' ) );
                $start->setTimezone( $wp_tz );

                throw new \Exception(
                    sprintf(
                        /* translators: 1: product name, 2: date, 3: time */
                        __( 'Le créneau pour « %1$s » le %2$s à %3$s n\'est plus disponible. Veuillez modifier votre panier.', 'lm-booking' ),
                        esc_html( $name ),
                        wp_date( get_option( 'date_format' ), $start->getTimestamp() ),
                        $start->format( 'H:i' )
                    )
                );
            }
        }
    }

    /**
     * Save booking data as order item meta.
     */
    public function save_order_item_meta( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
        if ( ! empty( $values['_lm_booking'] ) ) {
            $item->add_meta_data( '_lm_booking', 'yes' );
            $item->add_meta_data( '_lm_booking_start', $values['_lm_booking_start'] );
            $item->add_meta_data( '_lm_booking_end', $values['_lm_booking_end'] );

            // Store a human-readable date for display.
            $wp_tz = wp_timezone();
            $start = new DateTime( $values['_lm_booking_start'], new DateTimeZone( 'UTC' ) );
            $start->setTimezone( $wp_tz );
            $end = new DateTime( $values['_lm_booking_end'], new DateTimeZone( 'UTC' ) );
            $end->setTimezone( $wp_tz );

            $item->add_meta_data(
                __( 'Date', 'lm-booking' ),
                wp_date( get_option( 'date_format' ), $start->getTimestamp() )
            );
            $item->add_meta_data(
                __( 'Créneau', 'lm-booking' ),
                $start->format( 'H:i' ) . ' – ' . $end->format( 'H:i' )
            );
        }

        if ( ! empty( $values['_lm_booking_addon'] ) ) {
            $item->add_meta_data( '_lm_booking_addon', 'yes' );
            $item->add_meta_data( '_lm_booking_parent_id', $values['_lm_booking_parent_id'] ?? 0 );
        }
    }

    /**
     * Create booking records in wp_lm_bookings after order creation.
     * Fires for both classic checkout (woocommerce_checkout_order_created)
     * and block checkout (woocommerce_store_api_checkout_order_processed).
     */
    public function create_bookings( WC_Order $order ): void {
        global $wpdb;

        // Guard against double-creation if both hooks somehow fire for the same order.
        $existing = LM_Booking_Repository::get_by_order( $order->get_id() );
        if ( ! empty( $existing ) ) {
            return;
        }

        $oversold = [];

        foreach ( $order->get_items() as $item ) {
            if ( 'yes' !== $item->get_meta( '_lm_booking' ) ) {
                continue;
            }

            $start_utc = $item->get_meta( '_lm_booking_start' );
            $end_utc   = $item->get_meta( '_lm_booking_end' );

            if ( empty( $start_utc ) || empty( $end_utc ) ) {
                continue;
            }

            $product_id = $item->get_product_id();

            // Atomic check-and-reserve: the locked capacity count (SELECT …
            // FOR UPDATE) and the INSERT run in the SAME transaction, so two
            // concurrent checkouts serialise on the slot and capacity can no
            // longer be oversold. The pre-payment check is only best-effort.
            $wpdb->query( 'START TRANSACTION' );

            if ( ! LM_Booking_Availability::is_slot_available_locked( $product_id, $start_utc, $end_utc ) ) {
                $wpdb->query( 'ROLLBACK' );
                $oversold[] = $item;
                continue;
            }

            $booking_id = LM_Booking_Repository::create( [
                'product_id'     => $product_id,
                'order_id'       => $order->get_id(),
                'order_item_id'  => $item->get_id(),
                'customer_id'    => $order->get_customer_id(),
                'start_datetime' => $start_utc,
                'end_datetime'   => $end_utc,
                'status'         => 'pending',
            ] );

            $wpdb->query( 'COMMIT' );

            if ( $booking_id ) {
                $item->add_meta_data( '_lm_booking_id', $booking_id );
                $item->save_meta_data();
            }
        }

        // Rare race: a slot filled up between the pre-payment check and now.
        // Don't drop it silently — flag the order for staff to resolve.
        if ( ! empty( $oversold ) ) {
            $this->flag_oversold_order( $order, $oversold );
        }
    }

    /**
     * Put an order on hold and add a note when one or more booked slots could
     * not be reserved because capacity was reached during a concurrent checkout.
     *
     * @param WC_Order                    $order
     * @param array<WC_Order_Item_Product> $items
     */
    private function flag_oversold_order( WC_Order $order, array $items ): void {
        $names = array_map( fn( $item ) => $item->get_name(), $items );

        $order->add_order_note(
            sprintf(
                /* translators: %s: comma-separated list of product names */
                __( '⚠️ Créneau(x) complet(s) au moment de la validation : %s. La réservation n\'a pas pu être enregistrée — vérifier la disponibilité et contacter le client (remboursement éventuel).', 'lm-booking' ),
                implode( ', ', $names )
            )
        );

        // Hold the order so it is not fulfilled as-is.
        if ( ! $order->has_status( 'on-hold' ) ) {
            $order->update_status( 'on-hold' );
        }
    }
}
