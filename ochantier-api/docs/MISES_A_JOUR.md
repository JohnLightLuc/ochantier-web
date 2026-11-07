# O'CHANTIER — Mises à jour de l'application

Prototype : `OChantier App.dc.html` · Date : 5 novembre 2026

Ce document liste les améliorations faites depuis l'export des 57 écrans (`export/OChantier_Ecrans/`). **Les écrans exportés ne contiennent pas encore ces changements.**

---

## 1. Vue d'ensemble

| # | Évolution | Écrans concernés |
|---|-----------|------------------|
| 1 | Question « Avez-vous un devis ? » pour l'offre contractuelle | Nouveau chantier |
| 2 | Demande de devis : recherche d'un technicien par corps de métier | Nouveau chantier, Mes chantiers, Détail chantier |
| 3 | 3 meilleures offres de devis par métier, une seule à choisir, paiement groupé | Détail chantier, Paiement |
| 4 | Devis ajouté automatiquement au chantier, puis saisie de l'offre financière | Détail chantier, Nouveau chantier |
| 5 | Devis consultable au format PDF | Détail chantier, Nouveau chantier, volet Devis |
| 6 | Option « Fin de chantier » retirée de « Paiement du solde » | Nouveau chantier |
| 7 | Libellé « Recherche d'entrepreneurs » pour l'offre contractuelle | Mes chantiers, Détail chantier, messages |
| 8 | 3 meilleures offres d'entrepreneurs, une seule à choisir, puis acompte | Détail chantier, Mes chantiers |
| 9 | Offre contractuelle : l'entrepreneur gère l'équipe, pointage en consultation | Détail chantier |
| 10 | Section « Réservations d'ouvriers » masquée pour l'offre contractuelle | Détail chantier |
| 11 | Accueil d'un nouveau compte (sans chantier ni paiement) | Accueil |
| 12 | Suppression d'un chantier au brouillon | Mes chantiers, Détail chantier, volet de confirmation |
| 13 | Correction du texte « undefined » → « Effectué » | Détail chantier |

---

## 2. Parcours de l'offre contractuelle

```
Nouveau chantier ─ Offre contractuelle
   │
   ├─ « Avez-vous un devis ? » ── OUI ──► Télécharger le devis (PDF / image)
   │                                       └─► Offre financière ─► Publier
   │                                                                  │
   └─ NON ─► Demande de devis (corps de métier)                       │
              └─► Statut « Recherche de technicien pour devis »       │
                   └─► 3 meilleures offres PAR MÉTIER                 │
                        └─► 1 offre choisie par métier ─► Paiement    │
                             └─► Statut « Devis commandé »            │
                                  └─► Devis réalisé = ajouté au chantier
                                       └─► Statut « Devis reçu · offre à définir »
                                            └─► Offre financière ─► Publier
                                                                      │
                         Statut « Recherche d'entrepreneurs en cours » ◄┘
                              └─► 3 meilleures offres d'entrepreneurs
                                   └─► 1 seule offre choisie ─► Paiement de l'acompte (30 %)
                                        └─► Chantier en cours : l'entrepreneur gère son équipe
                                             └─► Fin de chantier : rapport (si abonnement)
```

---

## 3. Détail par écran

### 3.1 Accueil

**Nouvelle variante « Accueil · nouveau compte »**, disponible dans la liste des écrans.
- **Mes chantiers** : si aucun chantier, une carte « Aucun chantier pour l'instant » s'affiche avec deux boutons : **Voir les ouvriers** et **Créer un chantier**.
- **Paiement en attente** : si aucun paiement, une carte « Aucun paiement en attente » explique que les acomptes et les échéances apparaîtront après la première réservation.
- Dès qu'un chantier est créé, l'accueil normal revient.

### 3.2 Mes chantiers (liste)

- **Nouveaux statuts** :
  - `Recherche de technicien pour devis` (bleu)
  - `Devis commandé` (vert)
  - `Devis reçu · offre à définir` (jaune)
  - `Recherche d'entrepreneurs en cours` (offre contractuelle)
  - `Offres d'entrepreneurs reçues` / `Offre choisie · acompte à payer`
