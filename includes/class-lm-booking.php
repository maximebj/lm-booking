<?php
/**
 * Main plugin class — singleton that wires all components together.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking {

    /** @var self|null */
    private static ?self $instance = null;

    /**
     * Get the singleton instance.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — load dependencies and register hooks.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
    }

    /**
     * Include required files.
     */
    private function load_dependencies(): void {
        $dir = LM_BOOKING_PATH . 'includes/';

        require_once $dir . 'class-lm-booking-install.php';
        require_once $dir . 'class-lm-booking-settings.php';
        require_once $dir . 'class-wc-product-booking.php';
        require_once $dir . 'class-lm-booking-repository.php';
        require_once $dir . 'availability/abstract-lm-booking-mode.php';
        require_once $dir . 'availability/class-lm-booking-mode-fixed.php';
        require_once $dir . 'class-lm-booking-availability.php';
        require_once $dir . 'class-lm-booking-addons.php';
        require_once $dir . 'class-lm-booking-cart.php';
        require_once $dir . 'class-lm-booking-checkout.php';
        require_once $dir . 'class-lm-booking-order.php';
        require_once $dir . 'class-lm-booking-rest-api.php';

        if ( is_admin() ) {
            require_once LM_BOOKING_PATH . 'admin/class-lm-booking-admin.php';
            require_once LM_BOOKING_PATH . 'admin/class-lm-booking-meta-boxes.php';
            require_once LM_BOOKING_PATH . 'admin/class-lm-booking-calendar.php';
        }
    }

    /**
     * Register all hooks.
     */
    private function register_hooks(): void {
        // Register custom product type.
        add_filter( 'product_type_selector', [ $this, 'add_product_type' ] );
        add_filter( 'woocommerce_product_class', [ $this, 'product_class' ], 10, 2 );

        // Load translations.
        add_action( 'init', [ $this, 'load_textdomain' ] );

        // Initialize components.
        new LM_Booking_Settings();
        new LM_Booking_Addons();
        new LM_Booking_Cart();
        new LM_Booking_Checkout();
        new LM_Booking_Order();

        add_action( 'rest_api_init', [ new LM_Booking_REST_API(), 'register_routes' ] );

        if ( is_admin() ) {
            new LM_Booking_Admin();
            new LM_Booking_Meta_Boxes();
            new LM_Booking_Calendar();
        }

        // Front-end scripts.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ] );
    }

    /**
     * Add "Réservable" to the product type selector.
     */
    public function add_product_type( array $types ): array {
        $types['booking'] = __( 'Réservable', 'lm-booking' );
        return $types;
    }

    /**
     * Map the "booking" product type to our custom class.
     */
    public function product_class( string $classname, string $product_type ): string {
        if ( 'booking' === $product_type ) {
            return 'WC_Product_Booking';
        }
        return $classname;
    }

    /**
     * Load plugin text domain.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain( 'lm-booking', false, dirname( LM_BOOKING_BASENAME ) . '/languages' );
    }

    /**
     * Enqueue front-end React app on bookable product pages.
     */
    public function enqueue_frontend_scripts(): void {
        if ( ! is_product() ) {
            return;
        }

        $product = wc_get_product( get_queried_object_id() );
        if ( ! $product instanceof WC_Product || 'booking' !== $product->get_type() ) {
            return;
        }

        $asset_file = LM_BOOKING_PATH . 'build/frontend.asset.php';
        $asset      = file_exists( $asset_file )
            ? require $asset_file
            : [ 'dependencies' => [], 'version' => LM_BOOKING_VERSION ];

        wp_enqueue_script(
            'lm-booking-frontend',
            LM_BOOKING_URL . 'build/frontend.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'lm-booking-frontend',
            LM_BOOKING_URL . 'assets/css/frontend.css',
            [],
            LM_BOOKING_VERSION
        );

        // Pass product data to JS.
        $addons_raw = get_post_meta( $product->get_id(), '_lm_booking_addons', true );
        $addons     = is_array( $addons_raw ) ? $addons_raw : [];

        $addons_data = array_map( function ( $addon ) {
            $addon_product = wc_get_product( $addon['product_id'] ?? 0 );
            if ( ! $addon_product ) {
                return null;
            }
            return [
                'product_id'     => $addon['product_id'],
                'name'           => $addon_product->get_name(),
                'type'           => $addon['type'] ?? 'optional',
                'max_qty'        => (int) ( $addon['max_qty'] ?? 1 ),
                'price'          => isset( $addon['price_override'] ) && null !== $addon['price_override']
                    ? (float) $addon['price_override']
                    : (float) $addon_product->get_price(),
                'price_override' => $addon['price_override'] ?? null,
                'in_stock'       => $addon_product->is_in_stock(),
                'stock_qty'      => $addon_product->get_stock_quantity(),
                'image'          => wp_get_attachment_image_url( $addon_product->get_image_id(), 'thumbnail' ),
            ];
        }, $addons );

        $addons_data = array_values( array_filter( $addons_data ) );

        wp_localize_script( 'lm-booking-frontend', 'lmBookingData', [
            'productId'  => $product->get_id(),
            'duration'   => (int) get_post_meta( $product->get_id(), '_lm_booking_duration', true ),
            'capacity'   => (int) get_post_meta( $product->get_id(), '_lm_booking_capacity', true ),
            'buffer'     => (int) get_post_meta( $product->get_id(), '_lm_booking_buffer', true ),
            'minAdvance' => (int) get_post_meta( $product->get_id(), '_lm_booking_min_advance', true ),
            'maxAdvance' => (int) get_post_meta( $product->get_id(), '_lm_booking_max_advance', true ),
            'price'      => (float) $product->get_price(),
            'currency'   => get_woocommerce_currency_symbol(),
            'addons'     => $addons_data,
            'restUrl'    => esc_url_raw( rest_url( 'lm-booking/v1' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'cartUrl'    => wc_get_cart_url(),
            'wcNonce'    => wp_create_nonce( 'wc_store_api' ),
            'i18n'       => [
                'selectDate'    => __( 'Sélectionnez une date', 'lm-booking' ),
                'selectSlot'    => __( 'Choisissez un créneau', 'lm-booking' ),
                'addons'        => __( 'Options supplémentaires', 'lm-booking' ),
                'included'      => __( 'Inclus', 'lm-booking' ),
                'optional'      => __( 'En option', 'lm-booking' ),
                'bookNow'       => __( 'Réserver', 'lm-booking' ),
                'outOfStock'    => __( 'Indisponible', 'lm-booking' ),
                'noSlots'       => __( 'Aucun créneau disponible pour cette date.', 'lm-booking' ),
                'loading'       => __( 'Chargement…', 'lm-booking' ),
                'summary'       => __( 'Résumé', 'lm-booking' ),
                'total'         => __( 'Total', 'lm-booking' ),
                'available'     => __( 'disponible', 'lm-booking' ),
                'unavailable'   => __( 'complet', 'lm-booking' ),
                'addedToCart'   => __( 'Réservation ajoutée au panier !', 'lm-booking' ),
                'errorAdding'   => __( 'Erreur lors de l\'ajout au panier.', 'lm-booking' ),
            ],
        ] );
    }
}
