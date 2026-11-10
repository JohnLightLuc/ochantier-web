# 00 — Architecture du Back Office

## Fonctionnel

### Ce qu’est le Back Office

Le Back Office (dashboard.ochantier.ci) est l’outil interne de l’équipe O’CHANTIER. Il sert à :

- contrôler et valider les comptes : promoteurs, ouvriers et commerciaux ;
- suivre l’activité : chantiers, besoins, matching, affectations et pointages ;
- piloter l’argent : paiements, reversements, abonnements, finance et clôtures ;
- traiter la qualité : avis, réclamations et litiges ;
- administrer la plateforme : paramètres, administrateurs, rôles et journal d’audit.

Les applications mobiles (Promoteur, Commercial) et le Back Office partagent **les mêmes données**, conservées par l’API O’CHANTIER. Une décision prise dans le Back Office (valider un dossier, suspendre un chantier, envoyer une proposition…) est donc visible immédiatement dans les applications, et inversement.

### Organisation de l’écran

- **Barre latérale** : les 15 modules, dans l’ordre de `config/admin.php`. Un compteur (badge) signale le nombre d’éléments en attente dans un module.
- **En-tête** : titre de la page, fil d’Ariane, cloche des notifications (files d’attente de l’administrateur) et menu du compte.
- **Contenu** : en général une **liste** (indicateurs, filtres, recherche, tri, pagination, export CSV, actions groupées), puis une **fiche** organisée en **onglets**.
- **Retours visuels** :
  - une notification éphémère (toast) confirme une action ou explique un refus ;
  - une fenêtre de confirmation précède les actions sensibles ;
  - un panneau latéral affiche un détail sans quitter la liste.

Les compteurs de la barre latérale et de la cloche sont recalculés **au plus une fois par minute**. Un léger décalage avec la liste du module est donc normal.

### Langue et formats

Toute l’interface est en français : URL comprises (`/promoteurs`, `/ouvriers/{id}/pointages`…) et paramètres de filtre (`?statut=valide&tri=inscription`). Le fuseau horaire est `Africa/Abidjan` et les montants sont en francs CFA.

---

## Technique

### Vue d’ensemble

```
Navigateur (React 19 + Inertia v3)
        │  requêtes Inertia (HTML la première fois, puis JSON)
        ▼
ochantier-admin  — Laravel 12, PHP 8.2  (BFF, aucune donnée métier)
        │  HTTP + JWT administrateur (conservé chiffré en session)
        ▼
ochantier-api    — /api/v1/admin/*  (règles métier, permissions, audit)
        │
        ▼
PostgreSQL / PostGIS (Neon en ligne)
```

Le Back Office est un **BFF** (*backend for frontend*) :

- **Il n’a pas de modèle métier.** Sa seule base (SQLite en ligne) contient les tables techniques de Laravel : sessions, cache et files d’attente. Le modèle `App\Models\User` et la table `users` sont des restes du squelette Laravel ; ils ne servent plus à la connexion.
- **Toutes les règles métier vivent dans l’API** : validations, transitions de statut, calculs, permissions, journal d’audit et notifications. Le Back Office traduit les écrans en appels d’API, puis les réponses en données d’affichage.
- **Le navigateur ne parle jamais directement à l’API** et ne voit jamais les jetons.

### Chemin d’une requête

Exemple : l’administrateur ouvre `/promoteurs?statut=soumis`.

1. **Route** (`routes/web.php`) : groupe `['auth', 'password.changed']`, route nommée `promoters.index`.
2. **Middleware**
   - `auth` : le garde `ApiGuard` reconstitue l’administrateur depuis la session et revérifie `GET me` au plus toutes les 60 secondes (voir [01](01-authentification-acces.md)) ;
   - `password.changed` (`EnsurePasswordChanged`) : renvoie vers `/mot-de-passe/nouveau` tant que le mot de passe provisoire n’a pas été remplacé ;
   - `HandleInertiaRequests` : ajoute les données partagées (voir plus bas).