- Le filtre **« En recherche »** inclut aussi les statuts liés au devis.
- **Actions sur les cartes** :
  - Demande de devis avec offres reçues : **Choisir les offres de devis**
  - Devis reçu : **Définir l'offre financière**
  - Offres d'entrepreneurs reçues : **Choisir une offre**, puis **Payer l'acompte**
  - Brouillon : **Supprimer** (nouveau), **Modifier**, **Publier**
- La ligne de recherche indique « Nous contactons les entrepreneurs… » pour une offre contractuelle.

### 3.3 Nouveau chantier / Modifier un chantier (formulaire)

**Type d'offre → Offre contractuelle**
- Nouvelle question **« Avez-vous un devis ? »** avec les boutons **Oui** et **Non**. Il faut y répondre pour publier.

**Si Oui**
- Zone **« Télécharger le devis »** qui accepte un PDF ou une image (photo, scan). On choisit un vrai fichier depuis l'appareil.
- Le devis ajouté s'affiche (type, nom, taille). On peut l'ouvrir d'un tap ou le retirer avec ×.
- La section **Offre financière** s'affiche ensuite.
- Le devis est obligatoire pour publier. Les autres documents ne le sont plus.

**Si Non : section « Demande de devis »**
- Choix d'un ou plusieurs **corps de métier** : Maçon, Carreleur, Menuisier, Coffreur, Ferrailleur, Électricien, Plombier, Peintre, Étanchéiste, Soudeur.
- Champ facultatif « Précisions pour le technicien ».
- La section **Offre financière** est masquée.
- Le bouton principal devient **« Rechercher un technicien »**.
- Pour publier, il faut un titre, une localisation et au moins un corps de métier.

**Offre financière → Paiement du solde**
- L'option **« Fin de chantier » est supprimée**. Il reste **Hebdomadaire** et **Par étape**.

**Retour depuis un devis reçu**
- Le formulaire s'ouvre avec **Oui** déjà choisi et les devis joints. Il ne reste qu'à saisir le montant et à publier.

### 3.4 Détail chantier

**a) Demande de devis — statut « Recherche de technicien pour devis »**
- Carte **« Offres de devis »** avec la liste des corps de métier demandés.
- Indicateur de recherche en cours : « Recherche de technicien pour le devis ».
- **Offres regroupées par corps de métier** : pour chaque métier, les **3 meilleures offres**, classées par note puis par prix. La première porte le badge « Meilleure offre ».
- Chaque offre indique : **technicien** (photo, nom, note, expérience), **métier**, **date d'exécution**, **distance** du chantier et **prix**.
- **Une seule offre par métier** (bouton radio ; un second tap désélectionne).
- Le récapitulatif affiche le nombre de métiers choisis et le **total**. Le bouton **« Payer … · commander »** ne s'active que si chaque métier a une offre choisie.

**b) Statut « Devis commandé »**
- Carte verte listant chaque technicien commandé : métier, date de passage, prix.
- Mention : chaque devis sera ajouté automatiquement au chantier dès sa réalisation.

**c) Statut « Devis reçu · offre à définir »**
- Carte **« Devis reçu »** avec la liste des devis (un par métier), étiquette **« Effectué »** et montant total estimé.
- Bouton **« Définir l'offre financière »**, qui ouvre le formulaire pour lancer la recherche d'entrepreneurs.
- Les devis s'ajoutent aussi aux **Documents** du chantier.

**d) Offre contractuelle publiée — « Recherche d'entrepreneurs en cours »**
- Le titre et le texte sont adaptés : « Nous transmettons votre offre contractuelle aux entrepreneurs… ».

**e) Offres d'entrepreneurs reçues**
- Carte **« Offres d'entrepreneurs »** avec les **3 meilleures offres**.
- Chaque offre indique : entreprise, note, nombre de chantiers réalisés, taille de l'équipe, **montant**, **durée** et **distance**. Badge « Meilleure offre ».
- **Une seule offre sélectionnable**.
- Montant de l'acompte (30 %) et bouton **« Payer l'acompte »**, actif seulement après sélection.

