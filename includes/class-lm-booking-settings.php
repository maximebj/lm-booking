<?php

/**
 * Plugin settings — global defaults and cross-cutting options.
 *
 * Always loaded (the option getters are needed on the front end too);
 * the settings page itself is only registered in admin.
 *
 * @package LM_Booking
 */

defined('ABSPATH') || exit;

class LM_Booking_Settings
{

    /**
     * Valid values for the default booking type option.
     * 'fixed' = fixed slots; the other values are flexible mode units.
     */
    public const TYPES = ['fixed', 'hour', 'half_day', 'day'];

    /**
     * Fallback half-day definition.
     */
    private const DEFAULT_HALF_DAY_HOURS = [
        'morning_start'   => '09:00',
        'morning_end'     => '13:00',
        'afternoon_start' => '14:00',
        'afternoon_end'   => '18:00',
    ];

    public function __construct()
    {
        if (! is_admin()) {
            return;
        }

        // Priority 20: the parent "Réservations" menu (LM_Booking_Calendar)
        // must be registered first, otherwise WordPress can't resolve the
        // submenu URL (raw /wp-admin/<slug> → 404) and doesn't prepend the
        // parent as first submenu item.
        add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('admin_init', [$this, 'register_settings']);

        // The settings page is open to shop managers, but options.php checks
        // 'manage_options' by default — align it with the menu capability.
        add_filter('option_page_capability_lm_booking_settings', fn() => 'manage_woocommerce');
    }

    /*
    |--------------------------------------------------------------------------
    | Option getters (usable anywhere, admin and front).
    |--------------------------------------------------------------------------
    */

    /**
     * Default booking type applied to new bookable products.
     *
     * @return string 'fixed' | 'hour' | 'half_day' | 'day'
     */
    public static function get_default_type(): string
    {
        $type = get_option('lm_booking_default_type', 'fixed');
        return in_array($type, self::TYPES, true) ? $type : 'fixed';
    }

    /**
     * Global half-day definition (site-local times, H:i).
     *
     * @return array{morning_start: string, morning_end: string, afternoon_start: string, afternoon_end: string}
     */
    public static function get_half_day_hours(): array
    {
        $hours = get_option('lm_booking_half_day_hours', []);
        if (! is_array($hours)) {
            $hours = [];
        }
        $hours = array_merge(self::DEFAULT_HALF_DAY_HOURS, $hours);

        return array_intersect_key($hours, self::DEFAULT_HALF_DAY_HOURS);
    }

