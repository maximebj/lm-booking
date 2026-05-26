<?php

/**
 * Admin calendar page — monthly reservation grid + day detail panel.
 *
 * @package LM_Booking
 */

defined('ABSPATH') || exit;

class LM_Booking_Calendar
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Réservations', 'lm-booking'),
            __('Réservations', 'lm-booking'),
            'manage_woocommerce',
            'lm-reservations',
            [$this, 'render_page'],
            'dashicons-calendar-alt',
            2
        );
    }

    public function enqueue_scripts(string $hook): void
    {
        if ('toplevel_page_lm-reservations' !== $hook) {
            return;
        }
        wp_enqueue_style(
            'lm-booking-calendar',
            LM_BOOKING_URL . 'assets/css/calendar.css',
            [],
            LM_BOOKING_VERSION
        );
    }

    public function render_page(): void
    {
        // ── Résolution année / mois ───────────────────────────────────────────
        $year  = isset($_GET['year'])  ? absint($_GET['year'])  : (int) date_i18n('Y');
        $month = isset($_GET['month']) ? absint($_GET['month']) : (int) date_i18n('n');
        if ($month < 1 || $month > 12) {
            $month = (int) date_i18n('n');
            $year  = (int) date_i18n('Y');
        }

        // ── Mois précédent / suivant ──────────────────────────────────────────
        $prev_month = $month - 1;
        $prev_year = $year;
        if ($prev_month < 1) {
            $prev_month = 12;
            $prev_year--;
        }
        $next_month = $month + 1;
        $next_year = $year;
        if ($next_month > 12) {
            $next_month = 1;
            $next_year++;
        }

        $base     = admin_url('admin.php');
        $prev_url = add_query_arg(['page' => 'lm-reservations', 'year' => $prev_year, 'month' => $prev_month], $base);
        $next_url = add_query_arg(['page' => 'lm-reservations', 'year' => $next_year, 'month' => $next_month], $base);
        $today_url = admin_url('admin.php?page=lm-reservations');

        // ── Données du mois ───────────────────────────────────────────────────
        $tz       = wp_timezone();
        $utc_tz   = new DateTimeZone('UTC');
        $start_dt = new DateTimeImmutable("{$year}-{$month}-01 00:00:00", $tz);
        $end_dt   = $start_dt->modify('+1 month');

        $rows     = LM_Booking_Repository::get_by_month(
            $start_dt->setTimezone($utc_tz)->format('Y-m-d H:i:s'),
            $end_dt->setTimezone($utc_tz)->format('Y-m-d H:i:s')
        );
        $bookings = $this->hydrate_bookings($rows, $tz, $utc_tz);

        // Regroupement par date locale
        $by_date = [];
        foreach ($bookings as $b) {
            $by_date[$b['date_local']][] = $b;
        }

        // ── Paramètres du calendrier ──────────────────────────────────────────
        $days_in_month = (int) $start_dt->format('t');
        $first_dow     = (int) $start_dt->format('N'); // 1=Lun … 7=Dim
        $last_dow      = (int) (new DateTimeImmutable("{$year}-{$month}-{$days_in_month}", $tz))->format('N');
        $today         = date_i18n('Y-m-d');
        $month_label   = ucfirst(date_i18n('F Y', mktime(0, 0, 0, $month, 1, $year)));
        $day_names     = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

        // ── Date sélectionnée initialement ────────────────────────────────────
        $selected_day = isset($_GET['selected_day']) ? absint($_GET['selected_day']) : 0;
        if ($selected_day < 1 || $selected_day > $days_in_month) {
            $selected_day = 0;
        }

        [$ty, $tm] = explode('-', $today);
        $today_in_month = ((int) $ty === $year && (int) $tm === $month);

        if ($selected_day > 0) {
            $initial_date = sprintf('%04d-%02d-%02d', $year, $month, $selected_day);
        } elseif ($today_in_month) {
            $initial_date = $today;
        } else {
            $initial_date = sprintf('%04d-%02d-%02d', $year, $month, 1);
        }

?>
        <div class="wrap lm-calendar-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Réservations', 'lm-booking'); ?></h1>
            <hr class="wp-header-end">

            <div class="lm-calendar-layout">

                <!-- ── Grille mensuelle ───────────────────────────────────── -->
                <div class="lm-calendar-main">

                    <div class="lm-calendar-nav">
                        <a href="<?php echo esc_url($prev_url); ?>" class="button">&larr; <?php esc_html_e('Mois précédent', 'lm-booking'); ?></a>
                        <h2 class="lm-calendar-title"><?php echo esc_html($month_label); ?></h2>
                        <a href="<?php echo esc_url($next_url); ?>" class="button"><?php esc_html_e('Mois suivant', 'lm-booking'); ?> &rarr;</a>
                        <a href="<?php echo esc_url($today_url); ?>" class="button button-secondary lm-today-btn"><?php esc_html_e("Aujourd'hui", 'lm-booking'); ?></a>
                    </div>

                    <div class="lm-calendar-legend">
                        <span class="lm-legend-item lm-legend--pending"><?php esc_html_e('En attente / en cours', 'lm-booking'); ?></span>
                        <span class="lm-legend-item lm-legend--completed"><?php esc_html_e('Terminé', 'lm-booking'); ?></span>
                        <span class="lm-legend-item lm-legend--cancelled"><?php esc_html_e('Annulé', 'lm-booking'); ?></span>
                    </div>

                    <div class="lm-calendar-grid">

                        <?php foreach ($day_names as $name) : ?>
                            <div class="lm-cal-header"><?php echo esc_html($name); ?></div>
                        <?php endforeach; ?>

                        <?php for ($i = 1; $i < $first_dow; $i++) : ?>
                            <div class="lm-cal-cell lm-cal-cell--empty"></div>
                        <?php endfor; ?>

                        <?php for ($day = 1; $day <= $days_in_month; $day++) :
                            $date_key     = sprintf('%04d-%02d-%02d', $year, $month, $day);
                            $is_today     = ($date_key === $today);
                            $is_selected  = ($date_key === $initial_date);
                            $has_bookings = ! empty($by_date[$date_key]);
                            $cell_class   = 'lm-cal-cell';
                            if ($is_today)     $cell_class .= ' lm-cal-cell--today';
                            if ($is_selected)  $cell_class .= ' lm-cal-cell--selected';
                            if ($has_bookings) $cell_class .= ' lm-cal-cell--has-bookings';
                        ?>
                            <div class="<?php echo esc_attr($cell_class); ?>"
                                data-date="<?php echo esc_attr($date_key); ?>">
                                <span class="lm-cal-day-num"><?php echo esc_html($day); ?></span>
                                <?php if ($has_bookings) : ?>
                                    <div class="lm-cal-bookings">
                                        <?php foreach ($by_date[$date_key] as $booking) :
                                            $this->render_chip($booking);
                                        endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>

                        <?php for ($i = $last_dow + 1; $i <= 7; $i++) : ?>
                            <div class="lm-cal-cell lm-cal-cell--empty"></div>
                        <?php endfor; ?>

                    </div><!-- .lm-calendar-grid -->

                    <?php if (empty($bookings)) : ?>
                        <p class="lm-calendar-empty">
                            <?php esc_html_e('Aucune réservation pour ce mois.', 'lm-booking'); ?>
                        </p>
                    <?php endif; ?>

                </div><!-- .lm-calendar-main -->

                <!-- ── Panneau de détail journalier ───────────────────────── -->
                <div class="lm-day-panel" id="lm-day-panel">

                    <div class="lm-day-panel__header">
                        <div class="lm-day-panel__top-row">
                            <span class="lm-day-panel__label">
                                <?php esc_html_e('Réservations du jour', 'lm-booking'); ?>
                            </span>
                            <button id="lm-day-today" class="button button-small">
                                <?php esc_html_e("Aujourd'hui", 'lm-booking'); ?>
                            </button>
                        </div>
                        <div class="lm-day-panel__nav-row">
                            <button id="lm-day-prev" class="lm-day-nav-btn">&larr;</button>
                            <h3 id="lm-day-title" class="lm-day-panel__title"></h3>
                            <button id="lm-day-next" class="lm-day-nav-btn">&rarr;</button>
                        </div>
                    </div>

                    <div class="lm-day-panel__body" id="lm-day-body"></div>

                </div><!-- .lm-day-panel -->

            </div><!-- .lm-calendar-layout -->
        </div><!-- .lm-calendar-wrap -->

        <script>
            (function() {
                var data = <?php echo wp_json_encode($by_date); ?>;
                var meta = <?php
                            // Statuts WC sans préfixe "wc-", pour le select et l'API REST.
                            $wc_statuses = [];
                            foreach (wc_get_order_statuses() as $key => $label) {
                                $wc_statuses[str_replace('wc-', '', $key)] = $label;
                            }
                            echo wp_json_encode([
                                'year'        => $year,
                                'month'       => $month,
                                'today'       => $today,
                                'initialDate' => $initial_date,
                                'prevUrl'     => $prev_url,
                                'nextUrl'     => $next_url,
                                'todayUrl'    => $today_url,
                                'daysInMonth' => $days_in_month,
                                'wcStatuses'  => $wc_statuses,
                                'wcRestUrl'   => esc_url_raw(rest_url('wc/v3')),
                                'restNonce'   => wp_create_nonce('wp_rest'),
                                'i18n'        => [
                                    'noBookings' => __('Aucune réservation ce jour.', 'lm-booking'),
                                    'options'    => __('Options', 'lm-booking'),
                                    'updating'   => __('Mise à jour…', 'lm-booking'),
                                    'error'      => __('Erreur lors de la mise à jour.', 'lm-booking'),
                                ],
                            ]); ?>;

                var titleEl = document.getElementById('lm-day-title');
                var bodyEl = document.getElementById('lm-day-body');
                var cells = document.querySelectorAll('.lm-cal-cell[data-date]');
                var current = meta.initialDate;

                // ── Utilitaires ───────────────────────────────────────────────────

                function esc(s) {
                    return String(s || '')
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                }

                function formatDate(dateStr) {
                    var p = dateStr.split('-');
                    var d = new Date(+p[0], +p[1] - 1, +p[2]);
                    return d.toLocaleDateString('fr-FR', {
                        weekday: 'long',
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric'
                    });
                }

                function pad2(n) {
                    return String(n).padStart(2, '0');
                }

                function prevDate(dateStr) {
                    var p = dateStr.split('-').map(Number);
                    var d = new Date(p[0], p[1] - 1, p[2] - 1);
                    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
                }

                function nextDate(dateStr) {
                    var p = dateStr.split('-').map(Number);
                    var d = new Date(p[0], p[1] - 1, p[2] + 1);
                    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
                }

                function dateMonth(dateStr) {
                    return +dateStr.split('-')[1];
                }

                function dateYear(dateStr) {
                    return +dateStr.split('-')[0];
                }

                function dateDay(dateStr) {
                    return +dateStr.split('-')[2];
                }

                // ── Sélection d'un jour ───────────────────────────────────────────

                function selectDate(dateStr) {
                    current = dateStr;

                    cells.forEach(function(c) {
                        c.classList.toggle('lm-cal-cell--selected', c.dataset.date === dateStr);
                    });

                    titleEl.textContent = formatDate(dateStr);

                    var list = data[dateStr] || [];
                    if (!list.length) {
                        bodyEl.innerHTML = '<p class="lm-day-empty">' + esc(meta.i18n.noBookings) + '</p>';
                        return;
                    }
                    bodyEl.innerHTML = list.map(renderCard).join('');
                }

                // ── Rendu d'une carte de réservation ─────────────────────────────

                // Correspondance statut commande → classe CSS de bordure de carte.
                // Jaune (pending)   = en attente ou en cours
                // Vert  (completed) = terminé
                // Rouge (cancelled) = annulé
                function orderStatusToBooking(s) {
                    if (s === 'completed') return 'completed';
                    if (s === 'cancelled' || s === 'refunded' || s === 'failed') return 'cancelled';
                    return 'pending'; // pending, on-hold, processing
                }

                function renderStatusSelect(b) {
                    var options = Object.keys(meta.wcStatuses).map(function(val) {
                        var sel = val === b.order_status ? ' selected' : '';
                        return '<option value="' + esc(val) + '"' + sel + '>' + esc(meta.wcStatuses[val]) + '</option>';
                    }).join('');
                    return '<select' +
                        ' id="lm-status-' + esc(b.id) + '"' +
                        ' name="order_status"' +
                        ' class="lm-bcard__status-select lm-bcard__status-select--' + esc(b.order_status) + '"' +
                        ' data-order-id="' + esc(b.order_id) + '"' +
                        ' data-card-id="lm-bcard-' + esc(b.id) + '"' +
                        ' data-prev="' + esc(b.order_status) + '">' +
                        options +
                        '</select>';
                }

                function renderCard(b) {
                    var img = b.product_image ?
                        '<img src="' + esc(b.product_image) + '" alt="" class="lm-bcard__img">' :
                        '<div class="lm-bcard__img lm-bcard__img--placeholder"></div>';

                    var phone = b.customer_phone ?
                        '<a href="tel:' + esc(b.customer_phone) + '" class="lm-bcard__phone">' +
                        esc(b.customer_phone) + '</a>' :
                        '';

                    var addonsHtml = '';
                    if (b.addons && b.addons.length) {
                        addonsHtml = '<div class="lm-bcard__addons">' +
                            '<div class="lm-bcard__addons-label">' + esc(meta.i18n.options) + '</div>' +
                            b.addons.map(function(a) {
                                var qty = a.qty > 1 ? ' &times;&thinsp;' + a.qty : '';
                                return '<div class="lm-bcard__addon">' +
                                    '<span>' + esc(a.name) + qty + '</span>' +
                                    '<span>' + esc(a.price_formatted) + '</span>' +
                                    '</div>';
                            }).join('') +
                            '</div>';
                    }

                    return '<div class="lm-bcard lm-bcard--' + esc(orderStatusToBooking(b.order_status)) + '"' +
                        ' id="lm-bcard-' + esc(b.id) + '">'

                        +
                        '<div class="lm-bcard__top">' +
                        img +
                        '<div class="lm-bcard__info">' +
                        '<div class="lm-bcard__time">' + esc(b.time_local) + '</div>' +
                        '<div class="lm-bcard__product">' + esc(b.product_title) + '</div>' +
                        renderStatusSelect(b) +
                        '</div>' +
                        '<a href="' + esc(b.order_url) + '" class="lm-bcard__order-link">' +
                        '#' + esc(b.order_number) + ' &rarr;' +
                        '</a>' +
                        '</div>'

                        +
                        '<div class="lm-bcard__customer">' +
                        '<span class="lm-bcard__name">' + esc(b.customer_name) + '</span>' +
                        phone +
                        '</div>'

                        +
                        '<div class="lm-bcard__footer">' +
                        '<span class="lm-bcard__price">' + esc(b.price_formatted) + '</span>' +
                        '</div>'

                        +
                        addonsHtml

                        +
                        '</div>';
                }

                // ── Changement de statut de commande ─────────────────────────────

                bodyEl.addEventListener('change', function(e) {
                    var sel = e.target;
                    if (!sel.classList.contains('lm-bcard__status-select')) return;

                    var newStatus = sel.value;
                    var prevStatus = sel.dataset.prev;
                    var orderId = sel.dataset.orderId;
                    var card = document.getElementById(sel.dataset.cardId);

                    sel.disabled = true;
                    sel.style.opacity = '0.55';

                    fetch(meta.wcRestUrl + '/orders/' + orderId, {
                            method: 'PUT',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': meta.restNonce,
                            },
                            body: JSON.stringify({
                                status: newStatus
                            }),
                        })
                        .then(function(r) {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.json();
                        })
                        .then(function() {
                            // Mise à jour visuelle du select
                            sel.classList.remove('lm-bcard__status-select--' + prevStatus);
                            sel.classList.add('lm-bcard__status-select--' + newStatus);
                            sel.dataset.prev = newStatus;
                            // Mise à jour de la bordure de la carte
                            if (card) {
                                card.className = card.className.replace(
                                    /lm-bcard--\S+/,
                                    'lm-bcard--' + orderStatusToBooking(newStatus)
                                );
                            }
                        })
                        .catch(function() {
                            // Retour arrière
                            sel.value = prevStatus;
                            alert(meta.i18n.error);
                        })
                        .finally(function() {
                            sel.disabled = false;
                            sel.style.opacity = '';
                        });
                });

                // ── Événements cellules ───────────────────────────────────────────

                cells.forEach(function(cell) {
                    cell.addEventListener('click', function() {
                        selectDate(cell.dataset.date);
                    });
                });

                document.getElementById('lm-day-prev').addEventListener('click', function() {
                    var prev = prevDate(current);
                    if (dateMonth(prev) === meta.month && dateYear(prev) === meta.year) {
                        selectDate(prev);
                    } else {
                        window.location.href = meta.prevUrl + '&selected_day=' + dateDay(prev);
                    }
                });

                document.getElementById('lm-day-next').addEventListener('click', function() {
                    var next = nextDate(current);
                    if (dateMonth(next) === meta.month && dateYear(next) === meta.year) {
                        selectDate(next);
                    } else {
                        window.location.href = meta.nextUrl + '&selected_day=' + dateDay(next);
                    }
                });

                document.getElementById('lm-day-today').addEventListener('click', function() {
                    var t = meta.today;
                    if (dateMonth(t) === meta.month && dateYear(t) === meta.year) {
                        selectDate(t);
                    } else {
                        window.location.href = meta.todayUrl;
                    }
                });

                // ── Init ──────────────────────────────────────────────────────────
                selectDate(current);

            })();
        </script>
    <?php
    }

    /**
     * Render a compact chip for the monthly grid.
     */
    private function render_chip(array $b): void
    {
    ?>
        <div class="lm-chip lm-chip--<?php echo esc_attr($b['status']); ?>">
            <?php if ($b['product_image']) : ?>
                <img src="<?php echo esc_url($b['product_image']); ?>" alt="" class="lm-chip-img">
            <?php endif; ?>
            <span class="lm-chip-body">
                <span class="lm-chip-time"><?php echo esc_html($b['time_local']); ?></span>
                <span class="lm-chip-product"><?php echo esc_html($b['product_title']); ?></span>
                <span class="lm-chip-customer"><?php echo esc_html($b['customer_name']); ?></span>
            </span>
        </div>
<?php
    }

    /**
     * Format a price amount as a plain Unicode string (no HTML entities).
     *
     * wc_price() returns HTML with entities like &nbsp; and &euro;.
     * wp_strip_all_tags() removes tags but keeps entities as raw text,
     * which breaks JSON → JS rendering. html_entity_decode() resolves them
     * to proper UTF-8 characters before JSON serialisation.
     */
    private static function format_price(float $amount): string
    {
        return html_entity_decode(
            wp_strip_all_tags(wc_price($amount)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * Hydrate raw DB rows with product data, order data, add-ons and pricing.
     *
     * @param array<object> $rows
     * @param DateTimeZone  $tz
     * @param DateTimeZone  $utc_tz
     * @return array<array>
     */
    private function hydrate_bookings(array $rows, DateTimeZone $tz, DateTimeZone $utc_tz): array
    {
        if (empty($rows)) {
            return [];
        }

        $product_ids = array_unique(array_map(fn($r) => (int) $r->product_id, $rows));
        $order_ids   = array_unique(array_filter(array_map(fn($r) => (int) $r->order_id, $rows)));

        // ── Produits ──────────────────────────────────────────────────────────
        $products = [];
        foreach ($product_ids as $pid) {
            $p = wc_get_product($pid);
            if ($p) {
                $products[$pid] = [
                    'title' => $p->get_name(),
                    'image' => wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') ?: '',
                ];
            }
        }

        // ── Commandes (compatible HPOS) ───────────────────────────────────────
        $orders = [];
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (! $order) {
                continue;
            }

            // Parcours des lignes de commande pour totaux et add-ons.
            $item_totals       = []; // item_id  → float (total TTC)
            $addons_by_product = []; // product_id → [ {name, qty, price_formatted} ]

            foreach ($order->get_items() as $item_id => $item) {
                if ('yes' === $item->get_meta('_lm_booking')) {
                    $ttc = (float) $item->get_total() + (float) $item->get_total_tax();
                    $item_totals[(int) $item_id] = $ttc;
                } elseif ('yes' === $item->get_meta('_lm_booking_addon')) {
                    $parent_pid = (int) $item->get_meta('_lm_booking_parent_id');
                    if ($parent_pid) {
                        $addon_ttc = (float) $item->get_total() + (float) $item->get_total_tax();
                        $addons_by_product[$parent_pid][] = [
                            'name'            => $item->get_name(),
                            'qty'             => (int) $item->get_quantity(),
                            'price_formatted' => self::format_price($addon_ttc),
                        ];
                    }
                }
            }

            $orders[$oid] = [
                'name'              => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'phone'             => $order->get_billing_phone(),
                'order_number'      => (string) $order->get_order_number(),
                'order_url'         => $order->get_edit_order_url(),
                'order_status'      => $order->get_status(), // sans préfixe "wc-"
                'item_totals'       => $item_totals,
                'addons_by_product' => $addons_by_product,
            ];
        }

        // ── Assemblage ────────────────────────────────────────────────────────
        $hydrated = [];
        foreach ($rows as $row) {
            $start_local = (new DateTimeImmutable($row->start_datetime, $utc_tz))->setTimezone($tz);
            $end_local   = (new DateTimeImmutable($row->end_datetime,   $utc_tz))->setTimezone($tz);

            $pid     = (int) $row->product_id;
            $oid     = (int) $row->order_id;
            $item_id = (int) $row->order_item_id;

            $order_data    = $orders[$oid] ?? null;
            $booking_ttc   = $order_data['item_totals'][$item_id] ?? 0.0;

            // Statut visuel basé sur le statut WC de la commande (même logique que
            // orderStatusToBooking() côté JS) : completed → vert, cancelled → rouge, reste → jaune.
            $order_status   = $order_data['order_status'] ?? '';
            $display_status = match ( true ) {
                $order_status === 'completed'                                       => 'completed',
                in_array( $order_status, ['cancelled', 'refunded', 'failed'], true ) => 'cancelled',
                default                                                             => 'pending',
            };

            $hydrated[] = [
                'id'              => (int) $row->id,
                'product_id'      => $pid,
                'order_id'        => $oid,
                'order_number'    => $order_data['order_number'] ?? (string) $oid,
                'order_url'       => $order_data['order_url']    ?? '#',
                'status'          => $display_status,
                'date_local'      => $start_local->format('Y-m-d'),
                'time_local'      => $start_local->format('H:i') . '–' . $end_local->format('H:i'),
                'product_title'   => $products[$pid]['title'] ?? __('(produit supprimé)', 'lm-booking'),
                'product_image'   => $products[$pid]['image'] ?? '',
                'customer_name'   => $order_data['name']         ?? '',
                'customer_phone'  => $order_data['phone']        ?? '',
                'order_status'    => $order_data['order_status'] ?? '',
                'price_formatted' => self::format_price($booking_ttc),
                'addons'          => $order_data['addons_by_product'][$pid] ?? [],
            ];
        }

        return $hydrated;
    }
}
