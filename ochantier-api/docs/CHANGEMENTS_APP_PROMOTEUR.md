# O’CHANTIER — Changements de l’API pour l’application Promoteur

Date : 8 novembre 2026 · Destinataires : équipe mobile (Flutter)

Ce document décrit ce qui change dans l’API pour l’application **Promoteur**, à la suite des mises à jour de la maquette (`docs/MISES_A_JOUR.md`) :

- la question « Avez-vous un devis ? » de l’offre contractuelle ;
- la demande de devis ;
- les offres d’entrepreneurs ;
- l’acompte de l’offre contractuelle ;
- la règle du responsable de chantier.

Toutes les routes sont préfixées par `/api/v1/promoter`. La collection Postman `docs/postman/promoteur/ochantier-promoteur.postman_collection.json` contient chaque requête avec des réponses réelles, dans le dossier **« Offre contractuelle »**.

> **Rien ne change pour l’offre journalière.** Les écrans de réservation d’ouvriers, de proposition d’équipe et de paiement de l’acompte d’une proposition restent identiques.

---

## 1. Résumé

| # | Changement | Écrans | Type |
|---|---|---|---|
| 1 | « Avez-vous un devis ? » sur l’offre contractuelle | Nouveau / Modifier un chantier | Nouveaux champs |
| 2 | Avec devis : le devis est obligatoire, les autres documents deviennent facultatifs | Nouveau chantier | Règle modifiée |
| 3 | « Fin de chantier » retiré du paiement du solde | Nouveau chantier | Valeur supprimée |
| 4 | Demande de devis (corps de métier, précisions) | Nouveau chantier, Mes chantiers, Détail | Nouveau parcours |
| 5 | Offres de devis par métier, commande payée en une fois | Détail, Paiement | Nouvelles routes |
| 6 | Devis reçus : retour au formulaire avec « Oui » déjà choisi | Détail, Nouveau chantier | Nouveau parcours |
| 7 | L’offre contractuelle part directement aux entrepreneurs | Publication | Comportement modifié |
| 8 | Offres d’entrepreneurs, choix d’une offre, acompte | Détail, Mes chantiers, Paiement | Nouvelles routes |
| 9 | Nouveaux badges et nouvelles actions sur les cartes de chantier | Mes chantiers, Accueil | Nouveaux champs |
| 10 | Responsable du chantier : collaborateurs réservés aux comptes abonnés | Nouveau chantier | Règle modifiée |
| 11 | Un brouillon dont les devis ont été payés ne peut plus être supprimé | Mes chantiers, Détail | Règle modifiée |

---

## 2. Formulaire « Nouveau chantier »

### 2.1 `GET sites/form-options`

Nouveaux champs :

```json
{
  "payment_modes": [
    { "value": "hebdomadaire", "label": "Hebdomadaire" },
    { "value": "par_etape", "label": "Par étape" }
  ],
  "quote_trades": [
    { "id": "uuid", "code": "macon", "label": "Maçon" },
    { "id": "uuid", "code": "electricien", "label": "Électricien" }
  ],
  "quote_document_type": "site_quote"
}
```

- `payment_modes` **ne contient plus** `fin_de_chantier`.
- `quote_trades` liste les corps de métier proposés dans la « Demande de devis » : Maçon, Électricien, Plombier, Peintre, Menuisier, Carreleur, Coffreur, Ferrailleur, Soudeur, Étanchéiste. Manœuvre n’y figure pas.
- `quote_document_type` est le code à utiliser pour envoyer le devis du promoteur (voir 2.3).
- Dans `responsibles`, les collaborateurs n’apparaissent que si le compte a un **abonnement en cours**. Sinon, la liste ne contient que le promoteur, « Moi-même » (voir §6).

### 2.2 `PUT sites/{id}/offer` — offre contractuelle

Le bloc `contract` change :

