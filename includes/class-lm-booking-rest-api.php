<?php
/**
 * REST API endpoints for the booking system.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_REST_API {

    /**
     * Namespace for the API routes.
     */
    private const NAMESPACE = 'lm-booking/v1';

    /**
     * Register REST routes.
     */
    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/availability', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_availability' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'date' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [ $this, 'validate_date' ],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/add-to-cart', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'add_to_cart' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'start_utc' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'end_utc' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'addons' => [
                    'required' => false,
                    'type'     => 'array',
                    'default'  => [],
                ],
            ],
        ] );

        // Product search for admin add-on manager.
        register_rest_route( self::NAMESPACE, '/products/search', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'search_products' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_products' );
            },
            'args' => [
                'term' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    /**
     * Validate a date string (Y-m-d).
     */
    public function validate_date( $value ): bool {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
    }

    /**
     * GET /availability — returns available time slots for a product on a date.
     */
    public function get_availability( WP_REST_Request $request ): WP_REST_Response {
        $product_id = $request->get_param( 'product_id' );
        $date       = $request->get_param( 'date' );

        $product = wc_get_product( $product_id );
        if ( ! $product || 'booking' !== $product->get_type() ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Produit introuvable ou non réservable.', 'lm-booking' ) ],
                404
            );
        }

        $slots = LM_Booking_Availability::get_available_slots( $product, $date );

        $response = new WP_REST_Response( [
            'product_id' => $product_id,
            'date'       => $date,
            'slots'      => $slots,
        ] );

        // Prevent caching.
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

        return $response;
    }

    /**
     * POST /add-to-cart — adds a booking (and optional add-ons) to the WC cart.
     */
    public function add_to_cart( WP_REST_Request $request ): WP_REST_Response {
        $product_id = $request->get_param( 'product_id' );
        $start_utc  = $request->get_param( 'start_utc' );
        $end_utc    = $request->get_param( 'end_utc' );
        $addons     = $request->get_param( 'addons' );

        // Verify product.
        $product = wc_get_product( $product_id );
        if ( ! $product || 'booking' !== $product->get_type() ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Produit introuvable ou non réservable.', 'lm-booking' ) ],
                404
            );
        }

        // Resolve the slot against the product's generated slots — enforces
        // opening hours, duration, the slot grid and the advance window, and
        // yields the authoritative server-computed price (slot price rules).
        // The client is never trusted for the price.
        $slot = LM_Booking_Availability::get_slot( $product, $start_utc, $end_utc );
        if ( null === $slot ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Ce créneau n\'est pas valide.', 'lm-booking' ) ],
                422
            );
        }

        // Verify availability (capacity).
        if ( ! LM_Booking_Availability::is_slot_available( $product_id, $start_utc, $end_utc ) ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Ce créneau n\'est plus disponible.', 'lm-booking' ) ],
                409
            );
        }

        // Ensure WC cart is loaded.
        if ( ! WC()->cart ) {
            wc_load_cart();
        }

        // Add the booking to cart.
        $cart_item_data = [
            '_lm_booking'       => true,
            '_lm_booking_start' => $start_utc,
            '_lm_booking_end'   => $end_utc,
            '_lm_booking_price' => (float) $slot['price'],
        ];

        $cart_key = WC()->cart->add_to_cart( $product_id, 1, 0, [], $cart_item_data );

        if ( ! $cart_key ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Impossible d\'ajouter la réservation au panier.', 'lm-booking' ) ],
                400
            );
        }

        // Add add-ons as linked cart items.
        $addon_errors = [];

        if ( ! empty( $addons ) && is_array( $addons ) ) {
            LM_Booking_Addons::$adding_addon_context = true;

            // Index the add-ons configured on this product by their ID, so we
            // can validate each requested add-on against its own settings.
            $addon_config = [];
            foreach ( $product->get_booking_addons() as $ba ) {
                $addon_config[ (int) $ba['product_id'] ] = $ba;
            }

            foreach ( $addons as $addon ) {
                $addon_product_id = absint( $addon['product_id'] ?? 0 );

                // Reject add-ons that are not configured on this product.
                if ( ! isset( $addon_config[ $addon_product_id ] ) ) {
                    continue;
                }

                $config = $addon_config[ $addon_product_id ];

                // Clamp the requested quantity to the configured maximum — the
                // client must not be trusted to respect max_qty (UI-only limit).
                $max_qty   = max( 1, (int) ( $config['max_qty'] ?? 1 ) );
                $addon_qty = min( $max_qty, max( 1, absint( $addon['quantity'] ?? 1 ) ) );

                $addon_data = [
                    '_lm_booking_addon'      => true,
                    '_lm_booking_parent_key' => $cart_key,
                    '_lm_booking_parent_id'  => $product_id,
                ];

                // Apply price override if configured.
                if ( null !== ( $config['price_override'] ?? null ) ) {
                    $addon_data['_lm_booking_price_override'] = (float) $config['price_override'];
                }

                $addon_key = WC()->cart->add_to_cart( $addon_product_id, $addon_qty, 0, [], $addon_data );

                if ( ! $addon_key ) {
                    $addon_errors[] = $addon_product_id;
                }
            }

            LM_Booking_Addons::$adding_addon_context = false;
        }

        return new WP_REST_Response( [
            'success'       => true,
            'cart_key'      => $cart_key,
            'cart_url'      => wc_get_cart_url(),
            'message'       => __( 'Réservation ajoutée au panier !', 'lm-booking' ),
            'addon_errors'  => $addon_errors,
        ] );
    }

    /**
     * GET /products/search — search simple products for the add-on manager.
     */
    public function search_products( WP_REST_Request $request ): WP_REST_Response {
        $term = $request->get_param( 'term' );

        $products = wc_get_products( [
            'status' => 'publish',
            'type'   => 'simple',
            'limit'  => 20,
            's'      => $term,
        ] );

        $results = array_map( function ( WC_Product $product ) {
            return [
                'id'        => $product->get_id(),
                'name'      => $product->get_name(),
                'price'     => (float) $product->get_price(),
                'image'     => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
                'stock_qty' => $product->get_stock_quantity(),
                'in_stock'  => $product->is_in_stock(),
            ];
        }, $products );

        return new WP_REST_Response( $results );
    }
}
