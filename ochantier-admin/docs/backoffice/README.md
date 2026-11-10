# Documentation du Back Office O’CHANTIER

Documentation fonctionnelle et technique du back-office `ochantier-admin` (dashboard.ochantier.ci).

Elle s’adresse à deux publics :

- **l’équipe métier / opérations** : ce que fait chaque écran, qui peut agir, les règles de gestion et les cycles de vie ;
- **les développeurs** : le chemin d’une requête, les routes, les endpoints de l’API, les classes qui portent la logique et les points d’attention.

Chaque document commence par une partie **Fonctionnel**, suivie d’une partie **Technique**. Les termes métier sont définis dans le [glossaire](#glossaire).

## Sommaire

### Socle commun

| # | Document | Contenu |
|---|----------|---------|
| 00 | [Architecture](00-architecture.md) | Back-office BFF au-dessus de l’API, chemin d’une requête, conventions de code (contrôleurs, Presenters, onglets, composants) |
| 01 | [Authentification et droits d’accès](01-authentification-acces.md) | Connexion avec code, sessions, jetons, mot de passe, rôles et permissions, activation des commerciaux |
| 02 | [Configuration, tests et déploiement](02-configuration-deploiement.md) | Variables d’environnement, démarrage local, tests, mise en ligne cPanel |

### Modules

| # | Module | URL | Document |
|---|--------|-----|----------|
| 10 | Vue d’ensemble (tableau de bord) | `/` | *à venir* |
| 11 | Promoteurs | `/promoteurs` | *à venir* |
| 12 | Ouvriers | `/ouvriers` | *à venir* |
| 13 | Commerciaux et classement | `/commerciaux` | *à venir* |
| 14 | Chantiers | `/chantiers` | *à venir* |
| 15 | Besoins et offres | `/besoins` | *à venir* |
| 16 | Matching | `/matching` | *à venir* |
| 17 | Affectations et remplacements | `/affectations` | *à venir* |
| 18 | Pointages | `/pointages` | *à venir* |
| 19 | Paiements | `/paiements` | *à venir* |
| 20 | Abonnements | `/abonnements` | *à venir* |
| 21 | Finance et reporting | `/finance-reporting` | *à venir* |
| 22 | Avis et litiges | `/avis-litiges` | *à venir* |
| 23 | Rapports | `/rapports` | *à venir* |
| 24 | Paramètres | `/parametres` | *à venir* |

## Structure d’un document de module

1. **Rôle du module** : le besoin métier couvert.
2. **Fonctionnel**
   - écrans, onglets, filtres et indicateurs ;
   - actions disponibles et permissions requises ;
   - cycle de vie (statuts et transitions) ;
   - règles de gestion et notifications envoyées.
3. **Technique**
   - routes du back-office, puis endpoints de l’API appelés ;
   - contrôleurs, Presenters et classes `*Tabs` du back-office ;
   - services de l’API qui portent les règles ;
   - pages et composants React ;
   - tests.
4. **Points d’attention** : limites connues, choix arrêtés, évolutions prévues.

## Autres références

- Schéma de la base : migrations de l’API (`ochantier-api/database/migrations`, schéma v1.1). Le fichier [`../database-schema.md`](../database-schema.md) décrit l’**ancien** schéma local de démonstration, supprimé depuis (migration `2026_11_07_000000_drop_demo_tables`) : il est obsolète.
- Énumérations de l’API : [`../../../ochantier-api/docs/enums.md`](../../../ochantier-api/docs/enums.md)
- Collections Postman de l’API : `ochantier-api/docs/postman/`
- Maquette et design system : `design_handoff_backoffice_ochantier/` (racine du dépôt)

## Glossaire

| Terme | Définition |
|-------|------------|
| **Promoteur** | Client qui fait réaliser un chantier : particulier, entreprise ou technicien. Utilise l’application Promoteur. |
| **Ouvrier** | Travailleur inscrit sur la plateforme, en général recruté par un commercial. Il n’a pas d’application : il est contacté par téléphone ou par son commercial. |
| **Commercial** | Agent terrain qui recrute des ouvriers et des promoteurs. Il est rémunéré par commissions et figure dans un classement. Utilise l’application Commercial. |
| **Chantier** | Projet d’un promoteur, localisé, avec une période et une équipe. |
| **Besoin / Offre** | Demande de main-d’œuvre d’un promoteur pour un chantier : une réservation qui regroupe des besoins par métier. |
| **Matching** | Recherche des ouvriers les plus adaptés à une offre, puis envoi de la proposition au promoteur. |
| **Affectation** | Un ouvrier retenu sur un chantier pour une période. |
| **Pointage** | Présence d’un ouvrier pour une journée, déclarée et validée par le promoteur. |
| **Reversement** | Paiement versé par O’CHANTIER à un ouvrier ou à un commercial. |
| **Rapprochement** | Comparaison des encaissements et décaissements enregistrés avec les relevés de l’opérateur de paiement. |
| **Clôture** | Arrêt des comptes d’un mois. |
| **BFF** | *Backend for frontend* : serveur propre à une interface, qui relaie les appels vers l’API métier. |