| Champ | Quand | Description |
|---|---|---|
| `has_quote` | toujours | Réponse à « Avez-vous un devis ? » : `true`, `false` ou `null` (pas encore répondu). |
| `amount` | `has_quote = true` | Montant de l’offre financière, en FCFA (10 000 minimum). |
| `payment_mode` | `has_quote = true` | `hebdomadaire` ou `par_etape`. **`fin_de_chantier` est refusé (422).** |
| `quote_request.trade_ids` | `has_quote = false` | Un ou plusieurs `id` de `quote_trades`. |
| `quote_request.notes` | `has_quote = false` | « Précisions pour le technicien », facultatif, 1 000 caractères au maximum. |

**Oui, j’ai un devis :**

```json
{
  "type": "contractuelle",
  "contract": { "has_quote": true, "amount": 3300000, "payment_mode": "par_etape" }
}
```

**Non, je demande un devis :**

```json
{
  "type": "contractuelle",
  "contract": {
    "has_quote": false,
    "quote_request": {
      "trade_ids": ["uuid-macon", "uuid-plombier"],
      "notes": "Villa R+1, accès par la rue pavée."
    }
  }
}
```

Le récapitulatif `data.offer.draft` reçoit de nouveaux champs :

```json
{
  "has_quote": false,
  "quotes": [],
  "quote_request": {
    "trades": [{ "id": "uuid", "code": "macon", "label": "Maçon" }],
    "notes": "Villa R+1, accès par la rue pavée."
  },
  "submit_label": "Rechercher un technicien",
  "documents_hint": "Joignez vos plans et le cahier des charges (facultatif)"
}
```

- `submit_label` donne le libellé du bouton principal : « Publier » ou « Rechercher un technicien ».
- `quotes` liste les devis joints (format `DocumentResource` : `id`, `type`, `files[]` avec `url`, `name`, `mime_type`, `size_bytes`). Un tap sur un devis l’ouvre (`files[0].url`).
- Quand `has_quote = false`, masquer la section « Offre financière ».

### 2.3 Télécharger le devis (Oui)

Utiliser la route existante `POST sites/{id}/documents` en `multipart/form-data` :

| Champ | Valeur |
|---|---|
| `document_type` | `site_quote` |
| `file` | PDF, JPEG ou PNG, 10 Mo au maximum |

Retirer un devis : `DELETE sites/{id}/documents/{documentId}` (route existante).

### 2.4 Règles de publication (`missing_for_publication` et 422 sur `publish`)

| Cas | Éléments exigés |
|---|---|
| Contractuelle, question sans réponse | « Indiquez si vous avez un devis » |
| Contractuelle avec devis | photo, titre, localisation, dates, **devis joint** (« Téléchargez votre devis »), montant, paiement du solde hebdomadaire ou par étape |
| Contractuelle sans devis | **titre, localisation, au moins un corps de métier** (ni photo ni dates) |

Les autres documents (plans, cahier des charges…) **ne sont plus obligatoires**.

---

## 3. Publication : `POST sites/{id}/publish`

La route ne change pas, mais son effet dépend du type d’offre :

| Offre | Effet | Statut du chantier | Message |
|---|---|---|---|
| Journalière | Transmise au Back office (inchangé) | `soumis` | « Chantier publié — recherche d’ouvriers lancée » |
| Contractuelle **avec** devis | Part **directement** aux entrepreneurs, sans contrôle du Back office | `recherche` | « Chantier publié — recherche d’entrepreneurs lancée » |
| Contractuelle **sans** devis | Crée la demande de devis (`DV-2026-0001`) | **`devis`** (nouveau) | « Demande de devis envoyée — recherche de technicien lancée » |

---

## 4. Demande de devis

### 4.1 Parcours

```text
Formulaire (Non) ─► « Rechercher un technicien » ─► statut devis « Recherche de technicien pour devis »
   └─► offres reçues ─► 1 offre par métier ─► paiement « Commande des devis » ─► « Devis commandé »
        └─► devis réalisés, ajoutés aux documents ─► chantier revenu au brouillon : « Devis reçu · offre à définir »
             └─► « Définir l’offre financière » (formulaire avec Oui et les devis) ─► Publier ─► §5
```

Pendant la demande, le chantier n’est **plus modifiable** (`can_edit = false`). Un `PATCH` renvoie **422** : « Une demande de devis est en cours… ».

### 4.2 `GET sites/{id}/quote-request` — carte « Offres de devis »

