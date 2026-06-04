# Parcours réservation — de la sélection du créneau à l'achat

Cette doc décrit le cheminement de la donnée d'une réservation, depuis l'affichage des
créneaux disponibles sur la fiche produit jusqu'à la création de l'enregistrement en base
après paiement.

Fichiers concernés :

- [`includes/class-lm-booking-rest-api.php`](../includes/class-lm-booking-rest-api.php) — endpoints REST (créneaux + ajout panier)
- [`includes/class-lm-booking-availability.php`](../includes/class-lm-booking-availability.php) — moteur de disponibilité
- [`includes/class-lm-booking-cart.php`](../includes/class-lm-booking-cart.php) — intégration panier
- [`includes/class-lm-booking-checkout.php`](../includes/class-lm-booking-checkout.php) — validation finale + création des réservations
- [`includes/class-lm-booking-repository.php`](../includes/class-lm-booking-repository.php) — accès à la table `wp_lm_bookings`

> **Rappel projet** : le checkout utilisé est exclusivement le **WooCommerce Checkout Block
> (Store API)**. Les hooks classiques sont conservés en parallèle mais ce sont les hooks
> Store API qui s'exécutent en production.

## Vue d'ensemble du flux

```
1. Affichage créneaux       GET  /lm-booking/v1/availability   → get_available_slots()
2. Ajout au panier          POST /lm-booking/v1/add-to-cart    → is_slot_available() + cart->add_to_cart()
3. Stockage panier          meta _lm_booking* sur le cart item (UTC)
4. Validation au paiement   hook Store API → is_slot_available_locked() (FOR UPDATE)
5. Persistance commande     hook → save_order_item_meta() (meta sur les lignes)
6. Création réservation     hook → create_bookings() → Repository::create() (status = pending)
```

La donnée du créneau circule **en UTC** sur tout le parcours (REST → panier → meta de ligne →
table). La conversion vers le fuseau local n'a lieu que pour l'affichage.

## 1. Affichage des créneaux disponibles

