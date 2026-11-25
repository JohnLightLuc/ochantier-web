# Apps mobiles — Badge des notifications non lues

Intégration du compteur de notifications non lues (badge de la cloche) dans les apps **Commercial** et **Promoteur**
d’O’CHANTIER.

| Usage | Méthode | Commercial | Promoteur |
|---|---|---|---|
| Badge de la cloche | `GET` | `/commercial/notifications/unread-count` | `/promoter/notifications/unread-count` |
| Centre de notifications | `GET` | `/commercial/notifications` | `/promoter/notifications` |
| Ouvrir une notification | `POST` | `/commercial/notifications/{id}/read` | `/promoter/notifications/{id}/read` |
| « Tout marquer lu » | `POST` | `/commercial/notifications/read-all` | `/promoter/notifications/read-all` |

Les routes sont relatives à `base_url` = `https://<hôte>/api/v1`. Le contrat est **identique** pour les deux apps : une même
couche réseau peut les servir en changeant seulement le préfixe (`commercial` ou `promoter`).

Collections Postman : `docs/postman/commercial/ochantier-commercial.postman_collection.json` (dossier « Notifications ») et
`docs/postman/promoteur/ochantier-promoteur.postman_collection.json` (dossier « Accueil et notifications »), requête
« Nombre de non lues ».

> L’app **Pro** n’a pas encore d’API : le même contrat sera repris quand elle existera.

---

## 1. Compteur : `GET …/notifications/unread-count`

```http
GET /api/v1/commercial/notifications/unread-count HTTP/1.1
Accept: application/json
Authorization: Bearer {access_token}
```

Sans paramètre.

### Réponse `200`

```http
HTTP/1.1 200 OK
Content-Type: application/json
Cache-Control: no-store, private

{"message":null,"data":{"unread_count":3}}
```

| Champ | Type | Description |
|---|---|---|
| `data.unread_count` | int ≥ 0 | Notifications non lues de l’utilisateur connecté, sur **tout l’historique**. Vaut `0` sans notification : jamais `null`. |

- Réponse de quelques dizaines d’octets, calculée par un simple comptage côté serveur : son coût ne dépend pas du nombre de
  notifications.
- `Cache-Control: no-store, private` : ne pas mettre la réponse en cache HTTP ; garder seulement la dernière valeur en mémoire
  pour l’affichage.
- Le nom du champ est `unread_count`.

### Erreurs

Format habituel de l’API : `{"message": "…"}`.

| Code | `message` | Conduite à tenir |
|---|---|---|
| `401` | « Session expirée : reconnectez-vous. » | Rafraîchir le jeton (`POST /auth/refresh`), puis rejouer une fois. |
| `403` | « Votre accès commercial n’est plus actif. Contactez votre responsable O’CHANTIER. » (commercial) ou « Cette fonctionnalité n’est pas disponible pour votre application. » | Ne pas réessayer ; garder le badge masqué. |
| `404` | — | Serveur pas encore à jour : utiliser le repli (section 6). |
| `429` | « Trop de tentatives. Réessayez dans quelques instants. » + `retry_after` (secondes) | Garder la dernière valeur, réessayer après `retry_after`. |
| `5xx` / réseau | — | Garder la dernière valeur connue, réessayer au prochain déclencheur. |

---

## 2. Quand relire le compteur

Pas de relecture périodique (polling). Relire seulement :

1. à la **réception d’un push** O’CHANTIER (app au premier plan ou à l’ouverture depuis la notification) ;
2. au **retour au premier plan** de l’app ;
3. au **retour du réseau** après une coupure ;
4. après la **connexion** ou le changement de compte.

Le push transporte une indication du nombre de non lues (section 3), mais le serveur reste la seule source du compteur : une
notification lue sur un autre appareil fait baisser le compteur sans push, et un push peut être manqué (app fermée,
hors-ligne).

---

## 3. Mises à jour sans relecture

Les réponses de lecture et les push renvoient déjà le compteur à jour : mettre le badge à jour directement, sans appeler
`unread-count`.

### Push reçu

Chaque push O’CHANTIER contient le nombre de non lues du destinataire, **notification envoyée comprise** :

