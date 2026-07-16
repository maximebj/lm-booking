<?php
/**
 * Custom WooCommerce product type: Booking.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class WC_Product_Booking extends WC_Product {

    /**
     * Product type identifier.
     *
     * @var string
     */
    protected $product_type = 'booking';

    /**
     * Return the product type.
     */
    public function get_type(): string {
        return 'booking';
    }

    /**
     * Bookable products are virtual (no shipping).
     */
    public function is_virtual(): bool {
        return true;
    }

    /**
     * Bookable products are always purchasable if they have a price.
     */
    public function is_purchasable(): bool {
        return $this->exists() && '' !== $this->get_price();
    }

    /**
     * Bookable products are sold individually — each booking is unique.
     */
    public function is_sold_individually(): bool {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Booking-specific meta accessors.
    |--------------------------------------------------------------------------
    */

    /**
     * Valid booking modes.
     */
    public const MODES = [ 'fixed', 'flexible' ];

    /**
     * Valid booking units (flexible mode only).
     */
    public const UNITS = [ 'hour', 'half_day', 'day' ];

    /**
     * Get the booking mode. Products saved before the mode existed have no
     * meta and behave as 'fixed'.
     *
     * @return string 'fixed' | 'flexible'
     */
    public function get_booking_mode(): string {
        $mode = $this->get_meta( '_lm_booking_mode' );
        return in_array( $mode, self::MODES, true ) ? $mode : 'fixed';
    }

    /**
     * Get the booking unit (only meaningful in flexible mode).
     *
     * @return string 'hour' | 'half_day' | 'day'
     */
    public function get_booking_unit(): string {
        $unit = $this->get_meta( '_lm_booking_unit' );
        return in_array( $unit, self::UNITS, true ) ? $unit : 'hour';
    }

    /**
     * Get the booking duration in minutes.
     */
    public function get_booking_duration(): int {
        return (int) $this->get_meta( '_lm_booking_duration' );
    }

    /**
     * Get the booking capacity (how many concurrent bookings per slot).
     */
    public function get_booking_capacity(): int {
        $capacity = (int) $this->get_meta( '_lm_booking_capacity' );
        return max( 1, $capacity );
    }

    /**
     * Get buffer time between slots in minutes.
     */
    public function get_booking_buffer(): int {
        return (int) $this->get_meta( '_lm_booking_buffer' );
    }

    /**
     * Get minimum advance time in hours.
     */
    public function get_booking_min_advance(): int {
        return (int) $this->get_meta( '_lm_booking_min_advance' );
    }

    /**
     * Get maximum advance time in days.
     */
    public function get_booking_max_advance(): int {
        $days = (int) $this->get_meta( '_lm_booking_max_advance' );
        return $days > 0 ? $days : 90; // Default 90 days.
    }

    /**
     * Get weekly hours configuration.
     *
     * @return array<int, array{enabled: bool, start: string, end: string}>
     *   Keyed by day of week (0 = Sunday … 6 = Saturday).
     */
    public function get_booking_weekly_hours(): array {
        $hours = $this->get_meta( '_lm_booking_weekly_hours' );
        if ( is_string( $hours ) ) {
            $hours = json_decode( $hours, true );
        }
        return is_array( $hours ) ? $hours : [];
    }

    /**
     * Get date overrides (closures, special hours, holidays).
     *
     * @return array<string, array{type: string, start?: string, end?: string}>
     *   Keyed by date string (Y-m-d).
     */
    public function get_booking_date_overrides(): array {
        $overrides = $this->get_meta( '_lm_booking_date_overrides' );
        if ( is_string( $overrides ) ) {
            $overrides = json_decode( $overrides, true );
        }
        return is_array( $overrides ) ? $overrides : [];
    }

    /**
     * Get price rules.
     */
    public function get_booking_price_rules(): array {
        $rules = $this->get_meta( '_lm_booking_price_rules' );
        if ( is_string( $rules ) ) {
            $rules = json_decode( $rules, true );
        }
        return is_array( $rules ) ? $rules : [];
    }

    /**
     * Get configured add-ons.
     *
     * @return array<int, array{product_id: int, type: string, max_qty: int, price_override: float|null}>
     */
    public function get_booking_addons(): array {
        $addons = $this->get_meta( '_lm_booking_addons' );
        if ( is_string( $addons ) ) {
            $addons = json_decode( $addons, true );
        }
        return is_array( $addons ) ? $addons : [];
    }
}