```json
{
  "data": {
    "id": "uuid",
    "reference": "DV-2026-0002",
    "status": { "value": "recherche", "label": "Recherche de technicien" },
    "notes": "Villa R+1, accès par la rue pavée.",
    "trades": [
      {
        "id": "uuid",
        "trade": { "id": "uuid", "code": "macon", "label": "Maçon" },
        "status": { "value": "recherche", "label": "Recherche de technicien" },
        "offers": [
          {
            "id": "uuid-offre",
            "technician": {
              "worker_id": "uuid",
              "name": "Traoré Ali",
              "photo_url": null,
              "rating": { "average": 4.5, "count": 12 },
              "experience_years": 5
            },
            "trade": "Maçon",
            "visit_date": "2026-11-15",
            "distance_km": 3.2,
            "amount": 29000,
            "is_best": true
          }
        ],
        "selected": null,
        "quote": null
      }
    ],
    "offers_count": 5,
    "ready_to_order": true,
    "total_amount": null,
    "quotes_total_amount": null,
    "can_cancel": true,
    "submitted_at": "2026-11-08T21:08:01+00:00",
    "ordered_at": null,
    "received_at": null
  }
}
```

- `status` de la demande : `recherche`, puis `commande`, puis `recu`.
- `offers` contient, **par métier, les 3 meilleures offres**, triées par note du technicien décroissante puis par prix croissant. La première porte le badge « Meilleure offre » (`is_best`).
- **Une seule offre par métier** (bouton radio : un second tap désélectionne). C’est l’application qui garde la sélection jusqu’au paiement.
- `ready_to_order` vaut `true` quand chaque métier a au moins une offre. Le bouton « Payer … · commander » ne s’active que lorsque chaque métier a une offre choisie.
- `selected` : offre commandée, à afficher sur la carte verte « Devis commandé » (métier, date de passage, prix).
- `quote` : devis réalisé (voir 4.5).
- Le même objet est aussi renvoyé dans `GET sites/{id}`, sous `data.quote_request`.

### 4.3 Annuler la demande : `DELETE sites/{id}/quote-request`

Possible tant qu’aucun devis n’est commandé (`can_cancel`). Le chantier revient alors au brouillon. Après la commande, l’API renvoie **422**.

### 4.4 Commander les devis (paiement groupé)

Le paiement Mobile Money existant prend une nouvelle cible : **`quote_bid_ids`**, avec une offre par corps de métier.

`POST payments/quote` (détail du montant) :

```json
{ "quote_bid_ids": ["uuid-offre-macon", "uuid-offre-plombier"], "operator_code": "orange_money" }
```

```json
{
  "data": {
    "kind": "commande_devis",
    "label": "Commande des devis · 2 métiers",
    "lines": [
      { "label": "Devis maçon", "amount": 29000 },
      { "label": "Devis plombier", "amount": 20000 },
      { "label": "Frais opérateur Orange Money 1,5 %", "amount": 735 }
    ],
    "amount": 49000,
    "fees": 735,
    "total": 49735
  }
}
```

`POST payments` (avec l’en-tête `Idempotency-Key`) :

```json
{
  "quote_bid_ids": ["uuid-offre-macon", "uuid-offre-plombier"],
  "operator_code": "orange_money",
  "dial_code": "225",
  "phone_number": "0712345634"
}
```

- Erreurs **422** :
  - « Choisissez une offre pour « Plombier ». » (un métier sans offre) ;
  - « Cette offre n’est plus proposée pour « Maçon ». » ;
  - « Ces devis sont déjà commandés. ».
- La commande ne peut pas être payée en plusieurs fois.
- Après le paiement, le chantier passe à « Devis commandé ». Un reçu PDF est émis, comme pour les autres paiements.

### 4.5 Devis reçu et volet « Devis »

Quand un devis est réalisé, `trades[].quote` est rempli :

