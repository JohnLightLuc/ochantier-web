# Handoff : Back-office O’CHANTIER ADMIN

## Mise en ligne sur GitHub avec Claude Code (à faire en premier)

1. Décompressez cette archive dans le dossier local de votre dépôt GitHub (déjà cloné), par exemple dans `backoffice/`.
2. Ouvrez un terminal dans le dépôt, lancez `claude`, puis donnez-lui cette consigne :
   > « Ajoute le dossier `backoffice/` tel quel au dépôt, crée un commit "Back-office O’CHANTIER ADMIN – maquette" et pousse-le sur la branche main. Ne modifie aucun fichier. »
3. Facultatif, pour une URL publique : demandez à Claude Code d’activer GitHub Pages (branche `main`, dossier racine), ou faites-le dans **Settings → Pages**. Ouvrez ensuite `…/backoffice/Back%20Office%20OChantier.dc.html`.

Tous les fichiers doivent rester **dans le même dossier** : le fichier principal charge `support.js`, `ochantier-tokens.css` et les composants `*.dc.html` en chemins relatifs. La maquette fonctionne sans serveur applicatif, mais pas en `file://` (elle charge des fichiers voisins) : utilisez GitHub Pages ou `npx serve .` en local.

## Vue d’ensemble
Maquette haute fidélité et entièrement interactive du back-office d’O’CHANTIER, une plateforme de mise en relation entre promoteurs immobiliers et ouvriers du BTP en Côte d’Ivoire. Toutes les données sont fictives et réalistes. Les montants sont affichés en FCFA, les dates au format JJ/MM/AAAA, les heures en 24 h.

## À propos des fichiers
Les fichiers sont des **références de design créées en HTML** (« Design Components » `.dc.html`, exécutés par `support.js`). Ce ne sont pas du code de production. Pour une application réelle, **recréez ces écrans dans l’environnement du projet** (React, Vue…) en suivant ses conventions ; sans environnement existant, choisissez le framework le plus adapté. Le fichier principal regroupe toute la logique dans une classe `Component` (JavaScript classique), avec un gabarit à attributs `{{ }}`.

## Fidélité
**Haute fidélité** : couleurs, typographie, espacements et interactions sont définitifs. Toutes les valeurs proviennent des variables de `ochantier-tokens.css`, à reprendre à l’identique.

## Écrans et modules (routes par hash)
| Module | Route | Contenu |
|---|---|---|
| Connexion | écran initial | Authentification, états d’erreur |
| Tableau de bord | `#/` | KPI, activité récente |
| Promoteurs | `#/promoteurs/…` | Liste, fiche, KYC, litiges |
| Ouvriers | `#/ouvriers/…` | Liste, fiche, documents, calendrier, formulaire en étapes |
| Commerciaux | `#/commerciaux/…` | Objectifs, score, commissions |
| Chantiers | `#/chantiers/…` | Liste, fiche, progression, documents |
| Besoins & Offres | `#/besoins/…` | Besoins par métier, couverture, affectations |
| Affectations & Offres | `#/affectations/…` | Offres des promoteurs, remplacements |
| Matching | `#/matching/…` | Moteur de score (critères bloquants + 8 critères pondérés) |
| Pointages | `#/pointages/…` | Arrivées/départs, contrôles, clôture |
| Paiements | `#/paiements/…` | Calcul brut/commission Y-Z/net, reversements, remboursements |
| Finance & Reporting | `#/finance-reporting/…` | 33 KPI, rapprochement, clôtures, rapports |
| Avis & Litiges | `#/avis-litiges/…` | Avis, modération, réclamations, litiges contradictoires, médiation, recours |
| Paramètres | `#/parametres/<catégorie>` | Commissions & frais (X, Y, Z, R et seuils de paiement successifs), Général, Métiers (ajout, suppression, activation), Paiements, Matching & affectations, Utilisateurs & rôles, Notifications, Sécurité, Intégrations, Journal d’audit |

La mise en page commune (AdminLayout) comprend une Sidebar repliable, une Topbar, un en-tête de page avec fil d’Ariane, des onglets, des cartes KPI, des filtres, des tableaux paginés (8 lignes par page), des panneaux latéraux et des modales.

## Interactions et comportements
- Navigation par hash, avec prise en charge du bouton retour (`syncHash`).
- Modales génériques `openModal({ kind: 'fields' | 'reason' | … })`, avec validation par champ.
- Toasts de succès, d’information et d’erreur (avec bouton « Réessayer »).
- Actions simulées : chargement ≈ 450 à 900 ms, puis succès ou erreur.
- **Paramètres** :
  - enregistrement d’un brouillon, avec indicateur « Modifications non enregistrées » ;
  - garde de sortie si des modifications ne sont pas enregistrées ;
  - validation bloquante : bornes, entiers, sommes de pondérations à 100 %, seuils strictement croissants ;
  - historique et journal d’audit.
- **Données sensibles** : secrets et identifiants toujours masqués, visibilité selon le rôle.
- **Responsive** : prévu pour 1728, 1440, 1280 et 1024 px. À 1024 px, la Sidebar se replie et les tableaux défilent horizontalement.

## Gestion d’état
- État React par écran : `screen`, routes par module (`wroute`, `croute`, `chroute`, `yroute`, `froute`, `lroute`…), filtres, période et pagination.
- Paramètres : `state.ps` avec `saved` (valeurs publiées lues par les autres modules), `draft`/`sd` (brouillons), `log` (audit) et `cat` (catégorie active).
- Jeux de données de démonstration générés au chargement : `this.P`, `this.W`, `this.C`, `this.CHS`, `this.PY`, `this.FIN`, `this.ALD`.

## Design tokens
Voir `ochantier-tokens.css`, qui fait foi : couleurs `--c-*`, espacements `--space-*`, tailles de texte `--fs-*`, rayons `--radius-*`, ombres `--shadow-*`, hauteurs de contrôle `--control-*`. La page `Design System OChantier.dc.html` présente l’ensemble des composants et tokens.

## Assets
Icônes : Material Symbols Rounded (Google Fonts). Aucune image propriétaire.

## Fichiers
- `Back Office OChantier.dc.html` : application complète (fichier principal)
- `Avatar`, `Button`, `StatCard`, `StateBlock`, `StatusBadge` (`.dc.html`) : composants partagés
- `Design System OChantier.dc.html` : référence du design system
- `ochantier-tokens.css` : variables globales
- `support.js`, `image-slot.js` : moteur d’exécution des Design Components (ne pas modifier)