`GET /lm-booking/v1/availability?product_id=&date=` →
[`get_availability()`](../includes/class-lm-booking-rest-api.php#L95), endpoint **public**
(`permission_callback => __return_true`), réponse non cachée.

Le calcul est délégué à
[`Availability::get_available_slots()`](../includes/class-lm-booking-availability.php#L19), qui :

1. vérifie que la date est dans la fenêtre autorisée (`min_advance` heures / `max_advance` jours) ;
2. récupère les horaires du jour via `get_hours_for_date()` (overrides de date, sinon horaires
   hebdomadaires ; `null` = fermé) ;
3. génère les créneaux par pas de `durée + buffer` ;
4. pour chaque créneau, convertit en UTC et appelle
   [`Repository::count_overlapping()`](../includes/class-lm-booking-repository.php#L135) pour
   calculer `available = capacité − réservé` ;
5. applique les règles de prix (`compute_slot_price()`, ex. majoration week-end).

Chaque créneau renvoyé contient `start`/`end` (affichage local), `start_utc`/`end_utc`
(stockage), `available` et `price`.

## 2. Ajout au panier

`POST /lm-booking/v1/add-to-cart` →
[`add_to_cart()`](../includes/class-lm-booking-rest-api.php#L124).

Étapes :

1. vérifie que le produit existe et est de type `booking` ;
2. **revalide la disponibilité** via
   [`is_slot_available()`](../includes/class-lm-booking-availability.php#L117) → renvoie `409`
   si le créneau n'est plus libre ;
3. charge le panier si besoin (`wc_load_cart()`) ;
4. ajoute la réservation avec ses meta de créneau :

```php
$cart_item_data = [
    '_lm_booking'       => true,
    '_lm_booking_start' => $start_utc,
    '_lm_booking_end'   => $end_utc,
];
$cart_key = WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);
```

5. ajoute les **add-ons** comme articles liés. Sécurité : seuls les `product_id` présents dans
   `get_booking_addons()` du produit sont acceptés (`in_array($id, $allowed_addon_ids, true)`).
   Un éventuel `price_override` configuré est stocké dans la meta `_lm_booking_price_override`.

## 3. Comportement dans le panier

[`class-lm-booking-cart.php`](../includes/class-lm-booking-cart.php) gère l'affichage et les
règles métier du panier :

- **`force_unique_cart_item()`** — ajoute un hash unique pour que deux créneaux du même produit
  ne soient pas fusionnés en une seule ligne ;
- **`display_booking_data()`** — affiche date + créneau (reconvertis en local) ;
- **`remove_linked_addons()`** — supprimer une réservation supprime ses add-ons liés ;
- **`apply_addon_price_overrides()`** — applique le prix override sur les add-ons
  (`woocommerce_before_calculate_totals`) ;
- **`lock_booking_quantity()` / `hide_addon_remove()`** — quantité figée, bouton « supprimer »
  masqué sur les add-ons (gérés via le parent).

> ⚠️ **Point d'attention** : `validate_add_to_cart()`
> ([`class-lm-booking-cart.php:85`](../includes/class-lm-booking-cart.php#L85)) est un
> no-op — la validation réelle se fait dans l'endpoint REST (étape 2), pas dans le hook
> `woocommerce_add_to_cart_validation`. Le mount React de la fiche produit est encore un
> placeholder (`<div id="lm-booking-form">TODO</div>`).

## 4. Validation finale au paiement (anti-course)

Au moment du paiement, on **revalide** chaque réservation du panier, cette fois avec un
verrou base pour éviter les doubles réservations concurrentes (race condition).

Hook Store API : `woocommerce_store_api_checkout_update_order_meta` →
[`validate_at_checkout_block()`](../includes/class-lm-booking-checkout.php#L89) (l'équivalent
classique `validate_at_checkout()` existe aussi).

```php
$wpdb->query('START TRANSACTION');
$available = LM_Booking_Availability::is_slot_available_locked($product_id, $start_utc, $end_utc);
$wpdb->query('COMMIT');
```

[`is_slot_available_locked()`](../includes/class-lm-booking-availability.php#L132) utilise
[`count_overlapping_locked()`](../includes/class-lm-booking-repository.php#L157), qui ajoute
`FOR UPDATE` à la requête de comptage.

En cas d'indisponibilité, le checkout Block **lève une `\Exception`** (et non `wc_add_notice()`,
qui est réservé au checkout classique) pour bloquer le paiement.

## 5. Persistance sur la commande

Hook `woocommerce_checkout_create_order_line_item` →
[`save_order_item_meta()`](../includes/class-lm-booking-checkout.php#L139) (s'exécute pour les
deux types de checkout).

Recopie les meta du cart item vers la **ligne de commande** :

- `_lm_booking` = `yes`, `_lm_booking_start`, `_lm_booking_end` (UTC) ;
- date + créneau lisibles (local) pour l'affichage commande ;
- pour les add-ons : `_lm_booking_addon` = `yes` et `_lm_booking_parent_id`.

## 6. Création de l'enregistrement de réservation

Hook Store API : `woocommerce_store_api_checkout_order_processed` →
[`create_bookings()`](../includes/class-lm-booking-checkout.php#L173).

1. **garde anti-doublon** : si des réservations existent déjà pour cette commande
   (`get_by_order()`), on sort — protège contre un double déclenchement des hooks ;
2. pour chaque ligne marquée `_lm_booking`, insère une ligne via
   [`Repository::create()`](../includes/class-lm-booking-repository.php#L34) avec
   `status = 'pending'` ;
3. l'`id` de réservation créé est réécrit en meta de ligne (`_lm_booking_id`).

C'est à ce moment que la réservation existe enfin dans `wp_lm_bookings` et entre dans le calcul
de disponibilité des futurs créneaux.

## Le champ `status` de la table

Après création (`pending`), le statut suit le statut de la commande WC via
[`LM_Booking_Order::sync_booking_status()`](../includes/class-lm-booking-order.php#L30)
(hook `woocommerce_order_status_changed`) :

| Statut commande WC                | Statut réservation |
| --------------------------------- | ------------------ |
| `processing`, `completed`         | `confirmed`        |
| `cancelled`, `refunded`, `failed` | `cancelled`        |
| `on-hold`, `pending`              | `pending`          |

De plus, une commande `completed` dont le créneau est passé bascule la réservation en
`completed`.

> **Pourquoi conserver ce champ alors que WC a déjà un statut ?** Le moteur de disponibilité
> (`count_overlapping`) ne requête **que** `wp_lm_bookings`, en filtrant
> `status IN ('pending','confirmed')`. C'est une projection dénormalisée du statut WC, traduite
> dans un vocabulaire métier réduit, qui permet une requête de chevauchement indexée sur une
> seule table — sans jointure vers les commandes (ni dépendance à HPOS).

## Récapitulatif des meta

| Meta                          | Porteur        | Rôle                                            |
| ----------------------------- | -------------- | ----------------------------------------------- |
| `_lm_booking`                 | cart + ligne   | marque une réservation                          |
| `_lm_booking_start` / `_end`  | cart + ligne   | créneau en UTC                                  |
| `_lm_booking_id`              | ligne          | id de l'enregistrement `wp_lm_bookings`         |
| `_lm_booking_addon`           | cart + ligne   | marque un add-on                                |
| `_lm_booking_parent_id`       | cart + ligne   | produit de réservation parent de l'add-on       |
| `_lm_booking_parent_key`      | cart           | clé du cart item parent (lien add-on → réservation) |
| `_lm_booking_price_override`  | cart           | prix forcé de l'add-on                          |