```json
{
  "number": "DE-2026-00001",
  "issued_on": "2026-11-08",
  "valid_until": "2026-12-08",
  "technician": {
    "name": "Traoré Ali",
    "photo_url": null,
    "rating": { "average": 4.5, "count": 12 },
    "experience_years": 5,
    "visited_on": "2026-11-15",
    "distance_km": 3.2
  },
  "site": { "label": "Villa Riviera", "location": "Riviera 3, Abidjan", "trade": "Maçon" },
  "items": [
    { "designation": "Fournitures · maçon", "unit_price": 871000, "quantity": 1, "total": 871000 }
  ],
  "total_amount": 1021000,
  "status": { "value": "effectue", "label": "Effectué" },
  "document": { "id": "uuid", "type": { "code": "site_quote" }, "files": [{ "url": "…", "name": "Devis Maçon DE-2026-00001.pdf" }] }
}
```

- Le volet « Devis » se construit à partir de ce bloc :
  - en-tête : N° de devis et date d’établissement ;
  - bloc **Technicien** et bloc **Chantier** ;
  - tableau des lignes et montant total ;
  - validité de 30 jours ;
  - signature.
- **Télécharger** : `document.files[0].url`, un PDF généré par l’API.
- Afficher l’étiquette `status.label` (« Effectué »). Elle corrige le texte « undefined » de la maquette.
- Chaque devis est aussi ajouté aux **Documents** du chantier (type `site_quote`).
- Quand tous les devis sont reçus :
  - le chantier **revient au brouillon** (`status = brouillon`, badge `devis_recu`) ;
  - `offer.draft.has_quote` vaut `true` et `offer.draft.quotes` contient les devis ;
  - `quotes_total_amount` donne le « montant total estimé ».

  Le bouton « Définir l’offre financière » ouvre le formulaire : il ne reste qu’à ajouter la photo et les dates si elles manquent, saisir le montant et publier (voir §3).

---

## 5. Offres d’entrepreneurs (offre contractuelle publiée)

Ne reçoivent l’offre contractuelle que les **entrepreneurs**, c’est-à-dire les entreprises et les indépendants. Les ouvriers ne la reçoivent pas.

### 5.1 `GET sites/{id}/contractor-offers` — carte « Offres d’entrepreneurs »

```json
{
  "data": {
    "offer_id": "uuid",
    "offers": [
      {
        "id": "uuid-offre",
        "contractor": {
          "id": "uuid",
          "name": "BTP Lagune SARL",
          "kind": { "value": "entreprise", "label": "Entreprise" },
          "logo_url": null,
          "rating": { "average": 4.8, "count": 42 },
          "completed_sites": 42
        },
        "team_size": 11,
        "amount": 2870640,
        "duration_days": 32,
        "distance_km": 4.1,
        "score": 88.8,
        "message": null,
        "status": { "value": "proposee", "label": "Proposée" },
        "is_chosen": false,
        "is_best": true
      }
    ],
    "chosen_id": null,
    "deposit_percent": 30,
    "deposit_amount": null,
    "can_pay": false
  }
}
```

- `offers` contient les **3 meilleures offres**, classées par `score` sur 100 :

  | Critère | Points |
  |---|---|
  | Note de l’entrepreneur | 40 |
  | Montant | 30 |
  | Durée | 15 |
  | Distance au chantier | 15 |

  Le montant, la durée et la distance sont comparés aux autres offres reçues. Le badge « Meilleure offre » correspond à `is_best`.
- `amount` est le **prix pour le promoteur**, commission O’CHANTIER comprise. C’est ce montant qu’il faut afficher.
- `kind` vaut `entreprise` ou `independant`.
- Le même objet est renvoyé dans `GET sites/{id}`, sous `data.contractor_offers`, tant que l’offre est en recherche ou que l’acompte reste à payer.

### 5.2 Choisir une offre : `POST sites/{id}/contractor-offers/{offerId}/choose`

- Une **seule** offre est retenue. Le choix reste modifiable tant que l’acompte n’est pas payé : choisir une autre offre remplace la précédente.
- La réponse est le même objet qu’en 5.1, avec `chosen_id`, `deposit_amount` et `can_pay = true`.
- Le chantier passe en `paiement`, avec le badge « Offre choisie · acompte à payer ».
- L’acompte apparaît aussi dans `GET payments`, sous la forme d’une ligne dont `target` vaut `{ "contractor_bid_id": "…" }`.
- Une offre qui n’est pas parmi les 3 meilleures renvoie **422** : « Cette offre n’est plus proposée. ».

