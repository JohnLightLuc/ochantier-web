# 01 — Authentification et droits d’accès

## Fonctionnel

### Se connecter

1. **E-mail et mot de passe** sur `/connexion`. La case « Se souvenir de moi » mémorise seulement l’adresse e-mail, pendant 90 jours (cookie `och_email`).
2. **Code de vérification** envoyé par e-mail, à saisir sur `/connexion/verification`. Le code a 6 chiffres par défaut ; l’écran s’adapte à la longueur annoncée par l’API. Le bouton « Renvoyer le code » se débloque après un délai (45 secondes). Si un code a été envoyé il y a moins de 45 secondes, l’API ne l’envoie pas de nouveau et renvoie vers la saisie.
3. **Mot de passe provisoire.** Si le compte en a un, l’administrateur doit le remplacer sur `/mot-de-passe/nouveau` avant d’accéder aux modules.
4. Il arrive ensuite sur la **Vue d’ensemble**, ou sur la page qu’il voulait ouvrir avant la connexion.

### Règles de sécurité

Ces règles sont appliquées par l’API et réglables par variables d’environnement (`config/auth.php`, clé `admin`) :

| Règle | Valeur par défaut |
|-------|-------------------|
| Code par e-mail obligatoire | oui (`ADMIN_MFA_REQUIRED`) |
| Longueur minimale du mot de passe | 12 caractères |
| Verrouillage du compte | après 5 mots de passe erronés, pendant 15 minutes |
| Fermeture d’une session inactive | après 30 minutes sans activité |
| Durée maximale d’une session | 12 heures après la connexion (un rafraîchissement ne la prolonge pas) |
| Sessions simultanées | 2 par compte ; une nouvelle connexion ferme la plus ancienne |
| Restriction par adresse IP | `off`, `alert` (par défaut) ou `block` (voir ci-dessous) |
| Lien de réinitialisation du mot de passe | valable 30 minutes |

**Restriction par adresse IP**

- `alert` : une connexion depuis une adresse hors liste blanche est acceptée, mais inscrite au journal d’audit (`login_unknown_ip`).
- `block` : la connexion et les requêtes sont refusées hors liste blanche (« Connexion impossible depuis ce réseau »), si la liste n’est pas vide.

**Contrôle continu du compte.** Le profil de l’administrateur est revérifié auprès de l’API au plus toutes les minutes. Un compte suspendu, ou une session fermée depuis un autre poste, est donc déconnecté en moins d’une minute.

### Mot de passe oublié

1. Sur `/mot-de-passe-oublie`, l’administrateur saisit son adresse. Le message affiché est le même que l’adresse corresponde à un compte ou non, pour ne pas révéler l’existence d’un compte.
2. Il reçoit un lien vers `/reinitialiser-mot-de-passe?token=…&email=…`, valable 30 minutes.
3. Une fois le nouveau mot de passe enregistré, **toutes ses sessions sont fermées** et il se reconnecte.

Pour un mot de passe provisoire remplacé depuis `/mot-de-passe/nouveau`, seules les **autres** sessions sont fermées.

### Comptes administrateurs

Les comptes sont créés depuis **Paramètres → Utilisateurs** (détail dans le document du module Paramètres) :

- **invitation** par un lien de 72 heures : l’invité choisit son mot de passe sur la page de réinitialisation, et le compte devient actif à ce moment-là ;
- attribution des rôles, suspension, réactivation, désactivation définitive ;
- personne ne modifie son propre accès, et il reste toujours au moins un super administrateur actif.

Le premier super administrateur est créé par le seeder de l’API (`AdminAccessSeeder`) à partir de `ADMIN_EMAIL` et `ADMIN_PASSWORD`.

### Rôles et permissions

Une **permission** combine un module et une action. Exemples :

- `promoters.read`, `promoters.validate` ;
- `payments.approve` ;
- `audit.export`.

Les actions possibles sont `read`, `create`, `update`, `validate`, `approve`, `export` et `delete`. Un administrateur peut avoir plusieurs rôles : ses permissions sont l’union de celles de ses rôles.

**Rôles système** (`ochantier-api/database/seeders/AdminAccessSeeder.php`) :

