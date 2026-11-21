<?php

namespace App\Support\Landing;

/**
 * Contenu éditable du site public O’CHANTIER : 12 sections, leurs champs (type, limites, aide) et les textes de la
 * version 1, repris du site. Le Back Office construit l’éditeur à partir de ce schéma et l’API valide le brouillon
 * avec. Le contenu a la forme {order, hidden, sections: {clé: {champ: valeur}}}.
 */
class LandingContentSchema
{
    /**
     * Section => [libellé, icône, description].
     */
    public const SECTIONS = [
        'cover' => ['Page de garde', 'wallpaper', 'Écran plein format affiché à l’ouverture du site.'],
        'header' => ['En-tête & menu', 'menu', 'Logo, liens du menu et boutons d’action.'],
        'demo' => ['Accueil · Démo', 'smartphone', 'Accroche promoteurs et démo interactive de l’application.'],
        'solution' => ['La solution', 'widgets', 'Les fonctionnalités de la plateforme.'],
        'paiement' => ['Paiement', 'lock', 'Fonctionnement du paiement sécurisé.'],
        'tarifs' => ['Tarifs', 'sell', 'Formules d’abonnement promoteur, reprises du module Abonnements.'],
        'carte' => ['Carte des chantiers', 'map', 'Chantiers qui recrutent, visibles par les ouvriers.'],
        'app' => ['Application', 'install_mobile', 'Liens de téléchargement et QR code.'],
        'contact' => ['Nous contacter', 'mail', 'Coordonnées et formulaire de contact.'],
        'newsletter' => ['Newsletter', 'campaign', 'Inscription à la lettre d’information, dans le pied de page.'],
        'footer' => ['Pied de page', 'bottom_navigation', 'Slogan, coordonnées et mentions.'],
        'seo' => ['Référencement', 'travel_explore', 'Titre, description et image de partage de la page.'],
    ];

    /**
     * Sections du corps de page, dont l’ordre se règle et qui peuvent être masquées.
     */
    public const MIDDLE = ['demo', 'solution', 'paiement', 'tarifs', 'carte', 'app', 'contact'];

    /**
     * Sections qui peuvent être masquées (la page de garde en plus du corps de page).
     */
    public const HIDEABLE = ['cover', 'demo', 'solution', 'paiement', 'tarifs', 'carte', 'app', 'contact'];

    /**
     * Fichiers acceptés par type de champ fichier : [types MIME, taille maximale en Ko].
     */
    public const FILE_RULES = [
        'video/mp4' => [['video/mp4'], 20480],
        'image/*' => [['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'], 4096],
    ];

