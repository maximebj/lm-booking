<?php
/**
 * Base class for availability mode strategies.
 *
 * Each booking mode (fixed slots, flexible hourly, flexible daily…) implements
 * its own way of generating availability and validating a client request.
 * Shared building blocks (opening hours resolution, price rules) live here.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

abstract class LM_Booking_Mode {

    /**
     * Get all available slots for a product on a given date.
     *
     * @param WC_Product_Booking $product The bookable product.
     * @param string             $date    Date string (Y-m-d) in local timezone.
     * @return array<int, array{start: string, end: string, start_utc: string, end_utc: string, available: int, price: float}>
     */
    abstract public function get_available_slots( WC_Product_Booking $product, string $date ): array;

    /**
     * Resolve a submitted (start, end) pair against the product's rules, or
     * null if the request is not a legal booking for this product.
     *
     * Resolving through the mode strategy enforces the booking rules that are
     * otherwise only applied when *displaying* availability — opening hours,
     * duration/grid, advance window — AND yields the authoritative,
     * server-computed price. The client must never be trusted for the price:
     * always read it from the returned slot.
     *
     * Capacity is reflected in the slot's 'available' field but not enforced
     * here — use LM_Booking_Availability::is_slot_available[_locked]() to gate
     * on capacity.
     *
     * Default implementation: exact match against the generated slots of the
     * request's local day (suits grid-based modes).
     *
     * @param WC_Product_Booking $product
     * @param string             $start_utc (Y-m-d H:i:s, UTC)
     * @param string             $end_utc   (Y-m-d H:i:s, UTC)
     * @return array{start: string, end: string, start_utc: string, end_utc: string, available: int, price: float}|null
     */
    public function get_slot( WC_Product_Booking $product, string $start_utc, string $end_utc ): ?array {
        $utc   = new DateTimeZone( 'UTC' );
        $start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_utc, $utc );
        $end   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $end_utc, $utc );

        if ( ! $start || ! $end ) {
            return null;
        }

        // Normalise to canonical UTC strings for an exact comparison.
        $start_norm = $start->format( 'Y-m-d H:i:s' );
        $end_norm   = $end->format( 'Y-m-d H:i:s' );

        // The slot's local date drives which day's slots to generate.
        $local_date = $start->setTimezone( wp_timezone() )->format( 'Y-m-d' );

        foreach ( $this->get_available_slots( $product, $local_date ) as $slot ) {
            if ( $slot['start_utc'] === $start_norm && $slot['end_utc'] === $end_norm ) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Get the opening hours for a specific date, considering overrides.
     *
     * @return array{start: string, end: string}|null  Null if closed.
     */
    protected function get_hours_for_date( WC_Product_Booking $product, string $date ): ?array {
        // Check date overrides first.
        $overrides = $product->get_booking_date_overrides();
        if ( isset( $overrides[ $date ] ) ) {
            $override = $overrides[ $date ];
            if ( ( $override['type'] ?? '' ) === 'closed' ) {
                return null;
            }
            if ( ! empty( $override['start'] ) && ! empty( $override['end'] ) ) {
                return [
                    'start' => $override['start'],
                    'end'   => $override['end'],
                ];
            }
        }

        // Fall back to weekly hours.
        $weekly   = $product->get_booking_weekly_hours();
        $wp_tz    = wp_timezone();
        $date_obj = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $wp_tz );
        if ( ! $date_obj ) {
            return null;
        }

        // PHP: 0=Sunday, 1=Monday … 6=Saturday.
        $dow = (int) $date_obj->format( 'w' );

        // Our data uses 0=Monday … 6=Sunday for better UX.
        $day_map = [ 0 => 6, 1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5 ];
        $day_key = (string) $day_map[ $dow ];

        if ( ! isset( $weekly[ $day_key ] ) ) {
            return null;
        }

        $day = $weekly[ $day_key ];
        if ( empty( $day['enabled'] ) ) {
            return null;
        }

        if ( empty( $day['start'] ) || empty( $day['end'] ) ) {
            return null;
        }

        return [
            'start' => $day['start'],
            'end'   => $day['end'],
        ];
    }

    /**
     * Compute slot price applying price rules.
     */
    protected function compute_slot_price( WC_Product_Booking $product, DateTimeImmutable $slot_start, float $base_price ): float {
        $rules = $product->get_booking_price_rules();
        if ( empty( $rules ) ) {
            return $base_price;
        }

        $price = $base_price;
        $dow   = (int) $slot_start->format( 'w' ); // 0=Sun … 6=Sat.

        foreach ( $rules as $rule ) {
            $type = $rule['type'] ?? '';

            if ( 'weekend' === $type && ( 0 === $dow || 6 === $dow ) ) {
                $price = $this->apply_modifier( $price, $rule );
            }
        }

        return round( $price, 2 );
    }

    /**
     * Apply a price modifier.
     */
    protected function apply_modifier( float $price, array $rule ): float {
        $modifier = $rule['modifier'] ?? 'multiply';
        $amount   = (float) ( $rule['amount'] ?? 1 );

        return match ( $modifier ) {
            'multiply' => $price * $amount,
            'add'      => $price + $amount,
            'fixed'    => $amount,
            default    => $price,
        };
    }
}