### 5.3 Payer l’acompte

Nouvelle cible de paiement : **`contractor_bid_id`**.

`POST payments/quote` :

```json
{ "contractor_bid_id": "uuid-offre", "operator_code": "orange_money" }
```

Lignes renvoyées :

```json
[
  { "label": "Offre de BTP Lagune SARL (32 jours)", "amount": 2658000 },
  { "label": "Commission O’CHANTIER 8 %", "amount": 212640 },
  { "label": "Total du forfait", "amount": 2870640 },
  { "label": "Acompte 30 %", "amount": 861000 },
  { "label": "Frais opérateur Orange Money 1,5 %", "amount": 12915 }
]
```

`POST payments` (avec l’en-tête `Idempotency-Key`) :

```json
{ "contractor_bid_id": "uuid-offre", "operator_code": "orange_money", "dial_code": "225", "phone_number": "0712345634" }
```

- L’acompte vaut X % (30 %) du **total commission comprise**.
- Un abonné peut payer en 2 ou 3 fois, avec `installments`, comme l’acompte d’une proposition.
- Une offre non choisie renvoie **422** : « Choisissez d’abord cette offre. ».
- Après le paiement :
  - le chantier est **confirmé** ;
  - l’échéancier du solde apparaît dans « Paiements » : une échéance par semaine de travaux, ou 3 échéances « par étape ». Dans ces lignes, `who` contient le nom de l’entrepreneur au lieu d’un nombre d’ouvriers.

### 5.4 Suivi du chantier en cours (offre contractuelle)

Ce bloc est **en cours de développement** (étape suivante). Il comprendra :

- « Entrepreneur du chantier » à la place de « Équipe sur le chantier » ;
- le pointage de l’entrepreneur en consultation seule, avec la liste nominative de ses ouvriers ;
- la chronologie, à laquelle le promoteur peut ajouter des étapes ;
- le masquage de « Réservations d’ouvriers ».

Une mise à jour de ce document suivra.

---

## 6. Responsable du chantier

Le champ `responsible_user_id` (`POST sites`, `PATCH sites/{id}`) accepte :

- le promoteur lui-même ;
- un collaborateur de son équipe (actif ou invité), **uniquement si le compte a un abonnement en cours**.

| Situation | Réponse |
|---|---|
| Sans abonnement, collaborateur choisi | **422** « Un abonnement est nécessaire pour confier un chantier à un collaborateur. » |
| Avec abonnement, personne hors de l’équipe | **422** « Choisissez vous-même ou un utilisateur de votre équipe. » |
| Responsable déjà désigné, renvoyé tel quel après la fin de l’abonnement | Accepté |

Pour rappel, ces règles existaient déjà : l’invitation de collaborateurs (`POST team/invitations`) exige un abonnement en cours et respecte le nombre d’utilisateurs de la formule. Voir `GET team` : `can_invite`, `invite_refusal`, `seats`.

---

## 7. Cartes « Mes chantiers » et Accueil

### 7.1 Nouveaux badges (`display_status`)

| `value` | Libellé | Statut enregistré |
|---|---|---|
| `devis_recherche` | Recherche de technicien pour devis | `devis` |
| `devis_commande` | Devis commandé | `devis` |
| `devis_recu` | Devis reçu · offre à définir | `brouillon` |
| `recherche_entrepreneurs` | Recherche d’entrepreneurs en cours | `recherche` |
| `offres_entrepreneurs` | Offres d’entrepreneurs reçues | `recherche` |
| `offre_choisie` | Offre choisie · acompte à payer | `paiement` |

Le nouveau statut enregistré `devis` a pour libellé « Demande de devis ».

### 7.2 Nouveaux champs de la carte

```json
{
  "quote_request": {
    "id": "uuid",
    "reference": "DV-2026-0002",
    "status": { "value": "recherche", "label": "Recherche de technicien" },
    "action": { "value": "choisir_devis", "label": "Choisir les offres de devis" }
  },
  "contractor_offers": {
    "action": { "value": "payer_acompte", "label": "Payer l’acompte" }
  }
}
```

Les deux champs valent `null` quand ils ne s’appliquent pas.

