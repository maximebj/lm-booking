# Calendrier admin — récupération et affichage des données

Cette doc décrit comment la vue **Réservations** (menu admin `lm-reservations`) récupère
les réservations d'un mois donné et les transforme pour les afficher dans la grille
mensuelle et le panneau de détail journalier.

Fichiers concernés :

- [`admin/class-lm-booking-calendar.php`](../admin/class-lm-booking-calendar.php) — page admin, rendu, hydratation
- [`includes/class-lm-booking-repository.php`](../includes/class-lm-booking-repository.php) — accès à la table `wp_lm_bookings`

## Vue d'ensemble du flux

```
Table wp_lm_bookings  →  Repository::get_by_month()  →  hydrate_bookings()  →  $by_date[]  →  Grille PHP + JSON (panneau JS)
   (stockage UTC)          (requête SQL bornée)          (jointure WC)          (groupé par jour local)
```

## 1. Résolution de la fenêtre du mois (UTC)

Les dates sont **stockées en UTC** dans `wp_lm_bookings` (`start_datetime` / `end_datetime`)
mais affichées dans le fuseau du site.

`render_page()` construit la fenêtre du mois dans le fuseau **local**, puis la convertit en
UTC avant d'interroger la base ([`class-lm-booking-calendar.php:76`](../admin/class-lm-booking-calendar.php#L76)) :

```php
$tz       = wp_timezone();
$utc_tz   = new DateTimeZone('UTC');
$start_dt = new DateTimeImmutable("{$year}-{$month}-01 00:00:00", $tz); // 1er du mois, local
$end_dt   = $start_dt->modify('+1 month');                              // borne exclusive

$rows = LM_Booking_Repository::get_by_month(
    $start_dt->setTimezone($utc_tz)->format('Y-m-d H:i:s'),
    $end_dt->setTimezone($utc_tz)->format('Y-m-d H:i:s')
);
```

> **Pourquoi convertir avant de requêter ?** Si l'on requêtait directement sur le mois
> calendaire UTC, on raterait ou inclurait à tort les réservations proches de minuit, à
> cause du décalage horaire (Europe/Paris = UTC+1 ou +2).

## 2. La requête SQL

[`Repository::get_by_month()`](../includes/class-lm-booking-repository.php#L230) est une simple
borne demi-ouverte filtrée par statut :

```sql
SELECT * FROM wp_lm_bookings
WHERE start_datetime >= %s        -- borne basse incluse
  AND start_datetime <  %s        -- borne haute exclue
  AND status IN ('pending', 'confirmed', 'completed')  -- on exclut 'cancelled'
ORDER BY start_datetime ASC
```

Elle ne renvoie que les **lignes brutes** de notre table, sans aucune donnée WooCommerce.

## 3. Hydratation — enrichissement WooCommerce

[`hydrate_bookings()`](../admin/class-lm-booking-calendar.php#L540) complète les lignes brutes.
Pour éviter le problème N+1, les appels sont **regroupés par identifiant unique** :

1. collecte des `product_id` et `order_id` distincts ;
2. un `wc_get_product()` par produit → titre + vignette ;
3. un `wc_get_order()` par commande → client, téléphone, n° de commande, statut WC,
   totaux TTC et add-ons (via les meta de ligne `_lm_booking` / `_lm_booking_addon`) ;
4. reconversion de chaque `start_datetime` UTC → heure locale pour obtenir
   `date_local` (`Y-m-d`) et `time_local` (`H:i–H:i`).

L'objet retourné est un **view model** (tableau associatif déjà formaté pour l'affichage),
et non un DTO brut : `price_formatted` est une chaîne (« 49,00 € »), `time_local` une plage
formatée, et `status` une valeur de couleur calculée.

### Statut affiché ≠ statut en base

La couleur de chaque carte/chip provient du **statut de la commande WooCommerce**, pas du
champ `status` de notre table :

| Statut commande WC                     | Statut affiché | Couleur |
| -------------------------------------- | -------------- | ------- |
| `completed`                            | `completed`    | vert    |
| `cancelled`, `refunded`, `failed`      | `cancelled`    | rouge   |
| tout le reste (`pending`, `on-hold`, `processing`) | `pending` | jaune |

La même logique est dupliquée côté PHP (`$display_status`) et côté JS (`orderStatusToBooking()`).

## 4. Regroupement par jour

Une fois hydraté, le regroupement par jour est trivial
([`class-lm-booking-calendar.php:88`](../admin/class-lm-booking-calendar.php#L88)) :

```php
$by_date = [];
foreach ($bookings as $b) {
    $by_date[$b['date_local']][] = $b; // clé = 'YYYY-MM-DD' en heure locale
}
```

`$by_date` est utilisé **deux fois** :

- **côté PHP** — remplit chaque cellule de la grille mensuelle avec des « chips »
  ([`render_chip()`](../admin/class-lm-booking-calendar.php#L499)) ;
- **côté JS** — sérialisé en JSON via `wp_json_encode($by_date)`
  ([`class-lm-booking-calendar.php:216`](../admin/class-lm-booking-calendar.php#L216)) pour
  alimenter le panneau de détail. La navigation jour précédent/suivant **dans le même mois**
  se fait sans rechargement (tout le mois est déjà en mémoire) ; seul le franchissement de la
  limite du mois recharge la page.

## 5. Modification du statut depuis le calendrier

Le `<select>` de statut sur chaque carte envoie un `PUT /wc/v3/orders/{id}` (API REST
WooCommerce) côté JS ([`class-lm-booking-calendar.php:414`](../admin/class-lm-booking-calendar.php#L414)).

Ce changement déclenche le hook `woocommerce_order_status_changed`, écouté par
[`LM_Booking_Order::sync_booking_status()`](../includes/class-lm-booking-order.php#L30), qui
resynchronise le champ `status` de `wp_lm_bookings`. La table reste donc cohérente avec WC.

## Limites connues / pistes d'évolution

1. **Tout le mois est chargé d'un bloc** — pas de pagination ; le HTML et le JSON inline
   grossissent avec le volume. Une approche AJAX (détail d'un jour à la demande) serait plus
   scalable.
2. **Index** — la requête du calendrier filtre uniquement sur `start_datetime`, mais l'index
   composite existant `product_availability` a `product_id` en tête et n'est donc pas
   exploitable ici. Un index `(start_datetime, status)` aiderait à terme (gain marginal tant
   que le volume reste faible). Nécessite une routine de migration de schéma.
3. **Doublon de dérivation du statut** — la correspondance « statut WC → affichage » existe en
   deux endroits (PHP + JS) à maintenir en parallèle.
