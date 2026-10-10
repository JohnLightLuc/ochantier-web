# O’CHANTIER ADMIN — back-office (HTML / JS statique)

Version statique et organisée de la maquette `design_handoff_backoffice_ochantier/Back Office OChantier.dc.html`.
Aucune étape de build : des fichiers HTML, CSS et JavaScript servis tels quels.
Le rendu et les interactions sont identiques à la maquette (données de démonstration).

## Lancer en local

Les templates sont chargés avec `fetch()`. Il faut donc un serveur statique : l’ouverture directe en `file://` ne marche pas.

```bash
cd ochantier-admin-static
npx serve .                    # ou : python3 -m http.server 8080
```

Ouvrez ensuite `http://localhost:3000` (ou `:8080`). Le site se déploie tel quel sur GitHub Pages, Netlify, un bucket S3, etc.

Dépendances externes (CDN) : React 18 (unpkg, chargé par le runtime), Google Fonts (Source Sans 3, Material Symbols Rounded) et la photo Unsplash de la page de connexion.

## Arborescence

```
ochantier-admin-static/
├── index.html                  # Page unique : CSS, runtime, scripts dans l’ordre
├── assets/
│   ├── css/
│   │   ├── tokens.css          # Design tokens (--c-*, --space-*, --fs-*…) : source de vérité
│   │   └── base.css            # Styles globaux et animations
│   └── vendor/
│       ├── dc-runtime.js       # Moteur de templates basé sur React ({{ }}, <sc-if>, <sc-for>, <dc-import>)
│       └── image-slot.js       # Élément <image-slot> (photo de la page de connexion)
└── src/
    ├── templates/              # Vues HTML
    │   ├── app.html            # Gabarit racine : assemble les partiels via <!-- @include … -->
    │   ├── auth/login.html
    │   ├── layout/             # sidebar, topbar, page-header (actions, filtres, KPI)
    │   ├── shared/liste.html   # Tableau générique paginé (listes de tous les modules)
    │   ├── screens/            # Un fichier par écran ou module
    │   └── overlays/           # Modale, panneau latéral, recherche globale, toast
    ├── components/             # Composants partagés : <nom>.html (vue) + <nom>.js (logique)
    │   └── Avatar, Button, StatCard, StateBlock, StatusBadge
    └── js/
        ├── och.js              # Espace de noms OCH (extend, component, buildApp)
        ├── config.js           # Propriétés de démonstration (états simulés)
        ├── core/               # app (état, cycle de vie), demo-data, helpers, router,
        │                       # forms-modals, layout, render (renderVals)
        ├── modules/            # Un fichier par module métier
        │   ├── dashboard, promoteurs, ouvriers, commerciaux, chantiers, besoins,
        │   ├── affectations, matching, pointages, paiements, finance, avis-litiges,
        │   ├── rapports, abonnements
        │   └── parametres/      # store.js (valeurs publiées et brouillons) + pages.js
        └── main.js             # Démarrage : charge les templates puis monte l’application
```

## Fonctionnement

1. `index.html` charge le runtime (`dc-runtime.js`), qui charge React et expose `window.dcReady`.
2. Chaque fichier de `src/js/core` et `src/js/modules` ajoute une couche à la classe applicative :
   ```js
   OCH.extend(Base => class extends Base {
     // champs et méthodes du module
   });
   ```
   `OCH.buildApp()` empile ces couches dans l’ordre des balises `<script>`. **Cet ordre compte** : les champs de données (`P = this.buildPromos()`, `W = this.buildWorkers()`…) s’initialisent dans cet ordre.
3. `main.js` charge `src/templates/app.html`, remplace chaque `<!-- @include chemin -->` par le partiel correspondant, charge les templates des composants, puis monte l’application dans `#dc-root`.
4. Les templates lisent l’objet renvoyé par `renderVals()` (`src/js/core/render.js`) :
   - `{{ expr }}` : valeur ;
   - `<sc-if value="{{ cond }}">` : affichage conditionnel ;
   - `<sc-for list="{{ items }}" as="x">` : boucle ;
   - `<dc-import name="StatCard" …>` : composant partagé.

## Modifier l’application

- **Une vue** : éditez le partiel concerné dans `src/templates/`. Pour un nouveau partiel, ajoutez une ligne `<!-- @include dossier/fichier.html -->`, seule sur sa ligne, à l’endroit voulu.
- **Une logique de module** : éditez `src/js/modules/<module>.js`. Toutes les méthodes sont accessibles via `this`, quel que soit le fichier qui les définit.
- **Un état de démonstration** (chargement, erreur, aucune donnée…) : changez le `default` voulu dans `src/js/config.js`.
- **Un composant partagé** : `src/components/<Nom>.html` et `<Nom>.js`, déclaré avec `OCH.component()` et chargé dans `index.html`.
- **Le style** : les valeurs viennent de `assets/css/tokens.css`. Les templates utilisent des styles en ligne qui référencent ces variables.

Navigation : routes par hash (`#/`, `#/promoteurs`, `#/ouvriers`, `#/matching`, `#/parametres/<catégorie>`…). L’écran courant est mémorisé dans `localStorage` (`och_screen`). Videz-le pour revenir à l’écran de connexion.
