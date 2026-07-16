# Plan — Modes de réservation (fixe / flexible)

> Document de conception. Statut : **validé sur le principe**, phases 1 à 4 à implémenter.

## Contexte et objectif

Aujourd'hui le plugin ne gère qu'une typologie : le **créneau fixe** (durée définie par le
marchand, le client choisit quand). C'est adapté au cas "coiffeur" mais pas à la location
de vélos (journée / demi-journée / multi-jours) ni à la location de salles (à l'heure,
plusieurs heures, demi-journée).

Objectif : permettre de choisir, **par produit**, le type de créneau. Une page de réglages
définit le type par défaut pour les nouveaux produits et les paramètres transverses
(définition des demi-journées).

## Décisions actées

| Décision | Choix |
| -------- | ----- |
| Portée du mode | **Par produit** (pas global). Un même site peut mixer les typologies (ex : loueur de vélos qui vend aussi des sessions de formation à créneau fixe). |
| Page de réglages | Définit le **mode par défaut** des nouveaux produits + la **définition des demi-journées** (ex : matin 9h–13h, après-midi 14h–18h). |
| Jours fermés en multi-jours | **Traversables et facturés.** Louer du samedi au mardi avec dimanche fermé est valide : le dimanche est compté/facturé (le matériel est immobilisé), on ne peut simplement pas retirer ni rapporter ce jour-là. Les jours de **début et de fin** doivent être ouverts. |
| Demi-journée | Disponible **dès la première phase de livraison** du mode flexible (pas une amélioration ultérieure). |
| Raccourcis en mode horaire | Un produit réservable à l'heure peut proposer en front des raccourcis "Matinée" / "Après-midi" / "Journée" qui pré-sélectionnent le **bloc horaire correspondant** (heure de début + durée, dérivées de la définition globale des demi-journées) — pas seulement une durée. |
| Unité au choix en mode flexible | L'unité (`hour` / `half_day` / `day`) est un vrai choix par produit : un parking se loue à la journée uniquement (`day`), un vélo à la demi-journée (`half_day`), une salle à l'heure (`hour`). |
| Rétrocompatibilité | Les produits existants sans meta de mode sont traités comme `fixed`. Aucune migration de données. |

## Modèle

### Modes et unités

```
_lm_booking_mode : 'fixed' (défaut) | 'flexible'

Si flexible :
_lm_booking_unit      : 'hour' | 'half_day' | 'day'
_lm_booking_min_units : nombre minimum d'unités consécutives (défaut 1)
_lm_booking_max_units : nombre maximum d'unités consécutives
```

| Mode / unité | Le client choisit | Cas d'usage |
| --- | --- | --- |
| `fixed` | date + créneau (existant, inchangé) | coiffeur, RDV, cours |
| `flexible` / `hour` | date + heure de début + durée (n heures) | salle de réunion |
| `flexible` / `half_day` | plage de demi-journées consécutives | vélo (demi-j., journée, multi-jours) |
| `flexible` / `day` | plage de jours consécutifs | location où la demi-journée n'a pas de sens |

La demi-journée est l'unité la plus expressive du mode "journée" : une plage
`samedi après-midi → lundi matin` est simplement une suite de 4 demi-journées
consécutives. L'unité `day` en est la variante simplifiée (1 cellule par jour).

### Ce qui ne change pas

- **Table `wp_lm_bookings` : aucun changement de schéma.** Elle stocke déjà des intervalles
  génériques (`start_datetime` / `end_datetime`) en UTC, et la capacité est vérifiée par
  comptage de chevauchements (`count_overlapping[_locked]`). Une réservation de 3 jours ou
  de 2 heures est juste un intervalle plus long.
- Le parcours panier → checkout → commande → booking : les cart items transportent déjà
  `start_utc` / `end_utc` et un **prix calculé côté serveur**. Seule la brique de
  validation devient dépendante du mode.
- Le pattern de sécurité : le serveur reste la seule source de vérité pour la validité
  d'une demande et son prix (cf. [securite.md](securite.md)). Le client n'envoie jamais
  de prix.

### Nouveaux metas produit

| Meta | Type | Rôle |
| --- | --- | --- |
| `_lm_booking_mode` | string | `fixed` \| `flexible` (absent = `fixed`) |
| `_lm_booking_unit` | string | `hour` \| `half_day` \| `day` |
| `_lm_booking_min_units` | int | durée minimum en unités |
| `_lm_booking_max_units` | int | durée maximum en unités |
| `_lm_booking_price_half_day` | decimal | mode jour/demi-j. : prix d'une demi-journée (le prix produit = prix journée) |
| `_lm_booking_shortcuts` | json | mode horaire : raccourcis activés (`half_day`, `full_day`) + éventuel prix spécifique |

