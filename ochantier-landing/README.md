# O’CHANTIER · Landing (Laravel)

Landing page publique d’O’CHANTIER servie par Laravel, avec les formulaires branchés sur une base de données.

## Démarrage

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve        # http://localhost:8000
```

## Organisation

| Élément | Emplacement |
|---|---|
| Page (template dc-runtime, entouré de `@verbatim`) | `resources/views/landing.blade.php` |
| Moteur de la page | `public/support.js` |
| Carte interactive (iframe) | `public/carte-chantiers.html` |
| Logos, captures, photos | `public/assets/` |
| Vidéo de garde optionnelle | déposer `public/assets/chantier.mp4` |

La page utilise la syntaxe `{{ … }}` du moteur dc-runtime : tout le contenu de `<x-dc>` doit rester dans le bloc `@verbatim` pour que Blade ne l’interprète pas.

## Formulaires

| Route | Formulaire | Table |
|---|---|---|
| `POST /contact` | Nous contacter | `contact_requests` (référence `OC-AAAA-XXXXX`) |
| `POST /newsletter` | Newsletter du pied de page | `newsletter_subscribers` |
| `POST /ouvriers` | Devenir ouvrier (multipart, photo + CNI) | `worker_applications` (référence `OUV-AAAA-XXXXX`) |

Les pièces d’identité et photos sont stockées sur le disque privé `local` (`storage/app/private/ouvriers/…`), jamais dans `public/`.
Les routes sont protégées par CSRF et limitées à 10 envois par minute et par IP.

## À brancher avant mise en ligne

- Transmission des demandes et candidatures au Back Office / `ochantier-api`
- Liens App Store / Google Play
- Coordonnées réelles (téléphone, e-mail, adresse)

## Tests

```bash
php artisan test
```
