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
| `404` | — | Serveur pas encore à jour : utiliser le repli (section 5). |
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
    "event": "worker.validated",
    "target": "{\"type\":\"worker\",\"id\":\"…\",\"prospect_id\":\"…\"}",
    "unread_count": "3"
  },
  "apns": { "payload": { "aps": { "badge": 3, "sound": "default" } } }
}
```

| Champ | Description |
|---|---|
| `data.event` | Code de l’événement (`worker.validated`, `proposal.received`…). |
| `data.target` | Écran à ouvrir, **encodé en JSON dans une chaîne** : faire un `JSON.parse` / décodage. |
| `data.unread_count` | Nombre de non lues, **en chaîne** (FCM n’accepte que des chaînes) : convertir en entier. |
| `apns.payload.aps.badge` | Même nombre, en entier : iOS met à jour le badge de l’icône **même app fermée**. |

- **Android** : mettre à jour le badge de la cloche (et de l’icône si le lanceur le gère) avec `data.unread_count`.
- **iOS** : le badge de l’icône est mis à jour par le système ; à l’ouverture de l’app, mettre la cloche à jour avec
  `data.unread_count` ou relire `unread-count`.
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

## 4. Cohérence garantie

Après chaque écriture, toutes les sources renvoient immédiatement la même valeur :

| Action | `unread-count` | `meta.unread_count` | Réponse de l’action |
|---|---|---|---|
| Nouvelle notification | +1 | +1 | — |
| `POST …/{id}/read` sur une non lue | −1 | −1 | `unread_count` à jour |
| `POST …/{id}/read` sur une déjà lue | inchangé | inchangé | `unread_count` inchangé |
| `POST …/read-all` | 0 | 0 | `0` |

---

## 5. Repli pour un serveur pas encore à jour

Tant que `GET …/notifications/unread-count` répond `404`, calculer le badge comme avant, avec `meta.unread` de
`GET …/notifications` (une seule requête, pas de pagination). Dès qu’il répond `200`, l’utiliser. Aucune coordination de
version n’est nécessaire.

---

## 6. Non prévu

- **`ETag` / `304`** sur le compteur : non prévu, la réponse est déjà minime.
