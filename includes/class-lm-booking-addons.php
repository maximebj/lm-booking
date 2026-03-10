<?php
/**
 * Add-ons system — restricts standalone purchase of add-on products.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Addons {

    public function __construct() {
        // Block standalone purchase of add-on products.
        add_filter( 'woocommerce_is_purchasable', [ $this, 'maybe_block_purchase' ], 10, 2 );

        // Replace add-to-cart button with a notice on add-on product pages.
        add_action( 'woocommerce_single_product_summary', [ $this, 'addon_notice' ], 29 );
        add_filter( 'woocommerce_loop_add_to_cart_link', [ $this, 'hide_loop_add_to_cart' ], 10, 2 );

        // Extra safety: filter add-ons out of shop queries.
        add_action( 'woocommerce_product_query', [ $this, 'exclude_from_shop' ] );
    }

    /**
     * Make add-on-only products non-purchasable unless added via booking context.
     */
    public function maybe_block_purchase( bool $purchasable, WC_Product $product ): bool {
        if ( 'yes' !== $product->get_meta( '_lm_addon_only' ) ) {
            return $purchasable;
        }

        // Allow if we are in a booking add-to-cart context (set by LM_Booking_Cart).
        if ( doing_action( 'lm_booking_adding_addon' ) ) {
            return $purchasable;
        }

        return false;
    }

    /**
     * Display a notice instead of the add-to-cart button on add-on product pages.
     */
    public function addon_notice(): void {
        global $product;

        if ( ! $product instanceof WC_Product ) {
            return;
        }

        if ( 'yes' !== $product->get_meta( '_lm_addon_only' ) ) {
            return;
        }

        // Remove the add-to-cart button.
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );

        echo '<div class="woocommerce-info" style="margin-top:1em;">';
        esc_html_e( 'Ce produit est disponible uniquement avec une réservation.', 'lm-booking' );
        echo '</div>';
    }

    /**
     * Hide the add-to-cart button in product loops for add-on products.
     */
    public function hide_loop_add_to_cart( string $html, WC_Product $product ): string {
        if ( 'yes' === $product->get_meta( '_lm_addon_only' ) ) {
            return '';
        }
        return $html;
    }

    /**
     * Exclude add-on-only products from shop/catalog queries.
     */
    public function exclude_from_shop( WP_Query $query ): void {
        if ( is_admin() ) {
            return;
        }

        $meta_query = $query->get( 'meta_query' ) ?: [];

        $meta_query[] = [
            'relation' => 'OR',
            [
                'key'     => '_lm_addon_only',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => '_lm_addon_only',
                'value'   => 'yes',
                'compare' => '!=',
            ],
        ];

        $query->set( 'meta_query', $meta_query );
    }
}
