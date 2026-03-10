<?php
/**
 * Product data panel — "Réservation" tab content.
 *
 * @package LM_Booking
 */

defined( 'ABSPATH' ) || exit;

global $post;
$product_id = $post->ID;
?>
<div id="lm_booking_options" class="panel woocommerce_options_panel hidden">

    <div class="options_group">
        <h4 style="padding-left:12px;"><?php esc_html_e( 'Configuration du créneau', 'lm-booking' ); ?></h4>

        <?php
        woocommerce_wp_text_input( [
            'id'                => '_lm_booking_duration',
            'label'             => __( 'Durée (minutes)', 'lm-booking' ),
            'type'              => 'number',
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
            <input type="hidden" name="_lm_booking_weekly_hours" id="_lm_booking_weekly_hours"
                   value="<?php echo esc_attr( wp_json_encode( get_post_meta( $product_id, '_lm_booking_weekly_hours', true ) ?: new stdClass() ) ); ?>" />
        </div>
    </div>

    <div class="options_group">
        <h4 style="padding-left:12px;"><?php esc_html_e( 'Exceptions de dates', 'lm-booking' ); ?></h4>
        <div id="lm-booking-date-overrides">
            <!-- React: DateOverrides component mounts here -->
            <input type="hidden" name="_lm_booking_date_overrides" id="_lm_booking_date_overrides"
                   value="<?php echo esc_attr( wp_json_encode( get_post_meta( $product_id, '_lm_booking_date_overrides', true ) ?: new stdClass() ) ); ?>" />
        </div>
    </div>

    <div class="options_group">
        <h4 style="padding-left:12px;"><?php esc_html_e( 'Add-ons', 'lm-booking' ); ?></h4>
        <p style="padding-left:12px; color:#666;">
            <?php esc_html_e( 'Sélectionnez des produits simples existants à proposer en supplément avec cette réservation. Ces produits ne seront plus achetables individuellement.', 'lm-booking' ); ?>
        </p>
        <div id="lm-booking-addon-manager">
            <!-- React: AddonManager component mounts here -->
            <input type="hidden" name="_lm_booking_addons" id="_lm_booking_addons"
                   value="<?php echo esc_attr( wp_json_encode( get_post_meta( $product_id, '_lm_booking_addons', true ) ?: [] ) ); ?>" />
        </div>
    </div>

</div>
