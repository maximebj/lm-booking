<?php
/**
 * Admin hooks — enqueue scripts, general admin setup.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

class LM_Booking_Admin {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
    }

    /**
     * Enqueue admin React app on the product edit screen.
     */
    public function enqueue_admin_scripts( string $hook ): void {
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->id ) {
            return;
        }

        $asset_file = LM_BOOKING_PATH . 'build/admin.asset.php';
        $asset      = file_exists( $asset_file )
            ? require $asset_file
            : [ 'dependencies' => [], 'version' => LM_BOOKING_VERSION ];

        wp_enqueue_script(
            'lm-booking-admin',
            LM_BOOKING_URL . 'build/admin.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'lm-booking-admin',
            LM_BOOKING_URL . 'assets/css/admin.css',
            [],
            LM_BOOKING_VERSION
        );

        // Pass existing meta data for the product being edited.
        global $post;
        $product_id = $post ? $post->ID : 0;

        wp_localize_script( 'lm-booking-admin', 'lmBookingAdmin', [
            'productId'     => $product_id,
            'weeklyHours'   => get_post_meta( $product_id, '_lm_booking_weekly_hours', true ) ?: [],
            'dateOverrides' => get_post_meta( $product_id, '_lm_booking_date_overrides', true ) ?: [],
            'addons'        => self::hydrate_addons( get_post_meta( $product_id, '_lm_booking_addons', true ) ?: [] ),
            'restUrl'       => esc_url_raw( rest_url( 'lm-booking/v1' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'searchUrl'     => esc_url_raw( rest_url( 'wc/v3/products' ) ),
            'i18n'          => [
                'monday'       => __( 'Lundi', 'lm-booking' ),
                'tuesday'      => __( 'Mardi', 'lm-booking' ),
                'wednesday'    => __( 'Mercredi', 'lm-booking' ),
                'thursday'     => __( 'Jeudi', 'lm-booking' ),
                'friday'       => __( 'Vendredi', 'lm-booking' ),
                'saturday'     => __( 'Samedi', 'lm-booking' ),
                'sunday'       => __( 'Dimanche', 'lm-booking' ),
                'enabled'      => __( 'Activé', 'lm-booking' ),
                'start'        => __( 'Début', 'lm-booking' ),
                'end'          => __( 'Fin', 'lm-booking' ),
                'addOverride'  => __( 'Ajouter une exception', 'lm-booking' ),
                'closed'       => __( 'Fermé', 'lm-booking' ),
                'specialHours' => __( 'Horaires spéciaux', 'lm-booking' ),
                'addAddon'     => __( 'Ajouter un add-on', 'lm-booking' ),
                'searchProduct' => __( 'Rechercher un produit…', 'lm-booking' ),
                'optional'     => __( 'Optionnel', 'lm-booking' ),
                'included'     => __( 'Inclus', 'lm-booking' ),
                'maxQty'       => __( 'Qté max', 'lm-booking' ),
                'priceOverride' => __( 'Prix custom', 'lm-booking' ),
                'remove'       => __( 'Retirer', 'lm-booking' ),
            ],
        ] );
    }

    /**
     * Hydrate stored add-on entries with product name, price and image.
     */
    private static function hydrate_addons( array $addons ): array {
        return array_values( array_filter( array_map( function ( $addon ) {
            $product = wc_get_product( $addon['product_id'] ?? 0 );
            if ( ! $product ) {
                return null;
            }
            $addon['name']  = $product->get_name();
            $addon['price'] = (float) $product->get_price();
            $addon['image'] = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
            return $addon;
        }, $addons ) ) );
    }
}
