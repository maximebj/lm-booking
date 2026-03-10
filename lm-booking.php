<?php
/**
 * Plugin Name: LM Booking
 * Plugin URI:  https://la-marketerie.com
 * Description: Système de réservation intégré à WooCommerce — créneaux temporels, gestion de capacité et add-ons.
 * Version:     1.0.0
 * Author:      La Marketerie
 * Author URI:  https://la-marketerie.com
 * Text Domain: lm-booking
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'LM_BOOKING_VERSION', '1.0.0' );
define( 'LM_BOOKING_FILE', __FILE__ );
define( 'LM_BOOKING_PATH', plugin_dir_path( __FILE__ ) );
define( 'LM_BOOKING_URL', plugin_dir_url( __FILE__ ) );
define( 'LM_BOOKING_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

/**
 * Check that WooCommerce is active before bootstrapping.
 */
function lm_booking_check_requirements() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'LM Booking nécessite WooCommerce pour fonctionner. Veuillez installer et activer WooCommerce.', 'lm-booking' );
            echo '</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * Activation hook.
 */
function lm_booking_activate() {
    require_once LM_BOOKING_PATH . 'includes/class-lm-booking-install.php';
    LM_Booking_Install::activate();
}
register_activation_hook( __FILE__, 'lm_booking_activate' );

/**
 * Deactivation hook.
 */
function lm_booking_deactivate() {
    // Clean up transients if needed.
    delete_transient( 'lm_booking_activating' );
}
register_deactivation_hook( __FILE__, 'lm_booking_deactivate' );

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
add_action( 'plugins_loaded', function () {
    if ( ! lm_booking_check_requirements() ) {
        return;
    }

    require_once LM_BOOKING_PATH . 'includes/class-lm-booking.php';
    LM_Booking::instance();
}, 20 );