| `action.value` | Bouton | Ouvre |
|---|---|---|
| `choisir_devis` | Choisir les offres de devis | Détail, carte « Offres de devis » |
| `definir_offre` | Définir l’offre financière | Formulaire de l’offre |
| `choisir_entrepreneur` | Choisir une offre | Détail, carte « Offres d’entrepreneurs » |
| `payer_acompte` | Payer l’acompte | Paiement (`contractor_bid_id`) |

### 7.3 Onglets (`GET sites?filter=`)

- `recherche` comprend aussi les demandes de devis (`devis`) et les brouillons dont les devis sont reçus (`devis_recu`).
- `brouillons` **n’inclut plus** les brouillons dont les devis sont reçus.
- Les compteurs `meta.counts` suivent la même règle.

### 7.4 Suppression d’un brouillon

- `can_delete` vaut `false` dès que des devis ont été payés pour le chantier, même s’il est revenu au brouillon.
- `DELETE sites/{id}` renvoie alors **422** : « Des devis ont été commandés pour ce chantier : il ne peut plus être supprimé. ».
- Le volet de confirmation « Supprimer ce brouillon ? » utilise la route existante.

---

## 8. Paiements (`GET payments`)

| Nouveau | Où |
|---|---|
| Motif `commande_devis` (« Commande des devis ») | Historique des transactions et reçus |
| Ligne « Acompte » d’une offre d’entrepreneur choisie, avec `target.contractor_bid_id` | Liste « Paiements » (`a_payer`) |
| Échéances « par étape » (`kind.label` = « Échéance par étape ») | Liste « Paiements » |
| `who` = nom de l’entrepreneur pour les échéances d’une offre contractuelle | Liste « Paiements » |

Pour payer une ligne, envoyer tel quel le contenu de `target` à `POST payments`, comme aujourd’hui.

---

## 9. Tester sans l’application Ouvriers

Les techniciens et les entrepreneurs répondront depuis l’**application Ouvriers**, qui n’est pas encore disponible. Hors production, leurs réponses se simulent sur le serveur :

| Commande | Effet |
|---|---|
| `php artisan ochantier:simulate-quotes [--site=CH-2026-0001]` | 1er passage : offres des techniciens. Après la commande : devis réalisés. |
| `php artisan ochantier:simulate-contractors [--site=CH-2026-0001]` | Offres de 4 entrepreneurs (entrepreneurs de démonstration créés au besoin). |

Parcours de test complet :

1. Brouillon contractuel avec « Non » et deux corps de métier.
2. Rechercher un technicien.
3. `simulate-quotes`.
4. Commander les devis.
5. `simulate-quotes` (devis réalisés).
6. Ajouter la photo et les dates, définir l’offre financière, publier.
7. `simulate-contractors`.
8. Choisir une offre.
9. Payer l’acompte.

---

## 10. Pour adapter l’application

- [ ] Formulaire : question « Avez-vous un devis ? » (Oui/Non obligatoire), zone « Télécharger le devis » (`site_quote`, PDF ou image), section « Demande de devis » (corps de métier de `quote_trades` et précisions).
- [ ] Formulaire : retirer « Fin de chantier », libeller le bouton avec `submit_label`, rendre les autres documents facultatifs.
- [ ] Formulaire : n’afficher dans les responsables que la liste `responsibles`, et gérer le 422 lié à l’abonnement.
- [ ] Mes chantiers : nouveaux badges, boutons `quote_request.action` et `contractor_offers.action`, règle de l’onglet « En recherche ».
- [ ] Détail : carte « Offres de devis » (3 offres par métier, une par métier, total, « Payer … · commander »), carte verte « Devis commandé », carte « Devis reçu ».
- [ ] Volet « Devis » : à partir de `quote` (structure PDF), avec Télécharger (`document.files[0].url`) et Fermer.
- [ ] Détail : carte « Offres d’entrepreneurs » (3 offres, une seule sélectionnable, acompte, « Payer l’acompte »).
- [ ] Paiement : cibles `quote_bid_ids` et `contractor_bid_id` dans `payments/quote` et `payments`.
- [ ] Brouillon : bouton corbeille selon `can_delete`.