```json
{
  "notification": { "title": "Dossier validé", "body": "Jean Kouassi a été validé(e). Commission de 25 000 FCFA confirmée." },
  "data": {
    "notification_id": "01a1d9a0-4b5c-7d6e-8f90-a1b2c3d4e5f6",
    "event": "worker.validated",
    "target": "{\"type\":\"worker\",\"id\":\"…\",\"prospect_id\":\"…\"}",
    "unread_count": "3"
  },
  "android": { "priority": "high", "notification": { "channel_id": "ochantier_default_v2", "sound": "ochantier_commercial" } },
  "apns": { "headers": { "apns-priority": "10" }, "payload": { "aps": { "badge": 3, "sound": "ochantier_commercial.wav" } } }
}
```

**Son** : chaque app a son son, choisi d’après le type du destinataire (canal Android `ochantier_default_v2`, commun aux
apps) :

| App | Android (`android.notification.sound`) | iOS (`aps.sound`) |
|---|---|---|
| Commercial | `ochantier_commercial` | `ochantier_commercial.wav` |
| Promoteur | `ochantier_promoteur` | `ochantier_promoteur.wav` |

Pour une application inconnue, le son vaut `default` (son système). Un changement de son impose un nouveau canal Android
(`ochantier_default_v3`…) : prévenir le backend, qui modifie `PUSH_ANDROID_CHANNEL`.

| Champ | Description |
|---|---|
| `data.notification_id` | Identifiant de la notification dans le centre de notifications (même `id` que dans la liste). |
| `data.event` | Code de l’événement (`worker.validated`, `proposal.received`…). |
| `data.target` | Écran à ouvrir, **encodé en JSON dans une chaîne** : faire un `JSON.parse` / décodage (types possibles : section 4). |
| `data.unread_count` | Nombre de non lues, **en chaîne** (FCM n’accepte que des chaînes) : convertir en entier. |
| `apns.payload.aps.badge` | Même nombre, en entier : iOS met à jour le badge de l’icône **même app fermée**. |

- **Android** : mettre à jour le badge de la cloche (et de l’icône si le lanceur le gère) avec `data.unread_count`.
- **iOS** : le badge de l’icône est mis à jour par le système ; à l’ouverture de l’app, mettre la cloche à jour avec
  `data.unread_count` ou relire `unread-count`.
- **Au tap sur le push** : ouvrir l’écran de `data.target`, puis marquer la notification lue avec
  `POST …/notifications/{data.notification_id}/read` ; sa réponse donne `unread_count` pour mettre le badge à jour. Si
  `notification_id` est absent (ancien serveur), ne rien marquer.
- C’est une **indication** : la prochaine relecture (section 2) fait foi. Si `unread_count` est absent (ancien serveur) ou
  illisible, relire `unread-count`.
- Après « Tout marquer lu » ou la lecture de toutes les notifications, remettre le badge de l’icône iOS à 0 côté app (aucun
  push n’est envoyé à la lecture).

### Ouvrir une notification : `POST …/notifications/{id}/read`

```json
{ "message": null, "data": { "unread": 2, "unread_count": 2 } }
```

### « Tout marquer lu » : `POST …/notifications/read-all`

```json
{ "message": "Toutes les notifications sont lues", "data": { "unread": 0, "unread_count": 0 } }
```

`message` vaut `null` s’il n’y avait rien à marquer.

`unread` et `unread_count` ont toujours la même valeur. `unread` est conservé pour les anciennes versions de l’app ; les nouvelles
lisent `unread_count`.

### Centre de notifications : `GET …/notifications`

La `meta` de la liste contient le même compteur :

```json
"meta": {
  "unread": 3,
  "unread_count": 3,
  "summary": "3 non lues",
  "tabs": [
    { "key": "toutes", "label": "Toutes", "unread": null },
    { "key": "non_lues", "label": "Non lues", "unread": null },
    { "key": "dossiers", "label": "Dossiers", "unread": 1 },
    { "key": "paiements", "label": "Paiements", "unread": 2 }
  ]
}
```

- `meta.unread_count` porte sur tout l’historique : quand l’écran des notifications est ouvert, mettre le badge à jour avec cette
  valeur.
- La liste n’est **pas paginée** : elle renvoie les **100 notifications les plus récentes** en une seule requête. Ne pas la
  télécharger pour calculer le badge.
- `tabs[].unread` (compteur par onglet) est calculé sur ces 100 notifications ; seul `unread_count` porte sur tout l’historique.
- Onglets promoteur : `all`, `unread`, `offres`, `paiements`, `chantiers` ; onglets commercial : `toutes`, `non_lues`,
  `dossiers`, `paiements`.

### Écran d’accueil

