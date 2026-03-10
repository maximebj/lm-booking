# LM Booking

Système de réservation intégré à WooCommerce. Permet de transformer n'importe quel produit WooCommerce en ressource réservable avec créneaux temporels, gestion de capacité et add-ons (suppléments liés à la réservation).

Cas d'usage : location de vélos, réservation de salles, rendez-vous avec un prestataire, etc.

## Prérequis

- WordPress >= 6.0
- PHP >= 8.0
- WooCommerce >= 8.0
- Node.js >= 18

## Installation

```bash
# Cloner le dépôt dans le dossier plugins de WordPress
cd wp-content/plugins/
git clone <url-du-repo> lm-booking
cd lm-booking

# Installer les dépendances JavaScript
npm install

# Compiler les assets React (admin + frontend)
npm run build
```

Le plugin est prêt. Rendez-vous dans **Extensions > Extensions installées** dans l'admin WordPress pour l'activer. La table `wp_lm_bookings` sera créée automatiquement à l'activation.

### Développement

Pour lancer le watcher en mode développement (recompilation automatique à chaque modification des fichiers `src/`) :

```bash
npm run start
```

## Architecture rapide

```
lm-booking/
├── lm-booking.php              # Point d'entrée du plugin
├── includes/                   # Classes PHP
│   ├── class-wc-product-booking.php       # Type de produit WooCommerce "booking"
│   ├── class-lm-booking-availability.php  # Moteur de disponibilité
│   ├── class-lm-booking-repository.php    # CRUD table wp_lm_bookings
│   ├── class-lm-booking-cart.php          # Intégration panier
│   ├── class-lm-booking-checkout.php      # Validation + création des bookings
│   ├── class-lm-booking-order.php         # Sync statuts commande ↔ booking
│   ├── class-lm-booking-addons.php        # Restriction d'achat des add-ons
│   └── class-lm-booking-rest-api.php      # Endpoints REST
├── admin/                      # Interface admin (onglet produit)
├── src/                        # Sources React
│   ├── admin/                  # Composants React admin
│   └── frontend/               # Composants React frontend (page produit)
├── build/                      # Assets compilés (généré par npm run build)
└── assets/css/                 # Feuilles de style
```

Le plugin ne crée qu'**une seule table custom** (`wp_lm_bookings`). Tout le reste s'appuie sur le natif WooCommerce : type de produit, meta produit, panier, checkout, commandes.

## Créer un produit réservable

### Étape 1 — Créer le produit

1. Dans l'admin WordPress, allez dans **Produits > Ajouter**.
2. Donnez un nom au produit (ex : *Location vélo 1h*).
3. Dans le sélecteur **Données produit**, choisissez le type **Réservable**.

### Étape 2 — Configurer le prix

1. Dans l'onglet **Général**, renseignez le **Prix** de base du créneau (ex : `15.00`).
   Ce prix s'affichera pour chaque créneau disponible.

### Étape 3 — Configurer le créneau

Cliquez sur l'onglet **Réservation** (icône calendrier). Renseignez les champs suivants :

| Champ | Description | Exemple |
|---|---|---|
| **Durée (minutes)** | Durée d'un créneau de réservation | `60` |
| **Capacité** | Combien de réservations simultanées sur un même créneau (= nombre de ressources disponibles) | `5` (5 vélos) |
| **Temps tampon (minutes)** | Pause entre deux créneaux (nettoyage, préparation…) | `15` |
| **Délai minimum (heures)** | Le client doit réserver au moins X heures à l'avance | `2` |
| **Délai maximum (jours)** | Le client peut réserver jusqu'à X jours à l'avance | `30` |

### Étape 4 — Définir les horaires d'ouverture

Toujours dans l'onglet **Réservation**, section **Horaires d'ouverture** :

1. Cochez les jours où les réservations sont possibles.
2. Pour chaque jour activé, définissez l'heure de début et de fin.

Exemple pour un loueur de vélos ouvert du mardi au samedi, 9h–18h :

| Jour | Ouvert | Début | Fin |
|---|---|---|---|
| Lundi | ☐ | — | — |
| Mardi | ☑ | 09:00 | 18:00 |
| Mercredi | ☑ | 09:00 | 18:00 |
| Jeudi | ☑ | 09:00 | 18:00 |
| Vendredi | ☑ | 09:00 | 18:00 |
| Samedi | ☑ | 10:00 | 17:00 |
| Dimanche | ☐ | — | — |

### Étape 5 — Ajouter des exceptions de dates (optionnel)

Pour fermer un jour férié ou modifier les horaires d'une date précise :

1. Cliquez sur **+ Ajouter une exception**.
2. Sélectionnez la date.
3. Choisissez le type :
   - **Fermé** — aucun créneau ce jour-là.
   - **Horaires spéciaux** — renseignez des horaires différents (ex : 10h–14h pour un 24 décembre).

### Étape 6 — Ajouter des add-ons (optionnel)

Les add-ons sont des **produits WooCommerce simples** (casque, panier, antivol…) proposés en supplément lors de la réservation. Leur stock est géré nativement par WooCommerce.

> **Pré-requis :** les produits add-ons doivent d'abord être créés comme des produits simples classiques (avec prix et stock activé).

1. Dans la section **Add-ons**, tapez le nom d'un produit dans le champ de recherche.
2. Cliquez sur le produit pour l'ajouter.
3. Pour chaque add-on, configurez :
   - **Type** : *Optionnel* (supplément payant) ou *Inclus* (offert avec la réservation).
   - **Qté max** : quantité maximum par réservation (ex : 2 casques max).
   - **Prix custom** : laissez vide pour utiliser le prix du produit, ou entrez un montant (ex : `0` pour un add-on offert).

> Les produits marqués comme add-ons seront automatiquement masqués de la boutique et non achetables individuellement. Si vous retirez un add-on de tous les produits réservables, il redeviendra visible et achetable normalement.

### Étape 7 — Publier

Cliquez sur **Publier**. Le produit est maintenant réservable.

## Parcours client

Sur la page produit, le client voit :

1. **Un calendrier** pour choisir une date.
2. **Une grille de créneaux** horaires avec la disponibilité restante et le prix de chaque créneau.
3. **Les add-ons disponibles** (s'il y en a) — les inclus sont pré-sélectionnés, les optionnels sont ajoutables avec +/−.
4. **Un résumé** avec le détail et le total avant de cliquer sur **Réserver**.

La réservation est ajoutée au panier WooCommerce avec le créneau choisi et les add-ons liés. Le checkout standard WooCommerce s'applique ensuite normalement.

## Gestion des réservations

Les réservations sont liées aux commandes WooCommerce. Le statut de la réservation suit celui de la commande :

| Statut commande | Statut réservation |
|---|---|
| En attente / En attente de paiement | `pending` |
| En cours / Terminée | `confirmed` |
| Annulée / Remboursée / Échouée | `cancelled` |

La disponibilité des créneaux est vérifiée à trois niveaux :
1. Au chargement du calendrier (API REST).
2. À l'ajout au panier (validation serveur).
3. Au moment du paiement (verrouillage SQL pour éviter les double-bookings).

## Licence

Propriétaire — La Marketerie.