3. **Contrôleur** (`PromoterController::index`)
   - traduit les paramètres français en paramètres d’API (`statut` → `status`, `tri=inscription` → `sort=registered_at`) ;
   - appelle `AdminApi::get('promoters', [...])` ;
   - met en forme chaque ligne avec `PromoterPresenter::row()` ;
   - renvoie `Inertia::render('Promoters/Index', $props)`.
4. **Client d’API** (`App\Support\Api\AdminApi`)
   - ajoute le jeton, `Accept-Language: fr`, le `User-Agent` et `X-Forwarded-For` de l’administrateur ;
   - rafraîchit le jeton s’il a expiré ou après un refus 401 ;
   - convertit les erreurs (voir plus bas).
5. **API** : `auth:admin` → `admin.can:promoters.read` → contrôleur `Api\V1\Admin\Promoter\PromoterController` → services `app/Support/...` → base de données.
6. **Navigateur** : Inertia charge à la demande `resources/js/pages/Promoters/Index.tsx` et l’affiche dans le gabarit `AdminLayout`.

Une **action** (par exemple « Valider le dossier ») suit le même chemin en `POST` :

1. le contrôleur appelle l’endpoint de l’API ;
2. il dépose un message avec `Inertia::flash('toast', …)` ;
3. il renvoie `back()` ou une redirection ;
4. la page se recharge avec les nouvelles données.

### Client d’API : `AdminApi`

`app/Support/Api/AdminApi.php`

| Méthode | Usage |
|---------|-------|
| `get / post / put / patch / delete($uri, $data)` | Appels authentifiés. En `GET`, `$data` devient la query string ; sinon, un corps JSON. |
| `upload($uri, $data, $files)` | Envoi multipart (pièces justificatives). Les champs vides ne sont pas transmis. |
| `guest($method, $uri, $data)` | Appels sans jeton : connexion, mot de passe oublié, activation des commerciaux. |
| `refresh()` | Échange le jeton de rafraîchissement contre une nouvelle paire de jetons. |

**Rafraîchissement concurrent.** Plusieurs requêtes simultanées d’une même session peuvent trouver le jeton expiré en même temps. L’API invalide le jeton de rafraîchissement dès sa première utilisation. `refresh()` évite donc les conflits ainsi :

- il pose un verrou de cache par jeton (`Cache::lock`) ;
- il garde la nouvelle paire chiffrée en cache pendant 30 secondes (`REFRESH_REUSE_SECONDS`) ;
- les requêtes suivantes réutilisent cette paire au lieu d’appeler à nouveau l’API.

**Traitement des erreurs**

| Réponse de l’API | Conversion dans le Back Office | Effet à l’écran |
|------------------|--------------------------------|-----------------|
| 2xx | `Response` | — |
| 401 après un rafraîchissement raté | `AuthenticationException` (jetons oubliés, message flash) | Retour à `/connexion` avec « Votre session a expiré » |
| 422 | `ValidationException` (erreurs de l’API, champ par champ) | Erreurs affichées sous les champs du formulaire |
| 403, 404, 409… | `ApiException` (statut, message, `code` de l’API) | Action : toast avec le message de l’API, sur la page précédente. Affichage d’une page : page d’erreur du statut. |
| `code = password_change_required` | `ApiException::render` | Redirection vers `/mot-de-passe/nouveau` |
| 5xx ou connexion impossible | `ApiException::unavailable()` (503) | « Le service O’CHANTIER est momentanément indisponible… » |

Quelques contrôleurs (chantiers, réclamations) attrapent une `ValidationException` et l’affichent en toast d’erreur avec `Inertia::flash('toastError', …)`, quand le refus ne concerne aucun champ visible.

### Données partagées avec toutes les pages

`app/Http/Middleware/HandleInertiaRequests.php`

