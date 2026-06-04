# Documentation — LM Booking

Documentation technique du plugin de réservation. Destinée à l'équipe de développement.

## Sommaire

| Document | Contenu |
| -------- | ------- |
| [parcours-reservation.md](parcours-reservation.md) | Cheminement de la donnée d'une réservation, de la sélection du créneau sur la fiche produit à la création de l'enregistrement en base après paiement (REST → panier → checkout → `wp_lm_bookings`). |
| [calendrier-recuperation-donnees.md](calendrier-recuperation-donnees.md) | Comment la vue admin **Réservations** récupère les réservations d'un mois, les enrichit avec les données WooCommerce et les affiche (grille mensuelle + panneau journalier). |
| [securite.md](securite.md) | État des lieux sécurité : failles identifiées sur le parcours de réservation, leur statut (corrigé / à traiter) et les correctifs mis en place. |

## Concepts transverses

- **Stockage en UTC** : les créneaux (`start_datetime` / `end_datetime`) circulent et sont
  stockés en UTC sur tout le parcours ; la conversion vers le fuseau du site n'a lieu qu'à
  l'affichage.
- **Checkout Block uniquement** : le projet utilise exclusivement le WooCommerce Checkout Block
  (Store API). Voir [`CLAUDE.md`](../CLAUDE.md) à la racine du plugin.
- **Table `wp_lm_bookings`** : source de vérité du moteur de disponibilité. Son champ `status`
  est une projection dénormalisée du statut de la commande WooCommerce.