### Options globales (page de réglages)

| Option | Rôle |
| --- | --- |
| `lm_booking_default_mode` / `lm_booking_default_unit` | pré-remplissage des nouveaux produits |
| `lm_booking_half_day_hours` | définition des demi-journées : matin (début/fin), après-midi (début/fin) |

### Tarification

- **Mode horaire** : prix produit = prix de l'heure. Total = n × prix horaire.
  Les raccourcis "Demi-journée" / "Journée" utilisent par défaut ce calcul linéaire,
  avec possibilité d'un **prix spécifique** par raccourci (le forfait journée est
  souvent < n × prix horaire).
- **Mode jour / demi-journée** : prix produit = prix de la **journée** ;
  `_lm_booking_price_half_day` = prix de la **demi-journée** (optionnel : s'il est vide,
  demi-journée = moitié du prix journée). Une plage est décomposée en
  `n journées complètes + k demi-journées` et facturée
  `n × prix_journée + k × prix_demi_journée`. Les jours fermés traversés comptent comme
  des journées complètes facturées.
- Les **price rules** existantes (ex : majoration weekend) continuent de s'appliquer
  unité par unité.

## Architecture cible

### Backend

`LM_Booking_Availability` devient un **dispatcher par mode**. La logique actuelle est
extraite telle quelle dans la stratégie `fixed` ; deux stratégies s'ajoutent.

```
includes/
├── class-lm-booking-availability.php        # façade : dispatch selon le mode du produit
└── availability/
    ├── class-lm-booking-mode-fixed.php      # logique actuelle, déplacée sans modification
    ├── class-lm-booking-mode-hourly.php     # flexible / hour
    └── class-lm-booking-mode-daily.php      # flexible / half_day + day
```

Le point d'entrée de validation `get_slot( $product, $start_utc, $end_utc )` (qui résout
une demande client vers un créneau autoritaire avec prix serveur) est conservé comme
signature commune : chaque stratégie sait dire si l'intervalle demandé est légal pour le
produit et à quel prix. `add_to_cart` (REST) et la re-validation panier/checkout ne
changent donc presque pas.

Règles de validation par stratégie :

- **`hourly`** : l'heure de début tombe sur la grille (pas = 1 unité), la durée est entre
  min et max unités, la plage entière est dans les horaires d'ouverture du jour, chaque
  unité de la plage a de la capacité restante, fenêtre min/max advance respectée.
- **`daily`** : le premier et le dernier jour sont **ouverts** ; les jours fermés
  intermédiaires sont acceptés (et facturés) ; la longueur est entre min et max unités ;
  la capacité est vérifiée sur l'intervalle complet (le chevauchement SQL couvre
  naturellement les jours fermés puisque le matériel est immobilisé) ; les horaires de
  retrait/retour sont dérivés des horaires d'ouverture du jour de début/fin (ou des
  bornes de demi-journée si l'unité est `half_day`).

### API REST

| Endpoint | Évolution |
| --- | --- |
| `GET /availability?product_id&date` | La réponse gagne un champ `mode`. `fixed` : inchangé (liste de slots). `hour` : liste des heures de début possibles avec, pour chacune, le nombre max d'unités consécutives disponibles. |
| `GET /availability/calendar?product_id&month=YYYY-MM` | **Nouveau.** Pour le mode jour/demi-journée : état de chaque jour du mois (`free` \| `partial` \| `full` \| `closed`), décliné par demi-journée si l'unité est `half_day`. Alimente le calendrier à sélection de plage. |
| `POST /add-to-cart` | Signature inchangée (`start_utc` / `end_utc`) : une plage de 3 jours est un intervalle comme un autre. La validation passe par la stratégie du produit. |

### Frontend (React)

`BookingForm` conserve ses étapes communes (calendrier → sélection → add-ons → résumé →
panier) et branche l'étape de sélection selon le mode :

