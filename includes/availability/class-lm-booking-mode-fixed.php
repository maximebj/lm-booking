<?php
/**
 * Fixed-slot mode — the merchant defines the slot duration, the client picks
 * a slot on a grid (historic behaviour: hairdresser, appointment, class…).
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Mode_Fixed extends LM_Booking_Mode {

    /**
     * Generate the slot grid for a date: consecutive slots of the configured
     * duration (+ buffer) within the day's opening hours.
     *
     * {@inheritDoc}
     */
    public function get_available_slots( WC_Product_Booking $product, string $date ): array {
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
        $hours = $this->get_hours_for_date( $product, $date );
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
                $slot_price = $this->compute_slot_price( $product, $current, $price );

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
}