| Rôle | Périmètre |
|------|-----------|
| **Super administrateur** | Tout, y compris la gestion des administrateurs |
| **Direction** | Consultation et export de tous les modules. Approbations financières (paiements, reversements, finance, gains des commerciaux), paramètres, dérogations de matching, litiges |
| **Opérations** | Contrôle des promoteurs, ouvriers et commerciaux, documents, chantiers, offres, matching (sans approbation), affectations, pointages, avis ; lecture et suivi des litiges ; rapports |
| **Finance** | Paiements, reversements, abonnements, gains des commerciaux, finance (sans validation), rapports ; lecture des données opérationnelles |
| **Support** | Avis, suivi des litiges, support, notifications, contenus d’aide ; lecture des comptes et chantiers |
| **Lecture seule** | Lecture de tous les modules métier, sauf administrateurs, journal d’audit et intégrations |

**Séparation des tâches.** Plusieurs décisions exigent deux personnes différentes : celui qui demande ne peut pas approuver. C’est le cas :

- des ajustements de gains des commerciaux ;
- des dérogations de matching ;
- des corrections de pointage ;
- des taux proposés dans les paramètres.

Chaque module précise ses règles.

### Ce que voit un administrateur sans permission

- Les **boutons** d’action sont masqués quand la permission manque (prop `can`).
- Tous les **modules** restent visibles dans la barre latérale. Ouvrir un module sans droit de lecture affiche une page 403.
- Une action tentée sans droit renvoie le toast « Votre rôle ne permet pas cette action. »

### Activation d’un compte commercial

Il s’agit d’une page **publique** du Back Office, `/activation-commercial?token=…`, utilisée par les **commerciaux** et non par les administrateurs :

1. le commercial reçoit un lien (SMS, et e-mail s’il en a un), valable 72 heures ;
2. il y choisit son mot de passe ;
3. il se connecte ensuite dans l’**application Commercial**, pas dans le Back Office.

Un lien expiré, révoqué ou déjà utilisé affiche une explication. Le lien est généré depuis la fiche du commercial (voir le module Commerciaux).

---

## Technique

### Routes du Back Office

| Route | Nom | Contrôleur | Endpoint de l’API |
|-------|-----|------------|-------------------|
| `GET /connexion` | `login` | `LoginController@create` | — |
| `POST /connexion` | `login.store` | `LoginController@store` | `POST auth/login` |
| `GET /connexion/verification` | `login.otp` | `OtpController@create` | — |
| `POST /connexion/verification` | `login.otp.store` | `OtpController@store` | `POST auth/login/verify` |
| `POST /connexion/verification/renvoi` | `login.otp.resend` | `OtpController@resend` | `POST auth/login/resend` |
| `GET/POST /mot-de-passe-oublie` | `password.request` / `password.email` | `PasswordResetLinkController` | `POST auth/password/forgot` |
| `GET/POST /reinitialiser-mot-de-passe` | `password.reset` / `password.store` | `NewPasswordController` | `POST auth/password/reset` |
| `GET/PUT /mot-de-passe(/nouveau)` | `password.change` / `password.update` | `PasswordController` | `PUT auth/password` |
| `POST /deconnexion` | `logout` | `LoginController@destroy` | `POST auth/logout` |
| `GET/POST /activation-commercial` | `commercial-activation(.store)` | `CommercialActivationController` | `GET/POST auth/commercial-activation` |

Remarques :

- Les `POST` publics sont limités à **6 requêtes par minute** (`throttle:6,1`). L’API applique ses propres limites (`admin-login`, `10,1`, `30,1` pour le rafraîchissement).
- Tous les écrans d’authentification sont rendus par **une seule page React**, `pages/Auth/Login.tsx`, avec une prop `step` : `login`, `otp`, `forgot`, `sent`, `reset`, `change` ou `activation`. Ils n’utilisent pas `AdminLayout`.
- Les adresses des liens envoyés par e-mail ou SMS sont fixées **côté API** : `ADMIN_PASSWORD_RESET_URL` et `COMMERCIAL_ACTIVATION_URL`. Elles doivent pointer vers le domaine du Back Office.
- Les appels sans jeton (`AdminApi::guest`) restent sous le préfixe `api/v1/admin`. Le Back Office ne connaît que cette URL de base : c’est pourquoi l’activation des commerciaux est exposée en `admin/auth/commercial-activation`.