| Mode | Étape 2 | Composants |
| --- | --- | --- |
| `fixed` | grille de créneaux (existant) | `TimeSlotGrid` (inchangé) |
| `hour` | heure de début + sélecteur de durée + **raccourcis** "Matinée" / "Après-midi" / "Journée" (chips qui pré-sélectionnent début + durée du bloc) | `StartTimePicker`, `DurationSelector` (nouveaux) |
| `half_day` / `day` | calendrier à **sélection de plage** (jours colorés par dispo, jours fermés grisés mais traversables) + choix matin/après-midi aux bornes si `half_day` | `RangeCalendar` (nouveau, remplace `DatePicker` pour ce mode) |

Le résumé (`BookingSummary`) affiche la décomposition tarifaire (n journées + k
demi-journées, ou n heures / forfait raccourci).

### Admin

- **Onglet Réservation du produit** : un sélecteur "Type de réservation" en tête
  (`Créneau fixe` / `Flexible à l'heure` / `Flexible demi-journée` / `Flexible journée`)
  conditionne l'affichage des champs (mécanisme show/hide type WooCommerce
  `show_if_*`). En mode jour : "Durée (minutes)" et "Temps tampon" laissent place à
  "Durée min/max" en unités et au prix demi-journée ; les horaires d'ouverture
  hebdomadaires deviennent des **jours** d'ouverture (les heures servent aux
  retraits/retours).
- **Page de réglages** : sous-menu du menu admin existant du plugin (Settings API) —
  mode/unité par défaut, définition des demi-journées.
- **Calendrier admin** : afficher les réservations multi-jours comme des barres
  continues s'étendant sur plusieurs cases (cf.
  [calendrier-recuperation-donnees.md](calendrier-recuperation-donnees.md)).

## Phases de livraison

Chaque phase est livrable indépendamment ; la phase 1 ne change aucun comportement.

### Phase 1 — Socle (aucun changement de comportement)

1. Metas `_lm_booking_mode` / `_lm_booking_unit` + accesseurs sur `WC_Product_Booking`
   (absent = `fixed` → rétrocompatibilité totale).
2. Refactor de `LM_Booking_Availability` en dispatcher + stratégie `fixed` (déplacement
   de la logique actuelle, à l'identique).
3. Sélecteur de mode dans l'onglet Réservation + show/hide des champs existants.
4. Page de réglages (mode par défaut, définition des demi-journées).
5. Vérification de non-régression sur le parcours créneau fixe complet.

### Phase 2 — Mode flexible jour / demi-journée (cas vélo)

1. Stratégie `daily` : validation de plage (bornes ouvertes, jours fermés traversés et
   facturés, min/max unités, capacité par chevauchement), calcul du prix décomposé.
2. Endpoint `GET /availability/calendar` (état mensuel par jour / demi-journée).
3. Champs admin du mode jour (durées min/max, prix demi-journée, jours d'ouverture).
4. Front : `RangeCalendar` avec sélection de plage + bornes matin/après-midi,
   résumé tarifaire décomposé.
5. Calendrier admin : rendu des réservations multi-jours.

### Phase 3 — Mode flexible horaire (cas salle de réunion)

1. Stratégie `hourly` : grille de départs + durées consécutives disponibles, validation
   et prix serveur.
2. Extension de `GET /availability` pour le format horaire.
3. Champs admin du mode horaire (min/max unités, raccourcis + prix spécifiques).
4. Front : `StartTimePicker` + `DurationSelector` + chips raccourcis
   (durées dérivées de la définition globale des demi-journées).

### Phase 4 — Finitions

- Prix spécifiques des raccourcis en admin (si non couvert en phase 3).
- Tarification dégressive multi-jours (ex : −10 % à partir de 3 jours) — extension des
  price rules.
- États intermédiaires du calendrier front (jour partiellement réservé) affinés.
- Documentation utilisateur (README) par typologie.

## Points de vigilance

- **Fuseaux horaires** : les bornes de journée/demi-journée sont définies en heure
  locale du site et converties en UTC au moment de la génération, comme aujourd'hui
  (cf. concept "Stockage en UTC" du [README docs](README.md)).
- **`is_sold_individually()`** reste vrai : une réservation = un cart item, quelle que
  soit sa longueur.
- **Verrouillage checkout** : `count_overlapping_locked` fonctionne tel quel sur des
  intervalles longs ; vérifier seulement que l'index `product_availability` reste
  pertinent (il l'est : requête par produit + bornes).
- **Exceptions de dates** (`date_overrides`) : en mode jour, une date "fermée" par
  exception suit la même règle qu'un jour de fermeture hebdomadaire (traversable,
  facturé, pas de retrait/retour).
