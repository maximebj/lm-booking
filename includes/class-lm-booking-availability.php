<?php
/**
 * Availability engine — computes available time slots for a bookable product.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Availability {

    /**
     * Get all available slots for a product on a given date.
     *
     * @param WC_Product_Booking $product The bookable product.
     * @param string             $date    Date string (Y-m-d) in local timezone.
     * @return array<int, array{start: string, end: string, available: int, price: float}>
     */
    public static function get_available_slots( WC_Product_Booking $product, string $date ): array {
        $wp_tz    = wp_timezone();
        $now      = new DateTimeImmutable( 'now', $wp_tz );
        $date_obj = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $wp_tz );

        if ( ! $date_obj ) {
            return [];
        }

        // Set to start of day.
        $date_obj = $date_obj->setTime( 0, 0, 0 );

        // Check if date is within allowed booking window.
        $min_advance_hours = $product->get_booking_min_advance();
        $max_advance_days  = $product->get_booking_max_advance();

        $earliest = $now->modify( "+{$min_advance_hours} hours" );
        $latest   = $now->modify( "+{$max_advance_days} days" )->setTime( 23, 59, 59 );

        if ( $date_obj->format( 'Y-m-d' ) > $latest->format( 'Y-m-d' ) ) {
            return [];
        }

        // Get hours for this day.
        $hours = self::get_hours_for_date( $product, $date );
        if ( null === $hours ) {
            return []; // Closed.
        }

        $duration = $product->get_booking_duration();
        $buffer   = $product->get_booking_buffer();
        $capacity = $product->get_booking_capacity();
        $price    = (float) $product->get_price();

        if ( $duration <= 0 ) {
            return [];
        }

        // Generate all possible slot start times.
        $start_time = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $hours['start'], $wp_tz );
        $end_time   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $hours['end'], $wp_tz );

        if ( ! $start_time || ! $end_time ) {
            return [];
        }

        $step  = $duration + $buffer;
        $slots = [];

        $current = $start_time;
        while ( true ) {
            $slot_end = $current->modify( "+{$duration} minutes" );

            // Slot must end before (or at) the closing time.
            if ( $slot_end > $end_time ) {
                break;
            }

            // Slot must not be in the past (considering min advance).
            if ( $slot_end > $earliest || $current >= $earliest ) {
                // Convert to UTC for DB query.
                $start_utc = $current->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
                $end_utc   = $slot_end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

                $booked    = LM_Booking_Repository::count_overlapping(
                    $product->get_id(),
                    $start_utc,
                    $end_utc
                );
                $available = max( 0, $capacity - $booked );

                // Apply price rules.
                $slot_price = self::compute_slot_price( $product, $current, $price );

                $slots[] = [
                    'start'     => $current->format( 'H:i' ),
                    'end'       => $slot_end->format( 'H:i' ),
                    'start_utc' => $start_utc,
                    'end_utc'   => $end_utc,
                    'available' => $available,
                    'price'     => $slot_price,
                ];
            }

            $current = $current->modify( "+{$step} minutes" );
        }

        return $slots;
    }

    /**
     * Check if a specific slot is available.
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

    /**
     * Find the generated slot that exactly matches a submitted (start, end)
     * pair, or null if none matches.
     *
     * Matching against the generated slots enforces the booking rules that are
     * otherwise only applied when *displaying* slots — opening hours, duration,
     * the slot grid (step) and the advance window — AND yields the
     * authoritative, server-computed price for the slot. The client must never
     * be trusted for the price: always read it from the returned slot.
     *
     * Capacity is reflected in the slot's 'available' field but not enforced
     * here — use is_slot_available[_locked]() to gate on capacity.
     *
     * @param WC_Product_Booking $product
     * @param string             $start_utc (Y-m-d H:i:s, UTC)
     * @param string             $end_utc   (Y-m-d H:i:s, UTC)
     * @return array{start: string, end: string, start_utc: string, end_utc: string, available: int, price: float}|null
     */
    public static function get_slot( WC_Product_Booking $product, string $start_utc, string $end_utc ): ?array {
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

        foreach ( self::get_available_slots( $product, $local_date ) as $slot ) {
            if ( $slot['start_utc'] === $start_norm && $slot['end_utc'] === $end_norm ) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Whether a submitted slot matches one of the product's generated slots.
     * Thin wrapper around get_slot() — see it for the rules enforced.
     */
    public static function is_valid_slot( WC_Product_Booking $product, string $start_utc, string $end_utc ): bool {
        return null !== self::get_slot( $product, $start_utc, $end_utc );
    }

    /**
     * Get the opening hours for a specific date, considering overrides.
     *
     * @return array{start: string, end: string}|null  Null if closed.
     */
    private static function get_hours_for_date( WC_Product_Booking $product, string $date ): ?array {
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
        $weekly = $product->get_booking_weekly_hours();
        $wp_tz  = wp_timezone();
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
    private static function compute_slot_price( WC_Product_Booking $product, DateTimeImmutable $slot_start, float $base_price ): float {
        $rules = $product->get_booking_price_rules();
        if ( empty( $rules ) ) {
            return $base_price;
        }

        $price = $base_price;
        $dow   = (int) $slot_start->format( 'w' ); // 0=Sun … 6=Sat.

        foreach ( $rules as $rule ) {
            $type = $rule['type'] ?? '';

            if ( 'weekend' === $type && ( 0 === $dow || 6 === $dow ) ) {
                $price = self::apply_modifier( $price, $rule );
            }
        }

        return round( $price, 2 );
    }

    /**
     * Apply a price modifier.
     */
    private static function apply_modifier( float $price, array $rule ): float {
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