    /**
     * Champs d’une section. Types : text, area, url, tel, email, toggle, file, lines (liste de textes), items (liste
     * d’éléments à sous-champs), select.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(string $section): array
    {
        $text = fn (string $key, string $label, array $o = []) => ['key' => $key, 'label' => $label, 'type' => 'text', ...$o];
        $area = fn (string $key, string $label, array $o = []) => $text($key, $label, ['type' => 'area', 'rows' => 3, ...$o]);
        $kick = $text('kick', 'Surtitre', ['max' => 70]);
        $title = $area('title', 'Titre', ['required' => true, 'max' => 90, 'rows' => 2]);
        $body = $area('text', 'Texte', ['max' => 320]);
        $targets = array_map(fn (string $key) => ['value' => $key, 'label' => self::SECTIONS[$key][0]], self::MIDDLE);

        return match ($section) {
            'cover' => [
                $kick,
                $text('title', 'Titre', ['required' => true, 'max' => 60]),
                $text('accent', 'Fin du titre (en vert)', ['max' => 40]),
                $area('text', 'Texte d’accroche', ['max' => 180, 'rows' => 2]),
                $text('cta', 'Bouton principal', ['required' => true, 'max' => 30]),
                $text('live', 'Indicateur en direct', ['max' => 40, 'hint' => 'Affiché en haut à droite, avec un point vert clignotant.']),
                ['key' => 'video', 'label' => 'Vidéo de fond', 'type' => 'file', 'accept' => 'video/mp4', 'hint' => 'MP4, 20 Mo max. Sans vidéo, la vidéo actuelle du site est affichée.'],
            ],
            'header' => [
                ['key' => 'logo', 'label' => 'Logo', 'type' => 'file', 'accept' => 'image/*', 'hint' => 'Sans fichier, le logo actuel du site est affiché.'],
                ['key' => 'nav', 'label' => 'Liens du menu', 'type' => 'items', 'min' => 1, 'max' => 6, 'add' => 'Ajouter un lien', 'sub' => [
                    $text('label', 'Libellé', ['required' => true, 'max' => 30]),
                    ['key' => 'section', 'label' => 'Section cible', 'type' => 'select', 'required' => true, 'options' => $targets],
                ]],
                $text('cta_worker', 'Bouton « inscription ouvrier »', ['required' => true, 'max' => 24, 'hint' => 'Ouvre le formulaire d’inscription ouvrier.']),
                $text('cta_app', 'Bouton principal', ['required' => true, 'max' => 24, 'hint' => 'Renvoie vers la section Application.']),
            ],
            'demo' => [
                $kick, $title,
                $text('highlight', 'Mot surligné en jaune', ['max' => 30, 'hint' => 'Doit figurer tel quel dans le titre.']),
                $body,
                ['key' => 'steps', 'label' => 'Étapes de la démo', 'type' => 'items', 'min' => 4, 'max' => 4, 'hint' => 'Les 4 étapes suivent les écrans de la démo : seul leur texte se modifie.', 'sub' => [
                    $text('title', 'Titre', ['required' => true, 'max' => 40]),
                    $text('text', 'Description', ['max' => 70]),
                ]],
            ],
            'solution' => [
                $kick, $title, $body,
                ['key' => 'features', 'label' => 'Fonctionnalités', 'type' => 'items', 'min' => 3, 'max' => 8, 'add' => 'Ajouter une fonctionnalité', 'sub' => [
                    $text('title', 'Titre', ['required' => true, 'max' => 50]),
                    $area('text', 'Description', ['required' => true, 'max' => 200, 'rows' => 2]),
                    ['key' => 'core', 'label' => 'Badge « Cœur de métier »', 'type' => 'toggle'],
                ]],
            ],
            'paiement' => [
                $kick, $title, $body,
                ['key' => 'chips', 'label' => 'Étiquettes', 'type' => 'lines', 'max' => 4, 'item_max' => 40, 'add' => 'Ajouter une étiquette'],
                ['key' => 'steps', 'label' => 'Étapes du paiement', 'type' => 'items', 'min' => 2, 'max' => 4, 'add' => 'Ajouter une étape', 'sub' => [
                    $text('title', 'Titre', ['required' => true, 'max' => 40]),
                    $area('text', 'Description', ['max' => 120, 'rows' => 2]),
                ]],
            ],
            'tarifs' => [$kick, $title, $body],
            'carte' => [$kick, $title, $body, $text('cta', 'Bouton', ['required' => true, 'max' => 30])],
            'app' => [
                $kick, $title, $body,
                $text('ios', 'Lien App Store', ['type' => 'url', 'max' => 255, 'placeholder' => 'https://apps.apple.com/…']),
                $text('android', 'Lien Google Play', ['type' => 'url', 'max' => 255, 'placeholder' => 'https://play.google.com/store/apps/…']),
                ['key' => 'qr', 'label' => 'QR code de téléchargement', 'type' => 'file', 'accept' => 'image/*', 'hint' => 'Sans fichier, le QR code actuel du site est affiché.'],
            ],
            'contact' => [
                $kick, $title, $body,
                $text('phone', 'Téléphone', ['type' => 'tel', 'required' => true, 'max' => 30, 'placeholder' => '+225 07 00 00 00 00']),
                $text('email', 'E-mail', ['type' => 'email', 'required' => true, 'max' => 120]),
                $text('address', 'Adresse', ['max' => 80]),
                $text('hours', 'Horaires', ['max' => 60]),
                ['key' => 'profiles', 'label' => 'Profils proposés (« Vous êtes »)', 'type' => 'lines', 'min' => 1, 'max' => 8, 'item_max' => 40, 'add' => 'Ajouter un profil'],
                ['key' => 'subjects', 'label' => 'Objets de la demande', 'type' => 'lines', 'min' => 1, 'max' => 10, 'item_max' => 80, 'add' => 'Ajouter un objet', 'hint' => 'Les messages reçus sont classés selon ces objets.'],
            ],
            'newsletter' => [
                $text('title', 'Titre', ['max' => 40]),
                $area('text', 'Texte', ['max' => 160, 'rows' => 2]),
                $text('placeholder', 'Texte du champ e-mail', ['required' => true, 'max' => 40]),
                $text('cta', 'Bouton', ['required' => true, 'max' => 24]),
                $text('notice', 'Mention sous le formulaire', ['max' => 120]),
                $text('success', 'Message après inscription', ['required' => true, 'max' => 120]),
            ],
            'footer' => [
                $text('slogan', 'Slogan', ['max' => 80]),
                $text('phone', 'Téléphone', ['type' => 'tel', 'max' => 30, 'placeholder' => '+225 07 00 00 00 00']),
                $text('email', 'E-mail', ['type' => 'email', 'max' => 120]),
                $text('legal', 'Mention de copyright', ['required' => true, 'max' => 80]),
                $text('legal_url', 'Lien « Mentions légales »', ['type' => 'url', 'max' => 255, 'placeholder' => 'https://…']),
                $text('terms_url', 'Lien « CGU »', ['type' => 'url', 'max' => 255, 'placeholder' => 'https://…']),
            ],
            'seo' => [
                $text('title', 'Titre de la page', ['required' => true, 'max' => 70]),
                $area('description', 'Méta-description', ['required' => true, 'max' => 160, 'hint' => 'Entre 70 et 160 caractères pour un affichage complet.']),
                ['key' => 'og_image', 'label' => 'Image de partage', 'type' => 'file', 'accept' => 'image/*', 'hint' => '1200 × 630 px, affichée lors des partages WhatsApp, Facebook et LinkedIn.'],
                ['key' => 'indexable', 'label' => 'Indexation par les moteurs de recherche', 'type' => 'toggle'],
            ],
            default => [],
        };
    }

    /**
     * Schéma complet, pour le Back Office.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, hideable: bool, movable: bool, fields: list<array<string, mixed>>}>
     */
    public static function describe(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::SECTIONS[$key][0],
            'icon' => self::SECTIONS[$key][1],
            'description' => self::SECTIONS[$key][2],
            'hideable' => in_array($key, self::HIDEABLE, true),
            'movable' => in_array($key, self::MIDDLE, true),
            'fields' => self::fields($key),
        ], array_keys(self::SECTIONS));
    }

    /**
     * Règles de validation des valeurs d’une section.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $section): array
    {
        $rules = [];

        foreach (self::fields($section) as $field) {
            $rules = [...$rules, ...self::fieldRules($field, $field['key'])];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, list<mixed>>
     */
    private static function fieldRules(array $field, string $path): array
    {
        $max = isset($field['max']) ? ['max:'.$field['max']] : [];

        return match ($field['type']) {
            'toggle' => [$path => ['present', 'boolean']],
            'file' => [$path => ['present', 'nullable', 'uuid']],
            'url' => [$path => ['present', 'nullable', 'url:https,http', ...$max]],
            'email' => [$path => ['present', 'nullable', 'email', ...$max]],
            'select' => [$path => ['required', 'in:'.implode(',', array_column($field['options'], 'value'))]],
            'lines' => [
                $path => ['present', 'array', ...(isset($field['min']) ? ['min:'.$field['min']] : []), 'max:'.$field['max']],
                "{$path}.*" => ['required', 'string', 'max:'.($field['item_max'] ?? 120)],
            ],
            'items' => array_merge(
                [$path => ['present', 'array', 'min:'.($field['min'] ?? 0), 'max:'.$field['max']]],
                ...array_map(fn (array $sub) => self::fieldRules($sub, "{$path}.*.{$sub['key']}"), $field['sub']),
            ),
            default => [$path => ['present', 'nullable', 'string', ...$max]],
        };
    }

    /**
     * Valeurs d’une section limitées à ses champs (les clés inconnues sont écartées, les absentes complétées).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function clean(string $section, array $values): array
    {
        return self::cleanFields(self::fields($section), $values);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function cleanFields(array $fields, array $values): array
    {
        $out = [];

        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? null;
            $out[$field['key']] = match ($field['type']) {
                'toggle' => (bool) $value,
                'lines' => array_values(array_map(fn ($line) => trim((string) $line), (array) $value)),
                'items' => array_values(array_map(fn ($item) => self::cleanFields($field['sub'], (array) $item), (array) $value)),
                default => is_string($value) && trim($value) !== '' ? trim($value) : null,
            };
        }

        return $out;
    }

    /**
     * Identifiants des fichiers (media_files) cités par un contenu.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function fileIds(array $content): array
    {
        $ids = [];

        foreach (array_keys(self::SECTIONS) as $section) {
            foreach (self::fields($section) as $field) {
                if ($field['type'] === 'file' && is_string($id = $content['sections'][$section][$field['key']] ?? null)) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Sections dont le contenu diffère entre deux versions (l’ordre et l’affichage comptent sous la clé « layout »).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return list<string>
     */
    public static function changes(array $a, array $b): array
    {
        $changed = array_values(array_filter(array_keys(self::SECTIONS), fn (string $key) => ($a['sections'][$key] ?? null) != ($b['sections'][$key] ?? null)));

        if ($a['order'] !== $b['order'] || array_values(array_diff($a['hidden'], $b['hidden'])) !== [] || array_values(array_diff($b['hidden'], $a['hidden'])) !== []) {
            $changed[] = 'layout';
        }

        return $changed;
    }

    /**
     * Libellé d’une clé de modification.
     */
    public static function label(string $key): string
    {
        return $key === 'layout' ? 'Ordre et affichage des sections' : (self::SECTIONS[$key][0] ?? $key);
    }

    /**
     * Contrôles avant publication : erreurs (bloquantes) et points d’attention.
     *
     * @param  array<string, mixed>  $content
     * @return list<array{level: 'error'|'warning', section: string, message: string}>
     */
    public static function checks(array $content): array
    {
        $checks = [];
        $s = $content['sections'];

        foreach (array_keys(self::SECTIONS) as $section) {
            if (in_array($section, $content['hidden'], true)) {
                continue;
            }

            foreach (self::fields($section) as $field) {
                $value = $s[$section][$field['key']] ?? null;

                if (($field['required'] ?? false) && ($value === null || $value === '')) {
                    $checks[] = ['level' => 'error', 'section' => $section, 'message' => "« {$field['label']} » est obligatoire."];
                }

                if (in_array($field['type'], ['items', 'lines'], true) && isset($field['min']) && count((array) $value) < $field['min']) {
                    $checks[] = ['level' => 'error', 'section' => $section, 'message' => "« {$field['label']} » demande au moins {$field['min']} élément(s)."];
                }
            }
        }

        foreach ($s['header']['nav'] as $link) {
            if (in_array($link['section'], $content['hidden'], true)) {
                $checks[] = ['level' => 'warning', 'section' => 'header', 'message' => "Le lien « {$link['label']} » pointe vers une section masquée : il est retiré du menu."];
            }
        }

        if ($s['demo']['highlight'] && ! str_contains((string) $s['demo']['title'], $s['demo']['highlight'])) {
            $checks[] = ['level' => 'warning', 'section' => 'demo', 'message' => 'Le mot surligné ne figure pas dans le titre : rien ne sera surligné.'];
        }

        if (mb_strlen((string) $s['seo']['description']) < 70) {
            $checks[] = ['level' => 'warning', 'section' => 'seo', 'message' => 'La méta-description fait moins de 70 caractères.'];
        }

        if (! $s['seo']['indexable']) {
            $checks[] = ['level' => 'warning', 'section' => 'seo', 'message' => 'Le site est exclu des moteurs de recherche.'];
        }

        return $checks;
    }

    /**
     * Version 1 : textes actuels du site.
     *
     * @return array{order: list<string>, hidden: list<string>, sections: array<string, array<string, mixed>>}
     */
    public static function defaults(): array
    {
        return ['order' => self::MIDDLE, 'hidden' => [], 'sections' => [
            'cover' => ['kick' => 'La plateforme de recrutement d’ouvriers du BTP', 'title' => 'Trouvez vos prochains ouvriers.', 'accent' => 'Ici et maintenant.', 'text' => 'Des ouvriers qualifiés et disponibles, puis le suivi et le paiement de vos chantiers. Tout est dans la même application.', 'cta' => 'Découvrir O’CHANTIER', 'live' => 'Ouvriers disponibles maintenant', 'video' => null],
            'header' => ['logo' => null, 'nav' => [['label' => 'La solution', 'section' => 'solution'], ['label' => 'Tarifs', 'section' => 'tarifs'], ['label' => 'Chantiers près de vous', 'section' => 'carte'], ['label' => 'Contact', 'section' => 'contact']], 'cta_worker' => 'Devenir ouvrier', 'cta_app' => 'Télécharger l’app'],
            'demo' => ['kick' => 'Promoteurs · Entreprises générales · Bureaux techniques', 'title' => 'Des ouvriers qualifiés disponibles pour votre chantier.', 'highlight' => 'disponibles', 'text' => 'Testez l’application client. Choisissez un chantier, indiquez le nombre d’ouvriers par métier et lancez la recherche. Vous recevez une offre d’ouvriers vérifiés, que vous confirmez en payant l’acompte.', 'steps' => [
                ['title' => 'Choisissez le chantier', 'text' => 'Tous vos chantiers et leur avancement.'],
                ['title' => 'Indiquez vos besoins par métier', 'text' => 'Dates, nombre d’ouvriers et tarif moyen.'],
                ['title' => 'Recevez une offre vérifiée', 'text' => 'Les ouvriers disponibles près du chantier.'],
                ['title' => 'Payez l’acompte en sécurité', 'text' => '30 % à la réservation, solde après validation.'],
            ]],
            'solution' => ['kick' => 'La solution', 'title' => 'Recruter, suivre, payer. Au même endroit.', 'text' => 'Six outils reliés entre eux : chaque information saisie sert à toute l’équipe.', 'features' => [
                ['title' => 'Recrutement à la demande', 'text' => 'Trouvez en quelques heures des ouvriers qualifiés et disponibles. Identité, diplômes, habilitations et assurances sont vérifiés.', 'core' => true],
                ['title' => 'Suivi des présences', 'text' => 'Pointage géolocalisé avec photo et signature de l’ouvrier. Taux de présence du jour et global.', 'core' => false],
                ['title' => 'Planification des travaux', 'text' => 'Une chronologie d’étapes à démarrer et à valider. L’avancement du chantier se met à jour automatiquement.', 'core' => false],
                ['title' => 'Gestion d’équipe multi-utilisateurs', 'text' => 'Invitez vos collaborateurs par SMS ou e-mail : administrateur, chef de chantier, technicien, comptable ou observateur.', 'core' => false],
                ['title' => 'Paiement flexible et sécurisé', 'text' => 'Acompte de 30 % à la réservation, puis solde ou paiement en 2 ou 3 fois. Échéances suivies dans l’app.', 'core' => false],
                ['title' => 'Rapport de fin de chantier', 'text' => 'Un document généré automatiquement : durée, présence, étapes, équipe et historique des pointages.', 'core' => false],
            ]],
            'paiement' => ['kick' => 'Paiement flexible & sécurisé', 'title' => 'Payé pour le travail fait. Ni avant, ni après.', 'text' => 'Vous déposez les fonds au lancement de la mission. Ils restent sécurisés jusqu’à la validation du travail, puis l’ouvrier est payé immédiatement. Vous êtes protégé, et lui aussi.', 'chips' => ['Acompte de 30 %', 'En 2 ou 3 fois', 'Solde après validation'], 'steps' => [
                ['title' => 'Fonds déposés', 'text' => 'Le montant est réservé sur un compte de séquestre.'],
                ['title' => 'Travail validé', 'text' => 'Vous validez à partir des présences et des étapes réalisées.'],
                ['title' => 'Ouvrier payé', 'text' => 'Le virement part tout de suite et la facture est générée automatiquement.'],
            ]],
            'tarifs' => ['kick' => 'Abonnements', 'title' => 'Une formule pour chaque équipe.', 'text' => 'Avec une formule Équipe ou Pro, invitez vos collaborateurs par SMS ou e-mail et attribuez-leur un rôle : chef de chantier, technicien, comptable ou observateur.'],
            'carte' => ['kick' => 'Vous êtes ouvrier ?', 'title' => 'Des chantiers recrutent près de chez vous.', 'text' => 'Filtrez par métier, localisez-vous et postulez depuis la carte. Les candidatures sont étudiées par l’équipe O’CHANTIER, qui vous recontacte pour vous affecter aux chantiers.', 'cta' => 'Devenir ouvrier →'],
            'app' => ['kick' => 'Application promoteurs & techniciens', 'title' => 'Téléchargez l’application O’CHANTIER.', 'text' => 'L’application des promoteurs immobiliers et des techniciens de chantier. Réservez des ouvriers qualifiés, suivez les présences et l’avancement, payez en toute sécurité. Disponible sur iPhone et Android.', 'ios' => null, 'android' => null, 'qr' => null],
            'contact' => ['kick' => 'Nous contacter', 'title' => 'Une question ? Parlons de votre chantier.', 'text' => 'Votre message est transmis directement à l’équipe O’CHANTIER. Nous répondons sous 24 h ouvrées.', 'phone' => '+225 07 00 00 00 00', 'email' => 'contact@ochantier.ci', 'address' => 'Cocody, Abidjan · Côte d’Ivoire', 'hours' => 'Lun. – Sam. · 8 h – 18 h',
                'profiles' => ['Promoteur immobilier', 'Entreprise de BTP', 'Technicien chantier', 'Ouvrier', 'Autre'],
                'subjects' => ['Demande de démonstration', 'Abonnement Équipe ou Pro', 'Recrutement d’ouvriers', 'Partenariat', 'Assistance technique', 'Autre demande']],
            'newsletter' => ['title' => 'Newsletter', 'text' => 'Nouveautés, conseils chantier et offres O’CHANTIER, une fois par mois.', 'placeholder' => 'Votre adresse e-mail', 'cta' => 'S’abonner', 'notice' => 'Désinscription possible à tout moment.', 'success' => 'Merci ! Inscription confirmée.'],
            'footer' => ['slogan' => 'Trouvez vos prochains ouvriers, ici et maintenant.', 'phone' => '+225 07 00 00 00 00', 'email' => 'contact@ochantier.ci', 'legal' => '© 2026 O’CHANTIER · Mentions légales · CGU', 'legal_url' => null, 'terms_url' => null],
            'seo' => ['title' => 'O’CHANTIER · Recrutez des ouvriers du BTP qualifiés, ici et maintenant', 'description' => 'O’CHANTIER, la plateforme de recrutement d’ouvriers du BTP à Abidjan : des ouvriers qualifiés et vérifiés, le suivi des présences et de l’avancement, le paiement sécurisé de vos chantiers.', 'og_image' => null, 'indexable' => true],
        ]];
    }
}