`GET /commercial/home` et `GET /promoter/home` renvoient aussi `data.unread_notifications` (même valeur que `unread_count`) : à
l’ouverture de l’accueil, inutile d’appeler `unread-count` en plus.

---

## 4. Écran à ouvrir : `target`

Chaque notification porte un `target` qui indique l’écran à ouvrir au tap, dans le push (`data.target`) comme dans la liste
(`items[].target`) :

```json
{ "type": "reservation", "id": "01a1…", "proposal_id": "01a1…" }
```

- `type` : écran à ouvrir (tables ci-dessous) ; `id` : élément à afficher, ou `null` pour un écran sans élément précis.
- Des champs en plus précisent l’onglet ou le filtre (`proposal_id`, `date`, `month`, `filter`, `prospect_id`).
- **Push** : `data.target` est une **chaîne JSON** à décoder. **Liste** : `items[].target` est déjà un objet.
- `target` peut être **absent** (push) ou `null` (liste) : message du Back office (`event` = `admin.message`).
- Router sur `type` avec un **cas par défaut** : un type absent ou inconnu ouvre le centre de notifications. De nouveaux types
  pourront s’ajouter sans nouvelle version de l’app.

### App Promoteur

| `type` | `id` | Champs en plus | Événements (`event`) |
|---|---|---|---|
| `reservation` | id de l’offre (réservation) | `proposal_id` | `proposal.received` (ouvriers trouvés) |
| `replacement` | id du remplacement | — | `replacement.proposed` |
| `transaction` | id de la transaction | — | `payment.succeeded` |
| `payments` | `null` | `filter: "en_retard"` (retard uniquement) | `payment.due`, `payment.late`, `payment.regularisation` |
| `site` | id du chantier | — | `site.confirmed`, `site.started`, `site.ended`, `payment.refunded`, décisions du Back office sur le chantier, l’offre et les incidents |
| `report` | id du chantier | — | `report.available` |
| `attendance` | id du chantier | `date` (`AAAA-MM-JJ`) | `attendance.pending` (journée à valider) |
| `subscription_invoice` | id de la facture | — | `subscription.invoice`, `subscription.reminder` |
| `subscription` | id de l’abonnement | — | `subscription.updated` |
| `claim` | id de la réclamation | — | `claim.updated` |
| `dispute` | id du litige | — | `dispute.updated` |
| `rating` | id de l’avis | — | `rating.moderated` |
| `referrals` | `null` | — | `referral.registered` (parrainage) |
| `team` | id du membre de l’équipe | — | `team.joined` |
| `profile` | `null` | — | événements du compte (vérification), message du Back office envoyé depuis la fiche promoteur |

### App Commercial

| `type` | `id` | Champs en plus | Événements (`event`) |
|---|---|---|---|
| `worker` | id de l’ouvrier | `prospect_id` | `worker.validated`, `worker.correction`, `worker.document_rejected` (pièce rejetée, avec son motif), `worker.refused`, `worker.updated` |
| `earnings` | `null` | `month` (`AAAA-MM`, sauf commission confirmée) | `worker.validated` (commission confirmée), `payout.paid`, `adjustment.approved` |
| `prospect` | id du prospect | — | `prospect.follow_up` (relance conseillée) |
| `ranking` | `null` | — | `ranking.published`, `ranking.updated` |
| `home` | `null` | — | `objective.reminder` |
| `profile` | `null` | — | événements du compte (zone, accès…) |

Pour `worker`, un dossier « À corriger » s’ouvre par son prospect (`prospect_id`) ; les autres par
`GET /commercial/workers/{id}`.

---

## 5. Cohérence garantie

Après chaque écriture, toutes les sources renvoient immédiatement la même valeur :

| Action | `unread-count` | `meta.unread_count` | Réponse de l’action |
|---|---|---|---|
| Nouvelle notification | +1 | +1 | — |
| `POST …/{id}/read` sur une non lue | −1 | −1 | `unread_count` à jour |
| `POST …/{id}/read` sur une déjà lue | inchangé | inchangé | `unread_count` inchangé |
| `POST …/read-all` | 0 | 0 | `0` |

---

## 6. Repli pour un serveur pas encore à jour

Tant que `GET …/notifications/unread-count` répond `404`, calculer le badge comme avant, avec `meta.unread` de
`GET …/notifications` (une seule requête, pas de pagination). Dès qu’il répond `200`, l’utiliser. Aucune coordination de
version n’est nécessaire.

---

## 7. Non prévu

- **`ETag` / `304`** sur le compteur : non prévu, la réponse est déjà minime.
