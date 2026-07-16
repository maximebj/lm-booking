<?php
/**
 * Availability engine — façade dispatching to the product's mode strategy.
 *
 * The public API (used by REST, cart and checkout) is stable across modes:
 * availability generation and request validation are delegated to the
 * strategy matching the product's booking mode (see includes/availability/),
 * while capacity checks are mode-agnostic (overlap counting on intervals).
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Availability {

    /**
     * Resolve the mode strategy for a product.
     *
     * Phase 1: only the 'fixed' strategy exists — flexible products fall back
     * to it (the flexible choices are disabled in the admin UI until the
     * hourly and daily strategies land in phases 2 and 3).
     */
    private static function mode_for( WC_Product_Booking $product ): LM_Booking_Mode {
        return new LM_Booking_Mode_Fixed();
    }

    /**
     * Get all available slots for a product on a given date.
     *
     * @param WC_Product_Booking $product The bookable product.
     * @param string             $date    Date string (Y-m-d) in local timezone.
     * @return array<int, array{start: string, end: string, start_utc: string, end_utc: string, available: int, price: float}>
     */
    public static function get_available_slots( WC_Product_Booking $product, string $date ): array {
        return self::mode_for( $product )->get_available_slots( $product, $date );
    }

    /**
     * Resolve a submitted (start, end) pair to an authoritative slot — the
     * only trusted source for the price. Null if the request is not legal
     * for this product. See LM_Booking_Mode::get_slot() for the rules.
     *
     * @param WC_Product_Booking $product
     * @param string             $start_utc (Y-m-d H:i:s, UTC)
     * @param string             $end_utc   (Y-m-d H:i:s, UTC)
     * @return array{start: string, end: string, start_utc: string, end_utc: string, available: int, price: float}|null
     */
    public static function get_slot( WC_Product_Booking $product, string $start_utc, string $end_utc ): ?array {
        return self::mode_for( $product )->get_slot( $product, $start_utc, $end_utc );
    }

    /**
     * Whether a submitted slot is a legal booking request for the product.
     * Thin wrapper around get_slot().
     */
    public static function is_valid_slot( WC_Product_Booking $product, string $start_utc, string $end_utc ): bool {
        return null !== self::get_slot( $product, $start_utc, $end_utc );
    }

    /**
     * Check if a specific interval has remaining capacity (mode-agnostic).
     *
     * @param int    $product_id
     * @param string $start_utc  (Y-m-d H:i:s)
     * @param string $end_utc    (Y-m-d H:i:s)
     * @return bool
     */
    public static function is_slot_available( int $product_id, string $start_utc, string $end_utc ): bool {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'booking' !== $product->get_type() ) {
            return false;
        }

        $capacity = $product->get_booking_capacity();
        $booked   = LM_Booking_Repository::count_overlapping( $product_id, $start_utc, $end_utc );

        return $booked < $capacity;
    }

    /**
     * Check availability with row-level locking (for checkout).
     */
    public static function is_slot_available_locked( int $product_id, string $start_utc, string $end_utc ): bool {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'booking' !== $product->get_type() ) {
            return false;
        }

        $capacity = $product->get_booking_capacity();
        $booked   = LM_Booking_Repository::count_overlapping_locked( $product_id, $start_utc, $end_utc );

        return $booked < $capacity;
    }
}