### Séquence de connexion

```
Navigateur            Back Office (BFF)                    API
   │ POST /connexion        │                                 │
   │───────────────────────▶│ POST auth/login                 │
   │                        │  {email, password, device_name, │
   │                        │   device_id}                    │
   │                        │────────────────────────────────▶│ IP, verrouillage, mot de passe,
   │                        │◀──── {mfa_required, destination,│ statut du compte, envoi du code
   │                        │       resend_in, code_length}   │
   │                        │ session login.pending = {...}   │
   │◀── redirection ────────│ /connexion/verification         │
   │ POST code              │                                 │
   │───────────────────────▶│ POST auth/login/verify          │
   │                        │────────────────────────────────▶│ vérification du code, ouverture
   │                        │◀── {access_token, refresh_token,│ de la session (AdminSessions)
   │                        │     expires_in, session_id,     │
   │                        │     admin}                      │
   │                        │ régénère l’id de session, puis  │
   │                        │ ApiTokens::store()              │
   │◀── redirection intended(/) ──────────────────────────────│
```

- **Appareil.** `device_id` est un UUID stable par navigateur, conservé 5 ans dans le cookie `och_device`. `device_name` est un libellé lisible (« Chrome · macOS ») calculé par `BrowserDevice::describe()`. L’API s’en sert pour la liste des sessions.
- **Refus présentés comme erreurs de champ.** `ApiAuthController::failAs()` transforme les refus de l’API (compte verrouillé, attente avant renvoi, réseau refusé…) en erreur du champ concerné, au lieu d’un toast.
- **Connexion sans code.** Si `ADMIN_MFA_REQUIRED=false`, l’API renvoie directement les jetons et l’étape du code est sautée.

### Jetons et session : `ApiTokens`

Toutes les données sont conservées dans la clé de session `ochantier_api` :

| Champ | Contenu |
|-------|---------|
| `access_token` | JWT d’accès **chiffré** (`Crypt::encryptString`), valable 15 minutes (`JWT_ACCESS_TTL` côté API) |
| `refresh_token` | Jeton de rafraîchissement **chiffré**, à usage unique |
| `expires_at` | Échéance du jeton d’accès, moins une marge de 30 secondes |
| `session_id` | Identifiant de la session ouverte dans l’API |
| `admin` | Profil renvoyé par l’API : identité, `roles`, `permissions`, `must_change_password` |
| `checked_at` | Date de la dernière vérification du profil |

Les jetons ne sortent jamais du serveur. Les sessions Laravel sont stockées en base (`SESSION_DRIVER=database`) avec une durée de 120 minutes. C’est toutefois la politique de l’API qui décide en pratique (30 minutes d’inactivité, 12 heures maximum).

### Garde `ApiGuard`

`config/auth.php` déclare le garde `web` avec le pilote `ochantier-api`, enregistré dans `AppServiceProvider::boot()` par `Auth::extend`.

À chaque requête, `ApiGuard::user()` procède ainsi :

1. sans jetons en session, il renvoie `null` (redirection vers `/connexion` par le middleware `auth`) ;
2. si le profil a été vérifié il y a **plus de 60 secondes**, il appelle `GET me` et met le profil à jour :
   - refus 4xx : les jetons sont oubliés et l’administrateur est déconnecté ;
   - API indisponible (5xx) : **le dernier profil connu est conservé**, ce qui évite une déconnexion pendant une panne ;
3. il construit un `AdminUser` (un `GenericUser`) à partir du profil.

`AdminUser` expose les méthodes suivantes :

- `permissions()` ;
- `hasPermission($code)` ;
- `mustChangePassword()` ;
- les attributs `name` (`full_name`) et `role` (libellé du premier rôle).

La méthode `validate()` du garde renvoie toujours `false` : les identifiants sont vérifiés uniquement par l’API.

### Rafraîchissement et expiration

