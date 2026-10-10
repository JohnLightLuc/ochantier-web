# O’CHANTIER ADMIN — Laravel + Inertia + React

Back-office de production d’O’CHANTIER. Les écrans sont migrés depuis la maquette
`../ochantier-admin-static/` (référence de design, données fictives).

## Stack

- Laravel 13 (PHP 8.3), Inertia v3 (`inertiajs/inertia-laravel`, `@inertiajs/react`)
- React 19 + TypeScript, Vite
- Styles : design tokens `resources/css/tokens.css` (copie de la maquette, source de vérité) — pas de Tailwind
- Locale `fr`, fuseau `Africa/Abidjan`

## Démarrer

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
composer run dev        # lance Laravel et Vite (php artisan dev)
```

## Arborescence front

```
resources/
├── css/app.css, tokens.css
├── js/app.tsx            # createInertiaApp
├── js/pages/             # une page Inertia par écran (Inertia::render('Dashboard'))
└── views/app.blade.php   # vue racine
```

## Vérifications

```bash
npm run types   # tsc --noEmit
npm run build
php artisan test
```
