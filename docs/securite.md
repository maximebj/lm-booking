# Sécurité — état des lieux

Audit du parcours de réservation et du calendrier admin. Document de suivi : il
recense les failles identifiées, leur statut, et ce qui a été mis en place.

**Dernière revue :** 2026-06-04
**Périmètre :** endpoints REST, panier, checkout, moteur de disponibilité, calendrier admin.

Légende statut : ✅ corrigé · 🔄 partiel · ⛔ à traiter · ℹ️ accepté / défense en profondeur.

## Récapitulatif

| #  | Finding | Sévérité | Statut |
| -- | ------- | -------- | ------ |
| A  | Créneau soumis non validé contre les règles métier | 🔴 Haut | ✅ |
| B  | Prix du créneau affiché mais jamais facturé | 🔴 Haut | ✅ |
| C  | Verrou `FOR UPDATE` inefficace → survente possible | 🟠 Moyen | ✅ |
| D  | Quantité d'add-on non bornée par `max_qty` | 🟠 Moyen | ✅ |
| E  | Écriture de meta/termes sur des post IDs arbitraires | 🟠 Moyen | ⛔ |
| F  | `/add-to-cart` public sans nonce (CSRF) | 🟡 Bas | ℹ️ |
| G  | `wp_json_encode` dans `<script>` inline (calendrier) | 🟡 Bas | ℹ️ |
| H  | `validate_date` accepte des dates impossibles | 🟡 Bas | ⛔ |
| I  | Pas de rate-limiting sur les endpoints publics | 🟡 Bas | ⛔ |
| J  | `max_qty` contournable par appels répétés (cumul panier) | 🟡 Bas | ⛔ |

---

## A — Créneau soumis non validé ✅