Le mécanisme est décrit dans [00 — Architecture](00-architecture.md#client-dapi--adminapi). Côté API, `AdminSessions::refresh()` refuse un jeton de rafraîchissement dans les cas suivants :

- jeton inconnu ou déjà utilisé ;
- session expirée (12 heures) ou inactive (30 minutes) ;
- compte qui ne peut plus se connecter.

Le Back Office oublie alors les jetons et renvoie vers la connexion avec le message « Votre session a expiré. Reconnectez-vous. ».

### Mot de passe provisoire : double contrôle

- **Back Office.** Le middleware `password.changed` (`EnsurePasswordChanged`) redirige vers `password.change` quand `must_change_password` vaut vrai.
- **API.** Son propre middleware `password.changed` répond `code: password_change_required` sur toutes les routes, sauf `me`, `auth/logout` et `auth/password`. `ApiException::render()` transforme ce code en redirection vers `password.change`.

Après `PUT auth/password`, le profil renvoyé par l’API remplace celui de la session (`ApiTokens::refreshAdmin`), ce qui lève le blocage sans attendre la vérification suivante.

### Déconnexion

`LoginController@destroy` procède en trois temps :

1. il appelle `POST auth/logout`, qui ferme la session dans l’API ; une erreur à cette étape est ignorée ;
2. il appelle `Auth::logout()`, qui oublie les jetons ;
3. il invalide la session Laravel et régénère le jeton CSRF.

### Permissions : où elles s’appliquent

| Niveau | Mécanisme | Effet |
|--------|-----------|-------|
| API (fait foi) | middleware `admin.can:{permission}[,{autre}]` sur chaque route (`EnsureAdminPermission`) ; une seule des permissions listées suffit | 403 `permission_denied` |
| API (règles fines) | services métier : par exemple, un demandeur ne peut pas être approbateur, et un compte ne modifie pas son propre accès | 422 ou 409 avec un message |
| Back Office | prop `can` calculée par chaque contrôleur avec `AdminUser::hasPermission()` | boutons masqués |

Les permissions affichées par le Back Office proviennent du profil mis en cache (60 secondes au plus). Un rôle retiré peut donc laisser un bouton visible quelques secondes ; l’API refuse alors l’action.

### Journal des connexions

L’API enregistre chaque tentative de connexion avec son motif d’échec :

- `wrong_password`, `unknown_account` ;
- `ip_not_allowed` ;
- `account_{statut}`.

Les connexions depuis une adresse IP inconnue sont inscrites au journal d’audit (`AdminAudit`). Le Back Office relaie l’adresse IP réelle de l’administrateur dans l’en-tête `X-Forwarded-For`. Pour que l’API la prenne en compte, l’adresse du serveur du Back Office doit figurer dans `TRUSTED_PROXIES` côté API.

### Tests

| Fichier | Couverture |
|---------|------------|
| `tests/Feature/AuthenticationTest.php` | Écrans de connexion, code (longueur, renvoi), mot de passe oublié et réinitialisation, mot de passe provisoire, rafraîchissement à l’expiration, session fermée dans l’API, profil conservé pendant une panne, déconnexion |
| `tests/Feature/AdminApiTest.php` | Jeton transmis, rafraîchissement après un 401 puis nouvel essai, conversion des erreurs (formulaire, toast, page d’erreur), redirection `password_change_required` |
| `tests/Feature/ModuleNavigationTest.php` | Chaque module de la barre latérale a une page ; chemins inconnus en 404 |
| `tests/Feature/SalesAgentsTest.php` | Page publique d’activation des commerciaux |

La réutilisation d’une paire de jetons par des requêtes simultanées (verrou et cache de 30 secondes) n’est pas couverte par un test.

### Points d’attention

- **Environnement de préproduction.** En ligne, `OTP_TEST_CODE` est défini côté API (code fixe) et `APP_ENV` n’est pas `production`. Avant le lancement réel, il faut passer `APP_ENV=production` et supprimer `OTP_TEST_CODE`.
- **Hachage des mots de passe.** Un hachage de mot de passe créé hors de Laravel, avec le préfixe `$2a$`, provoque une erreur 500 à la connexion. Il faut toujours créer ou modifier les mots de passe par le seeder ou par l’API.
- **Sessions ouvertes.** L’API expose `GET auth/sessions` et `DELETE auth/sessions/{id}` (sessions ouvertes du compte), mais le Back Office n’a pas encore d’écran pour les afficher.