**f) Chantier en cours — offre contractuelle**
- « Équipe sur le chantier » est remplacée par **« Entrepreneur du chantier »** : entreprise, forfait, taille de l'équipe, et une mention indiquant que l'entrepreneur constitue et gère lui-même son équipe.
- Boutons **Remplacer** et **Rechercher des ouvriers supplémentaires** retirés.
- **« Pointage de l'entrepreneur »** en consultation seule : présences, taux et historique sont visibles. Les boutons d'arrivée, de départ, « Arrivée pour tous » et « Valider la journée » sont retirés. Mention « le pointage est saisi par [entreprise] ».
- **Chronologie** : le promoteur la consulte et peut **ajouter ses propres étapes**.
- Section **« Réservations d'ouvriers » masquée**.

**g) Fin de chantier**
- **Rapport de fin de chantier** consultable si l'abonnement le permet. Sinon, il reste verrouillé avec un lien vers l'abonnement.

**h) Brouillon**
- Nouveau bouton **corbeille** à gauche de « Modifier » dans le bloc « Brouillon non publié ».

**i) Correction**
- Le texte parasite **« undefined »** affiché à côté des devis est remplacé par l'étiquette **« Effectué »**.

### 3.5 Volet « Devis » (nouveau)

Un tap sur un devis l'ouvre depuis la carte Devis reçu, les Documents ou le formulaire.
- **Devis réalisé par un technicien O'CHANTIER**, présenté comme une page PDF :
  - En-tête : logo, **N° de devis**, **date d'établissement**
  - Bloc **Technicien** : photo, nom, métier, note, expérience, date de visite, distance
  - Bloc **Chantier** : nom, localisation, corps de métier
  - **Tableau du devis** : désignation, prix unitaire, quantité, total par ligne, **montant total**
  - Date de validité (30 jours) et **signature du technicien**
- **Devis téléchargé par le promoteur** : le PDF ou l'image est affiché tel quel.
- Boutons **Télécharger** et **Fermer**.

### 3.6 Volet « Supprimer ce brouillon ? » (nouveau)

- Confirmation qui rappelle le nom du chantier, avec la mention « action irréversible ».
- Boutons **Annuler** et **Supprimer** (rouge).
- Après suppression : message de confirmation et retour à « Mes chantiers » si l'on était sur le détail.

### 3.7 Paiement

- Nouveau paiement **« Commande des devis »** : un seul paiement couvre tous les techniciens choisis. Une fois validé, le chantier passe à « Devis commandé ».
- Le paiement de l'**acompte de l'offre contractuelle** reprend le montant de l'offre d'entrepreneur choisie.

---

## 4. Règles fonctionnelles

| Règle | Valeur |
|-------|--------|
| Offres de devis affichées par métier | 3 meilleures |
| Choix possible par métier | 1 seule offre |
| Classement des offres | Note décroissante, puis prix croissant |
| Offres d'entrepreneurs affichées | 3 meilleures |
| Choix d'entrepreneur | 1 seule offre |
| Acompte de l'offre contractuelle | 30 % du montant de l'offre choisie |
| Paiement du solde | Hebdomadaire ou Par étape |
| Formats acceptés pour le devis | PDF ou image |
| Gestion de l'équipe (contractuelle) | Par l'entrepreneur |
| Pointage (contractuelle) | Saisi par l'entrepreneur, consultable par le promoteur |
| Chronologie (contractuelle) | Consultable et modifiable par le promoteur |
| Rapport de fin de chantier | Réservé aux comptes abonnés |
| Suppression | Chantiers au statut Brouillon uniquement |

---

## 5. Données de démonstration (à remplacer en production)

- Arrivée des offres de devis : une toutes les ~1,6 s, 4 offres reçues par métier, les 3 meilleures affichées.
- Réalisation des devis : environ 6 s après le paiement.
- Noms des techniciens et des entreprises, prix, distances, contenu et montants des devis : valeurs d'exemple.

---

## 6. Prochaines étapes

- Exporter à nouveau les écrans iPhone 17 Pro pour y inclure les nouveaux états : demande de devis, offres par métier, devis commandé, devis reçu, volet Devis, offres d'entrepreneurs, entrepreneur du chantier, accueil d'un nouveau compte, suppression d'un brouillon.
- Mettre à jour le dossier de passation pour l'équipe Flutter (`design_handoff_ochantier_flutter/`).
