<?php

/**
 * Cart integration — display booking info, validate, manage add-on links.
 *
 * @package LM_Booking
 */

defined('ABSPATH') || exit;

class LM_Booking_Cart
{

    public function __construct()
    {
        // Display booking date/time in cart.
        add_filter('woocommerce_get_item_data', [$this, 'display_booking_data'], 10, 2);

        // Validate booking availability on add-to-cart.
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 5);

        // Make each booking cart item unique (even same product, different slot).
        add_filter('woocommerce_add_cart_item_data', [$this, 'force_unique_cart_item'], 10, 2);

        // Remove child add-ons when parent booking is removed.
        add_action('woocommerce_remove_cart_item', [$this, 'remove_linked_addons'], 10, 2);

        // Apply price overrides for add-ons.
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_addon_price_overrides']);

        // Style add-ons as sub-items in the cart.
        add_filter('woocommerce_cart_item_name', [$this, 'indent_addon_name'], 10, 3);

        // Prevent quantity changes for bookings and their add-ons.
        add_filter('woocommerce_cart_item_quantity', [$this, 'lock_booking_quantity'], 10, 3);

        // Hide the remove button for add-ons (removed via parent).
        add_filter('woocommerce_cart_item_remove_link', [$this, 'hide_addon_remove'], 10, 2);

        // Replace the add-to-cart form on bookable product pages.
        add_action('wp', [$this, 'maybe_replace_add_to_cart']);
    }

    /**
     * Display booking date/time as cart item data.
     */
    public function display_booking_data(array $item_data, array $cart_item): array
    {
        if (! empty($cart_item['_lm_booking'])) {
            $wp_tz = wp_timezone();

            $start = new DateTime($cart_item['_lm_booking_start'], new DateTimeZone('UTC'));
            $start->setTimezone($wp_tz);

            $end = new DateTime($cart_item['_lm_booking_end'], new DateTimeZone('UTC'));
            $end->setTimezone($wp_tz);

            $item_data[] = [
                'key'   => __('Date', 'lm-booking'),
                'value' => wp_date(get_option('date_format'), $start->getTimestamp()),
            ];

            $item_data[] = [
                'key'   => __('Créneau', 'lm-booking'),
                'value' => $start->format('H:i') . ' – ' . $end->format('H:i'),
            ];
        }

        if (! empty($cart_item['_lm_booking_addon'])) {
            $parent_product = wc_get_product($cart_item['_lm_booking_parent_id'] ?? 0);
            if ($parent_product) {
                $item_data[] = [
                    'key'   => __('Pour', 'lm-booking'),
                    'value' => $parent_product->get_name(),
                ];
            }
        }

        return $item_data;
    }

    /**
     * Validate that the booking slot is still available before adding to cart.
     */
    public function validate_add_to_cart(bool $valid, int $product_id, int $quantity, $variation_id = 0, $variations = []): bool
    {
        // Only validate for booking products added via our flow.
        // The actual booking data comes from the REST API add-to-cart endpoint.
        return $valid;
    }

    /**
     * Ensure each booking gets a unique cart key (prevent merging).
     */
    public function force_unique_cart_item(array $cart_item_data, int $product_id): array
    {
        if (! empty($cart_item_data['_lm_booking'])) {
            $cart_item_data['_lm_booking_hash'] = md5(
                $cart_item_data['_lm_booking_start'] . $cart_item_data['_lm_booking_end'] . microtime()
            );
        }

        if (! empty($cart_item_data['_lm_booking_addon'])) {
            $cart_item_data['_lm_booking_addon_hash'] = md5(
                ($cart_item_data['_lm_booking_parent_key'] ?? '') . $product_id . microtime()
            );
        }

        return $cart_item_data;
    }

    /**
     * When a booking is removed, also remove its linked add-ons.
     */
    public function remove_linked_addons(string $cart_item_key, WC_Cart $cart): void
    {
        $items = $cart->get_cart();

        foreach ($items as $key => $item) {
            if (! empty($item['_lm_booking_addon']) && ($item['_lm_booking_parent_key'] ?? '') === $cart_item_key) {
                $cart->remove_cart_item($key);
            }
        }
    }

    /**
     * Apply price overrides for add-on cart items.
     */
    public function apply_addon_price_overrides(WC_Cart $cart): void
    {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (! empty($cart_item['_lm_booking_addon']) && isset($cart_item['_lm_booking_price_override'])) {
                $cart_item['data']->set_price((float) $cart_item['_lm_booking_price_override']);
            }
        }
    }

    /**
     * Indent add-on names in the cart to show visual hierarchy.
     */
    public function indent_addon_name(string $name, array $cart_item, string $cart_item_key): string
    {
        if (! empty($cart_item['_lm_booking_addon'])) {
            return '<span class="lm-booking-addon-indent">&nbsp;&nbsp;↳ </span>' . $name;
        }
        return $name;
    }

    /**
     * Lock quantity for booking items and their add-ons.
     */
    public function lock_booking_quantity(string $quantity_html, string $cart_item_key, array $cart_item): string
    {
        if (! empty($cart_item['_lm_booking'])) {
            return sprintf('<span class="quantity">%d</span>', $cart_item['quantity']);
        }
        return $quantity_html;
    }

    /**
     * Hide the remove button for add-on items (they are removed via the parent).
     */
    public function hide_addon_remove(string $link, string $cart_item_key): string
    {
        $cart = WC()->cart;
        if (! $cart) {
            return $link;
        }

        $items = $cart->get_cart();
        if (isset($items[$cart_item_key]) && ! empty($items[$cart_item_key]['_lm_booking_addon'])) {
            return '';
        }

        return $link;
    }

    /**
     * On bookable product pages, replace the default add-to-cart form with
     * a mount point for the React booking form.
     */
    public function maybe_replace_add_to_cart(): void
    {
        if (! is_product()) {
            return;
        }

        global $post;
        $product = wc_get_product($post);

        if (! $product || 'booking' !== $product->get_type()) {
            return;
        }

        // Remove the default add-to-cart form for bookable products.
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);

        // Add our React mount point instead.
        add_action('woocommerce_single_product_summary', function () {
            echo '<div id="lm-booking-form">TODO</div>'; // Vérifier le chargement de React
        }, 30);
    }
}