| Prop | Contenu |
|------|---------|
| `auth.user` | Nom, e-mail, initiales et libellé du premier rôle de l’administrateur |
| `navigation` | Modules de `config/admin.php` (clé, libellé, icône, chemin, titre, sous-titre) avec leur `badge` |
| `notifications` | Files d’attente affichées dans la cloche, construites par `DashboardPresenter::notifications()` |
| `flash.toast` / `flash.toastError` | Message de la dernière action |

Les badges et les files d’attente viennent de `GET dashboard/badges`. La réponse est gardée **60 secondes en session** (`dashboard.shell`). Si cet appel échoue, la page s’affiche quand même, sans compteurs.

### Conventions côté serveur

| Élément | Emplacement | Rôle |
|---------|-------------|------|
| Contrôleur de lecture | `app/Http/Controllers/{Module}Controller.php` | `index` (liste), `show` (fiche et onglet), `export` (CSV relayé depuis l’API), `legacy` (anciennes URL) |
| Contrôleur de formulaire | `{Module}FormController` | `create`, `store`, `edit`, `update`, `storeDocument` |
| Contrôleur de décision | `{Module}ReviewController`, `{Module}StatusController`, `{Module}DecisionController`, `{Module}AccessController` | Actions qui changent un statut : valider, demander des corrections, refuser, suspendre… |
| Presenter | `app/Support/{Module}Presenter.php` | Traduit une ressource de l’API en données d’affichage : libellés, tons de couleur, dates, montants |
| Onglets | `app/Support/{Module}Tabs.php` | Lit **uniquement l’onglet affiché** (`GET {ressource}/{id}/{onglet}`) et le met en forme |
| En-tête | `Controller::header($title, $subtitle, $crumbs)` | Titre et fil d’Ariane, dans la prop `header` |

Autres conventions :

- **Paramètres d’URL en français.** Chaque contrôleur de liste déclare une table `QUERY` (clé française → paramètre de l’API) et une table `SORTS`.
- **Permissions dans l’interface.** Les contrôleurs calculent une prop `can` (`['create' => …, 'update' => …]`) à partir de `AdminUser::hasPermission()`. Elle sert uniquement à afficher ou masquer les boutons : **le contrôle qui fait foi est celui de l’API** (`admin.can:…`).
- **Chargement différé.** Les données coûteuses et rarement utiles sont déclarées avec `Inertia::optional()` et demandées par la page avec `router.reload({ only: [...] })`. Exemple : les types de pièces, chargés seulement à l’ouverture du formulaire d’ajout de document.
- **Anciennes adresses.** Les URL héritées de la maquette (slugs, onglets pas encore disponibles) mènent à une méthode `legacy`. Celle-ci redirige vers la fiche ou vers une recherche, avec un toast explicatif, plutôt que vers une page 404.
- **Exports CSV.** Le Back Office relaie le corps et l’en-tête `Content-Disposition` de `GET {ressource}/export`. Il accepte les mêmes filtres que la liste, ou une sélection `ids=a,b,c`.

### Conventions côté client

```
resources/js/
├── app.tsx               createInertiaApp : pages chargées à la demande, AdminLayout sauf pour Auth/*
├── layouts/AdminLayout   barre latérale, en-tête, toasts issus du flash ; état replié mémorisé (localStorage och_side)
├── contexts/AdminUiContext  toast(), openPanel(), confirm() partagés par toutes les pages
├── pages/{Module}/       une page Inertia par écran (Index, Show, Form…)
├── components/
│   ├── layout/           Sidebar, Topbar, NotificationPanel
│   ├── list/             ListToolbar, Table, Pagination, ActionStrip (actions groupées)
│   ├── overlays/         ConfirmModal, Dialog, FormDialog, SidePanel, Toast
│   ├── ui/               Button, Field, Icon, Menu, Panel, StatCard, StatusBadge, StateBlock, Avatar, Skeleton
│   ├── blocks/           blocs génériques pilotés par le serveur (voir plus bas)
│   └── {module}/         en-têtes de fiche, onglets et hooks d’actions propres au module
├── lib/                  format.ts (nombres, montants, dates), tone.ts (tons de couleur des statuts)
└── types/                types TypeScript des props
```