**Problème.** Le moteur `get_available_slots()` (horaires, durée, buffer, grille,
fenêtre d'avance) ne servait qu'à *afficher* les créneaux. À l'ajout panier et au
checkout, la seule vérification serveur était la capacité. Un client pouvait donc
soumettre des `start_utc` / `end_utc` arbitraires : hors horaires, durée libre,
`end < start`, hors fenêtre d'avance, hors grille.

**Correctif.** Nouvelle méthode
[`Availability::get_slot()`](../includes/class-lm-booking-availability.php#L144) qui
reconstruit les créneaux légitimes du jour et renvoie celui qui correspond
**exactement** au couple soumis (ou `null`). Branchée sur l'unique point d'entrée,
l'endpoint [`add_to_cart()`](../includes/class-lm-booking-rest-api.php#L139) : un
créneau non reconnu renvoie `422`.

**Périmètre.** Le gating est fait au seul endroit où une réservation entre dans le
panier (l'endpoint REST ; le panier est en session serveur, non altérable). La
re-validation des *règles* au checkout a été volontairement écartée : elle dépend de
`now` (fenêtre d'avance) et rejetterait à tort des paniers légitimes au fil du temps.
Le checkout continue de re-vérifier la **capacité** (voir C).

---

## B — Prix du créneau jamais facturé ✅

**Problème.** `compute_slot_price()` (majoration week-end, etc.) était calculé pour
l'affichage dans `/availability` mais la réservation était ajoutée au panier au
**prix de base** du produit. Le client voyait un prix et en payait un autre.

**Correctif.** Le prix vient du créneau renvoyé par `get_slot()` (donc **calculé
serveur**, jamais le client) et est stocké dans la donnée de panier
`_lm_booking_price`
([rest-api](../includes/class-lm-booking-rest-api.php#L165)). Il est appliqué au
calcul des totaux par
[`Cart::apply_price_overrides()`](../includes/class-lm-booking-cart.php#L131)
(hook `woocommerce_before_calculate_totals`, même mécanisme que les add-ons). Le prix
remonte ensuite naturellement dans le total de ligne → commande → calendrier.

---

## C — Verrou `FOR UPDATE` inefficace ✅

**Problème.** L'intention anti-race-condition était là, mais la lecture verrouillée et
l'insertion étaient dans **deux transactions/hooks distincts** : `START TRANSACTION ;
SELECT … FOR UPDATE ; COMMIT` au checkout (verrou relâché immédiatement), puis
`INSERT` plus tard dans `create_bookings()`. Deux checkouts concurrents sur le dernier
créneau pouvaient tous deux passer → survente.

**Correctif.** La frontière de transaction a été déplacée pour englober **le comptage
verrouillé ET l'insertion** dans une seule transaction, dans
[`create_bookings()`](../includes/class-lm-booking-checkout.php#L173) — là où la
réservation se matérialise réellement. Les checkouts concurrents se sérialisent donc
sur le créneau. La validation pré-paiement
([`validate_at_checkout_block`](../includes/class-lm-booking-checkout.php#L82)) est
ramenée à un contrôle **best-effort** non verrouillé (message d'erreur convivial).

**Cas résiduel géré.** Si la rare course se produit (deux paniers passent le contrôle
pré-paiement en simultané), le second échoue au comptage verrouillé : la réservation
n'est **pas** créée, et
[`flag_oversold_order()`](../includes/class-lm-booking-checkout.php#L213) place la
commande **en attente** + ajoute une **note de commande** pour traitement manuel
(remboursement éventuel). À surveiller : `update_status('on-hold')` est appelé pendant
`order_processed` ; comportement à confirmer en condition réelle.

---

## D — Quantité d'add-on non bornée ✅

**Problème.** À l'ajout panier, la quantité d'add-on n'était bornée que par le bas
(`max(1, …)`). Le `max_qty` configuré n'était appliqué que dans l'UI React → un client
pouvait commander `9999` d'un add-on plafonné à 2.

**Correctif.** La boucle d'add-ons de
[`add_to_cart()`](../includes/class-lm-booking-rest-api.php#L171) indexe les add-ons
configurés par `product_id` et **clampe** la quantité au `max_qty` :
`min($max_qty, max(1, $qty))`. La liste blanche des IDs autorisés (correctif de sécurité
antérieur) est préservée. Choix retenu : clamp silencieux (gracieux) plutôt que rejet.

---

## E — Écriture de meta/termes sur des post IDs arbitraires ⛔

**Problème.** Dans
[`save_booking_meta()`](../admin/class-lm-booking-meta-boxes.php#L123), les
`product_id` d'add-ons proviennent du JSON client et reçoivent
`update_post_meta($id, '_lm_addon_only', 'yes')` + des termes
`product_visibility` sans vérifier que l'ID est bien un produit. Un utilisateur
`edit_products` peut marquer **n'importe quel post** comme add-on-only et l'exclure du
catalogue.

**Impact.** Limité : acteur authentifié et capacité-gated. C'est un défaut
d'autorisation/robustesse, pas une escalade de privilège.

**Correctif proposé (non implémenté).** Valider `wc_get_product($id)` (et idéalement la
propriété/capacité sur ce produit) avant toute écriture.

---

## Findings mineurs

- **F — `/add-to-cart` public sans nonce (ℹ️).** `permission_callback => '__return_true'`.
  Un nonce `wp_rest` est généré et passé au JS mais **jamais vérifié** côté serveur →
  endpoint CSRF-able. Impact réel faible (manipulation du panier de la victime). Piste :
  un `permission_callback` qui vérifie le nonce REST.
- **G — `wp_json_encode` dans `<script>` inline (ℹ️).** Calendrier admin, avec données
  client (nom de facturation, téléphone). **Probablement non exploitable** : `json_encode`
  échappe les `/` par défaut, ce qui neutralise un breakout `</script>`. Durcissement
  recommandé : flags `JSON_HEX_TAG | JSON_HEX_AMP` ou `wp_add_inline_script`.
- **H — `validate_date` (⛔).** La regex `^\d{4}-\d{2}-\d{2}$` accepte `2026-13-45` ;
  `createFromFormat` « roule » sans erreur. Cosmétique (le matching de créneau du point A
  rejette de toute façon les dates impossibles).
- **I — Pas de rate-limiting (⛔).** `/availability` est public et fait des requêtes DB
  par appel. Surface DoS mineure.
- **J — `max_qty` cumulable (⛔).** Le clamp du point D est *par ajout*. Rien n'empêche
  d'appeler l'endpoint plusieurs fois pour empiler le même add-on (les add-ons ne sont pas
  fusionnés dans le panier). Si `max_qty` doit être un plafond **total par réservation**,
  vérifier la quantité déjà présente pour le parent avant d'ajouter.

---

## Ce qui est bien fait

- **Aucune injection SQL** : tout passe par `$wpdb->prepare` avec `%i` (noms de table,
  WP 6.2+), `%d`, `%s` ; les listes `status IN (…)` sont des littéraux codés en dur.
- **Validation des IDs d'add-on** côté serveur, prix override lu depuis le produit et non
  du client.
- **Échappement de sortie** systématique : `esc_html`/`esc_attr`/`esc_url` (PHP) et `esc()`
  (JS) sur les champs rendus.
- **Capacités** correctes : `manage_woocommerce` (calendrier), `edit_products`
  (recherche produit).
- **`defined('ABSPATH') || exit`** en tête de chaque fichier.
- **Garde anti-doublon** sur la création des réservations (idempotence des hooks).

---

## Reste à traiter

Par priorité : **E** (validation des IDs produit à la sauvegarde), puis les durcissements
**F**/**G**, puis **J** si le `max_qty` doit être cumulatif, enfin **H**/**I**.
