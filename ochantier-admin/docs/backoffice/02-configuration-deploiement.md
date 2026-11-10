# 02 — Configuration, tests et déploiement

## Fonctionnel

| Environnement | Back Office | API |
|---------------|-------------|-----|
| Local | `http://localhost:8000` | `http://localhost:8001/api/v1/admin` |
| En ligne (préproduction) | `https://dashboard.ochantier.ci` | `https://api.ochantier.ci/api/v1/admin` (base Neon) |

- **Déploiements séparés.** Le Back Office et l’API sont mis en ligne indépendamment. Une nouveauté qui touche les deux doit être déployée **d’abord dans l’API**. Sinon, l’écran concerné du Back Office répond par une page 404 ou par « service indisponible ».
- **Préproduction.** L’environnement en ligne n’est pas encore en mode production (voir [Points d’attention](#points-dattention)).

---

## Technique

### Variables d’environnement du Back Office

Seules les variables propres au projet, ou dont la valeur compte, sont listées ici. Les autres sont celles de Laravel par défaut (`.env.example`).

| Variable | Valeur locale | Rôle |
|----------|---------------|------|
| `APP_NAME` | `O’CHANTIER ADMIN` | Titre des onglets du navigateur (repris par `VITE_APP_NAME`) |
| `APP_ENV` | `local` | `staging` en ligne aujourd’hui ; `production` au lancement |
| `APP_KEY` | générée | **Chiffre les jetons de l’API en session.** La changer déconnecte tout le monde. |
| `APP_URL` | `http://localhost:8000` | Adresse du Back Office |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `fr` | Langue des messages de validation |
| `OCHANTIER_API_URL` | `http://localhost:8001/api/v1/admin` | URL de base de l’API, **préfixe `api/v1/admin` compris** |
| `OCHANTIER_API_TIMEOUT` | `10` | Délai maximal d’un appel à l’API, en secondes. Au-delà, l’API est considérée comme indisponible (503). |
| `DB_CONNECTION` | `sqlite` | Base **technique** uniquement : sessions, cache, files d’attente |
| `SESSION_DRIVER` / `SESSION_LIFETIME` | `database` / `120` | Session Laravel qui porte les jetons |
| `CACHE_STORE` | `database` | Verrous et cache de 30 secondes du rafraîchissement des jetons |

Côté **API**, quatre réglages concernent directement le Back Office :

| Variable de l’API | Rôle |
|-------------------|------|
| `ADMIN_PASSWORD_RESET_URL` | Lien de réinitialisation envoyé aux administrateurs (`{Back Office}/reinitialiser-mot-de-passe`) |
| `COMMERCIAL_ACTIVATION_URL` | Lien d’activation envoyé aux commerciaux (`{Back Office}/activation-commercial`) |
| `TRUSTED_PROXIES` | Doit inclure l’adresse du serveur du Back Office, pour que l’adresse IP relayée (`X-Forwarded-For`) soit prise en compte |
| `ADMIN_MFA_REQUIRED`, `ADMIN_SESSION_*`, `ADMIN_MAX_SESSIONS`, `ADMIN_IP_RESTRICTION`, `AUTH_MAX_ATTEMPTS`, `AUTH_LOCKOUT_MINUTES`, `OTP_TEST_CODE` | Politique de connexion (voir [01](01-authentification-acces.md#règles-de-sécurité)) |

La liste des modules de la barre latérale n’est pas une variable : elle est définie dans `config/admin.php`.

### Démarrer en local

**1. Lancer l’API** (`ochantier-api`) sur le port 8001.

> ⚠️ Le `.env` local de l’API pointe vers la base **en ligne** (Neon). N’y lancez jamais `php artisan migrate` ni de seeder sans vous être assuré de la base ciblée.

**2. Lancer le Back Office** :

```bash
cd ochantier-admin
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate   # tables techniques uniquement
composer run dev                                        # Laravel (port 8000) + Vite
```

Pour se connecter, utilisez un administrateur créé côté API (seeder `AdminAccessSeeder`, ou comptes de démonstration).

### Vérifications avant de livrer

```bash
npm run types                       # TypeScript (tsc --noEmit)
npm run build                       # bundle Vite → public/build
vendor/bin/pint --dirty --format agent
php artisan test --compact          # utiliser PHP 8.2 : /opt/homebrew/opt/php@8.2/bin/php
```

### Tests

- Il y a **un test de fonctionnalité par module** dans `tests/Feature/`, plus `AuthenticationTest`, `AdminApiTest` et `ModuleNavigationTest`.
- **Aucun appel réel à l’API.** `tests/TestCase.php` fixe l’URL à `http://api.test/api/v1/admin` et appelle `Http::preventStrayRequests()`. Chaque test simule les réponses de l’API avec `Http::fake([...])`, et une requête non simulée fait échouer le test.
- Les URL simulées doivent être **exactes**, préfixe compris. Une route d’API renommée doit être mise à jour dans les tests.
- Le schéma de session de l’API est simulé avec `ApiTokens::store([...])`. Voir `withApiSession()` dans `AdminApiTest`.

Les règles métier sont testées **dans l’API** (`ochantier-api/tests`). Les tests du Back Office vérifient seulement trois choses :

- la traduction entre écrans et appels d’API (paramètres, endpoints) ;
- la mise en forme (Presenters) ;
- les redirections et les messages.

### Déploiement en ligne (cPanel)

**Emplacements**

| Élément | Chemin sur le serveur (compte `ochanti1`) |
|---------|------------------------------------------|
| Dépôt cloné | `/home/ochanti1/repositories/ochantier-admin-web` |
| Application | `…/ochantier-admin-web/ochantier-admin` (`APPDIR`) |
| Dossier servi par le sous-domaine | `/home/ochanti1/dashboard.ochantier.ci` (`DOCROOT`) : un `index.php` relais charge Laravel depuis `APPDIR` |
| Base technique | `ochantier-admin/database/database.sqlite`, non versionnée, créée à la main |

**Tâches de `.cpanel.yml`.** Le fichier est à la racine du dépôt, car cPanel ignore les sous-dossiers. Ses tâches s’exécutent dans cet ordre :

1. suppression de `public/hot`, sinon Vite pointerait vers un serveur de développement ;
2. `composer install --no-dev --optimize-autoloader` avec PHP 8.2 (`ea-php82`) ;
3. `php artisan migrate --force` (tables techniques) ;
4. `php artisan optimize:clear` puis `php artisan optimize` (caches de configuration, routes et vues) ;
5. dans `DOCROOT`, `build` devient un **lien symbolique** vers `APPDIR/public/build` ; `favicon.ico` et `robots.txt` sont copiés ; `storage` devient un lien vers `storage/app/public`.

**Mettre en ligne**

1. Construire les fichiers statiques (`npm run build`) et les committer : `public/build` est servi tel quel, et le serveur ne lance pas npm.
2. Pousser sur le dépôt distant.
3. Dans cPanel → Git Version Control :
   - **Update from Remote** récupère le code, mais **n’exécute aucune tâche** ;
   - **Deploy HEAD Commit** exécute `.cpanel.yml`. Cette étape est indispensable.
4. En cas d’erreur, consulter les journaux du déploiement (`~/.cpanel/logs`) et ceux de Laravel (`storage/logs`, lignes `staging.ERROR`).

**Diagnostics fréquents**

| Symptôme | Cause probable |
|----------|----------------|
| Page blanche, fichiers JS ou CSS en 404 | `build/manifest.json` servi différent de celui du dépôt : build non committé, ou lien `build` cassé |
| Une page du Back Office en 404 | La route n’existe pas encore dans l’API déployée |
| « Service momentanément indisponible » | API injoignable, ou délai dépassé (`OCHANTIER_API_TIMEOUT`) |
| Erreur 500 à la connexion | Hachage de mot de passe administrateur invalide (préfixe `$2a$`) côté API |
| Tous les administrateurs déconnectés après un déploiement | `APP_KEY` modifiée : les jetons chiffrés en session sont illisibles |

### Points d’attention

- **Avant le lancement réel :**
  - côté API, passer `APP_ENV=production` et supprimer `OTP_TEST_CODE` ;
  - côté Back Office, passer `APP_ENV=production` et `APP_DEBUG=false`.
- **Pas de tâche planifiée.** Le Back Office n’a ni tâche planifiée ni file d’attente métier : `routes/console.php` ne contient que la commande d’exemple de Laravel. Les traitements automatiques (levée des suspensions, rappels, expirations) sont planifiés **dans l’API**.
- **Fichier obsolète.** `docs/database-schema.md` décrit l’ancien schéma local, supprimé par `2026_11_07_000000_drop_demo_tables`.
