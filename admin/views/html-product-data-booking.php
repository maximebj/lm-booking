<?php
/**
 * Product data panel — "Réservation" tab content.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

global $post;
$product_id = $post->ID;

// Current booking type: mode + unit metas collapsed into one select value.
// New products (auto-draft, no meta yet) start on the site-wide default;
// products saved before the mode existed behave as 'fixed'.
$lm_mode = get_post_meta( $product_id, '_lm_booking_mode', true );
if ( 'flexible' === $lm_mode ) {
    $lm_booking_type = get_post_meta( $product_id, '_lm_booking_unit', true ) ?: 'hour';
} elseif ( 'fixed' === $lm_mode || 'auto-draft' !== get_post_status( $product_id ) ) {
    $lm_booking_type = 'fixed';
} else {
    $lm_booking_type = LM_Booking_Settings::get_default_type();
}
?>
<div id="lm_booking_options" class="panel woocommerce_options_panel hidden">

    <div class="options_group">
        <h4 style="padding-left:12px;"><?php esc_html_e( 'Type de réservation', 'lm-booking' ); ?></h4>

        <p class="form-field _lm_booking_type_field">
            <label for="_lm_booking_type"><?php esc_html_e( 'Type de réservation', 'lm-booking' ); ?></label>
            <select id="_lm_booking_type" name="_lm_booking_type">
                <?php foreach ( LM_Booking_Settings::get_type_labels() as $type_value => $type_label ) : ?>
                    <option
                        value="<?php echo esc_attr( $type_value ); ?>"
                        <?php selected( $lm_booking_type, $type_value ); ?>
                        <?php disabled( 'fixed' !== $type_value ); // Phase 1 : les modes flexibles arrivent en phases 2 et 3. ?>
                    >
                        <?php echo esc_html( $type_label ); ?><?php echo 'fixed' !== $type_value ? esc_html__( ' — bientôt disponible', 'lm-booking' ) : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php echo wc_help_tip( __( 'Créneau fixe : le client choisit un créneau de durée définie. Flexible : le client choisit la durée (en heures, demi-journées ou journées).', 'lm-booking' ) ); ?>
        </p>

        <script>
            jQuery( function ( $ ) {
                // Show/hide the fields that only apply to the fixed-slot mode.
                function lmBookingToggleModeFields() {
                    var isFixed = 'fixed' === $( '#_lm_booking_type' ).val();
                    $( '.lm-booking-show-if-fixed' ).toggle( isFixed );
                }
                $( '#_lm_booking_type' ).on( 'change', lmBookingToggleModeFields );
                lmBookingToggleModeFields();
            } );
        </script>
    </div>

    <div class="options_group">
        <h4 style="padding-left:12px;"><?php esc_html_e( 'Configuration du créneau', 'lm-booking' ); ?></h4>

        <?php
        woocommerce_wp_text_input( [
            'id'                => '_lm_booking_duration',
            'label'             => __( 'Durée (minutes)', 'lm-booking' ),
            'type'              => 'number',
            'wrapper_class'     => 'lm-booking-show-if-fixed',
            'custom_attributes' => [ 'min' => '5', 'step' => '5' ],
            'value'             => get_post_meta( $product_id, '_lm_booking_duration', true ) ?: '60',
            'desc_tip'          => true,
            'description'       => __( 'Durée d\'un créneau de réservation en minutes.', 'lm-booking' ),
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_lm_booking_capacity',
            'label'             => __( 'Capacité', 'lm-booking' ),
            'type'              => 'number',
            'custom_attributes' => [ 'min' => '1' ],
            'value'             => get_post_meta( $product_id, '_lm_booking_capacity', true ) ?: '1',
            'desc_tip'          => true,
            'description'       => __( 'Nombre de réservations simultanées possibles pour un même créneau.', 'lm-booking' ),
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_lm_booking_buffer',
            'label'             => __( 'Temps tampon (minutes)', 'lm-booking' ),
            'type'              => 'number',
            'wrapper_class'     => 'lm-booking-show-if-fixed',
            'custom_attributes' => [ 'min' => '0', 'step' => '5' ],
            'value'             => get_post_meta( $product_id, '_lm_booking_buffer', true ) ?: '0',
            'desc_tip'          => true,
            'description'       => __( 'Temps de battement entre deux créneaux (ex : nettoyage, préparation).', 'lm-booking' ),
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_lm_booking_min_advance',
            'label'             => __( 'Délai minimum (heures)', 'lm-booking' ),
            'type'              => 'number',
            'custom_attributes' => [ 'min' => '0' ],
            'value'             => get_post_meta( $product_id, '_lm_booking_min_advance', true ) ?: '1',
            'desc_tip'          => true,
            'description'       => __( 'Le client doit réserver au minimum X heures à l\'avance.', 'lm-booking' ),
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_lm_booking_max_advance',
            'label'             => __( 'Délai maximum (jours)', 'lm-booking' ),
            'type'              => 'number',
            'custom_attributes' => [ 'min' => '1' ],
            'value'             => get_post_meta( $product_id, '_lm_booking_max_advance', true ) ?: '90',
            'desc_tip'          => true,
            'description'       => __( 'Le client peut réserver jusqu\'à X jours à l\'avance.', 'lm-booking' ),
        ] );
        ?>
    </div>

    <div class="options_group">
        <h4 style="padding-left:12px;"><?php esc_html_e( 'Horaires d\'ouverture', 'lm-booking' ); ?></h4>
        <div id="lm-booking-weekly-schedule">
            <!-- React: WeeklySchedule component mounts here -->
        </div>
    </div>

    <div class="options_group">
        <h4 style="padding-left:12px;"><?php esc_html_e( 'Exceptions de dates', 'lm-booking' ); ?></h4>
        <div id="lm-booking-date-overrides">
            <!-- React: DateOverrides component mounts here -->
        </div>
    </div>

    <div class="options_group">
        <h4 style="padding-left:12px;"><?php esc_html_e( 'Add-ons', 'lm-booking' ); ?></h4>
        <p style="padding-left:12px; color:#666;">
            <?php esc_html_e( 'Sélectionnez des produits simples existants à proposer en supplément avec cette réservation. Ces produits ne seront plus achetables individuellement.', 'lm-booking' ); ?>
        </p>
        <div id="lm-booking-addon-manager">
            <!-- React: AddonManager component mounts here -->
        </div>
    </div>

</div>