- **Styles.** Ils reposent sur les design tokens de `resources/css/tokens.css` (copie de la maquette, qui fait référence) et sur des styles en ligne qui utilisent ces variables. Le projet n’utilise pas Tailwind.
- **Hooks d’actions** (`usePromoterActions`, `useSiteActions`, `useOfferActions`…). Chacun regroupe les actions d’une fiche : confirmation, appel `router.post`, gestion des erreurs.
- **Blocs pilotés par le serveur** (`components/blocks/blocks.tsx`, `types/blocks.ts`). Les écrans de synthèse (rapports, certaines vues de finance et de qualité) reçoivent leur structure du serveur : indicateurs, graphiques, tableaux, étapes. Chaque élément porte une `BlockAction` déclarative (`link`, `download`, `post`, `form`, `confirm`…), que la page exécute.

### Arborescence des modules

| Module | Préfixe d’URL | Contrôleurs du Back Office | Préfixe de l’API |
|--------|---------------|----------------------------|------------------|
| Vue d’ensemble | `/` | `DashboardController` | `dashboard` |
| Promoteurs | `/promoteurs` | `PromoterController`, `PromoterFormController`, `PromoterReviewController` | `promoters` |
| Ouvriers | `/ouvriers` | `WorkerController`, `WorkerFormController`, `WorkerStatusController` | `workers` |
| Commerciaux | `/commerciaux` | `SalesAgentController`, `SalesAgentFormController`, `SalesAgentAccessController`, `SalesRankingController` | `commercials` |
| Chantiers | `/chantiers` | `SiteController`, `SiteDecisionController` | `sites` |
| Besoins | `/besoins` | `NeedController`, `NeedDecisionController` | `offers` |
| Matching | `/matching` | `MatchingController` | `matching` |
| Affectations | `/affectations` | `AssignmentController` | `assignments` |
| Pointages | `/pointages` | `AttendanceController` | `attendance` |
| Paiements | `/paiements` | `PaymentController`, `PayoutController`, `RefundController` | `payments`, `payouts`… |
| Abonnements | `/abonnements` | `SubscriptionController` | `subscriptions`, `subscription-plans` |
| Finance | `/finance-reporting` | `FinanceController`, `ReconciliationController`, `ClosingController` | `finance` |
| Avis et litiges | `/avis-litiges` | `RatingController`, `ClaimController`, `DisputeController` | `ratings`, `claims`, `disputes` |
| Rapports | `/rapports` | `ReportController` | `reports` |
| Paramètres | `/parametres` | `SettingsController`, `AdminAccountController` | `settings`, `admins`, `audit` |

Le détail de chaque module (routes, endpoints, règles) est donné dans son propre document.

### Points d’attention

- **Une route d’API manquante donne une 404 dans le Back Office.** L’API et le Back Office sont déployés séparément. Après une évolution, il faut mettre l’API en ligne **avant** le Back Office.
- **La barre latérale affiche tous les modules**, quelles que soient les permissions. Un administrateur sans droit de lecture qui ouvre un module voit une page d’erreur 403 (refus `permission_denied` de l’API).
- **Le Back Office ne garde rien en mémoire entre deux pages**, en dehors de la session (jetons, profil, compteurs). Toute donnée affichée est relue dans l’API.
- **Les gabarits Blade de `resources/views/exports/`** (rapports et reçus imprimables) ne sont référencés par aucun contrôleur à ce jour. Ce sont des restes de la maquette, à confirmer ou à supprimer lors de la documentation des modules Paiements et Finance.