    /**
     * Labels for the booking types (shared with the product panel).
     *
     * @return array<string, string>
     */
    public static function get_type_labels(): array
    {
        return [
            'fixed'    => __('Créneau fixe (durée définie par le produit)', 'lm-booking'),
            'hour'     => __('Flexible — à l\'heure', 'lm-booking'),
            'half_day' => __('Flexible — à la demi-journée', 'lm-booking'),
            'day'      => __('Flexible — à la journée', 'lm-booking'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Settings page.
    |--------------------------------------------------------------------------
    */

    /**
     * Register the settings page under the "Réservations" menu.
     */
    public function register_menu(): void
    {
        add_submenu_page(
            'lm-reservations',
            __('Réglages des Réservations', 'lm-booking'),
            __('Réglages', 'lm-booking'),
            'manage_woocommerce',
            'lm-booking-settings',
            [$this, 'render_page']
        );
    }

    /**
     * Register options, sections and fields.
     */
    public function register_settings(): void
    {
        register_setting('lm_booking_settings', 'lm_booking_default_type', [
            'type'              => 'string',
            'default'           => 'fixed',
            'sanitize_callback' => [$this, 'sanitize_default_type'],
        ]);

        register_setting('lm_booking_settings', 'lm_booking_half_day_hours', [
            'type'              => 'array',
            'default'           => self::DEFAULT_HALF_DAY_HOURS,
            'sanitize_callback' => [$this, 'sanitize_half_day_hours'],
        ]);

        add_settings_section(
            'lm_booking_defaults',
            __('Valeurs par défaut', 'lm-booking'),
            function () {
                echo '<p>' . esc_html__('Type de réservation pré-sélectionné à la création d\'un nouveau produit réservable. Le type reste modifiable sur chaque produit.', 'lm-booking') . '</p>';
            },
            'lm-booking-settings'
        );

        add_settings_field(
            'lm_booking_default_type',
            __('Type de réservation par défaut', 'lm-booking'),
            [$this, 'render_default_type_field'],
            'lm-booking-settings',
            'lm_booking_defaults'
        );

        add_settings_section(
            'lm_booking_half_days',
            __('Demi-journées', 'lm-booking'),
            function () {
                echo '<p>' . esc_html__('Définition globale du matin et de l\'après-midi. Utilisée par les produits réservables à la demi-journée et par les raccourcis "Matinée / Après-midi / Journée" des produits réservables à l\'heure.', 'lm-booking') . '</p>';
            },
            'lm-booking-settings'
        );

        add_settings_field(
            'lm_booking_half_day_hours',
            __('Horaires des demi-journées', 'lm-booking'),
            [$this, 'render_half_day_hours_field'],
            'lm-booking-settings',
            'lm_booking_half_days'
        );
    }

    /**
     * Sanitize the default type option.
     */
    public function sanitize_default_type($value): string
    {
        return in_array($value, self::TYPES, true) ? $value : 'fixed';
    }

    /**
     * Sanitize the half-day hours option: each bound must be H:i, morning
     * must precede afternoon, start must precede end.
     */
    public function sanitize_half_day_hours($value): array
    {
        $defaults = self::DEFAULT_HALF_DAY_HOURS;

        if (! is_array($value)) {
            return $defaults;
        }

        $clean = [];
        foreach ($defaults as $key => $default) {
            $time          = trim((string) ($value[$key] ?? ''));
            $clean[$key] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : $default;
        }

        $ordered = $clean['morning_start'] < $clean['morning_end']
            && $clean['morning_end'] <= $clean['afternoon_start']
            && $clean['afternoon_start'] < $clean['afternoon_end'];

        if (! $ordered) {
            add_settings_error(
                'lm_booking_half_day_hours',
                'lm_booking_half_day_hours_order',
                __('Les horaires de demi-journées doivent être croissants (matin avant après-midi). Valeurs par défaut rétablies.', 'lm-booking')
            );
            return $defaults;
        }

        return $clean;
    }

    /**
     * Render the default type select.
     */
    public function render_default_type_field(): void
    {
        $current = self::get_default_type();
?>
        <select name="lm_booking_default_type" id="lm_booking_default_type">
            <?php foreach (self::get_type_labels() as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php
    }

    /**
     * Render the half-day hours fields.
     */
    public function render_half_day_hours_field(): void
    {
        $hours  = self::get_half_day_hours();
        $fields = [
            'morning_start'   => __('Matin — début', 'lm-booking'),
            'morning_end'     => __('Matin — fin', 'lm-booking'),
            'afternoon_start' => __('Après-midi — début', 'lm-booking'),
            'afternoon_end'   => __('Après-midi — fin', 'lm-booking'),
        ];

        foreach ($fields as $key => $label) {
            printf(
                '<p><label for="lm_booking_half_day_%1$s">%2$s</label><br /><input type="time" id="lm_booking_half_day_%1$s" name="lm_booking_half_day_hours[%1$s]" value="%3$s" /></p>',
                esc_attr($key),
                esc_html($label),
                esc_attr($hours[$key])
            );
        }
    }

    /**
     * Render the settings page.
     */
    public function render_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
    ?>
        <div class="wrap">
            <h1><?php esc_html_e('Réglages des Réservations', 'lm-booking'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('lm_booking_settings');
                do_settings_sections('lm-booking-settings');
                submit_button();
                ?>
            </form>
        </div>
<?php
    }
}
