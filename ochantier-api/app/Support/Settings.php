<?php

namespace App\Support;

use App\Enums\WorkerStatus;

use App\Models\Setting;
use App\Models\SettingChange;
use App\Models\Worker;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Paramètres de la plateforme : définition des sections (champs, bornes, valeurs par défaut, rattachement à
 * config/admin.php), lecture des valeurs enregistrées, validation (y compris les règles croisées), enregistrement
 * tracé dans le journal d’audit et application à la configuration utilisée par les modules.
 *
 * Un paramètre rattaché à la configuration (`config`) prend effet immédiatement dans les modules concernés
 * (commissions, frais, Matching…). Les autres paramètres sont conservés pour les applications et services.
 */
class Settings
{
    /**
     * Sections : clé → libellé, icône.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SECTIONS = [
        'general' => ['Général', 'settings'], 'metiers' => ['Métiers', 'engineering'], 'commissions' => ['Commissions & frais', 'paid'], 'paiements' => ['Paiements', 'credit_card'],
        'matching' => ['Matching & affectations', 'link'], 'utilisateurs' => ['Utilisateurs & rôles', 'group'], 'notifications' => ['Notifications', 'notifications'],
        'securite' => ['Sécurité', 'verified_user'], 'integrations' => ['Intégrations', 'power'], 'audit' => ['Journal d’audit', 'description'],
    ];

    /**
     * Profils fictifs de la simulation du score de Matching (une note par critère, dans l’ordre des pondérations).
     *
     * @var array<int, array{0: string, 1: array<int, int>}>
     */
    public const MATCHING_PROFILES = [['Maçon confirmé · 4 km', [95, 80, 85, 95, 70, 80, 90, 75]], ['Maçon expert · 18 km', [100, 90, 100, 45, 60, 95, 95, 90]], ['Débutant disponible · 2 km', [80, 100, 40, 100, 95, 60, 70, 40]]];

    /** @var array<string, mixed> Valeurs de config/admin.php avant application des paramètres enregistrés. */
    private static array $defaults = [];

    /** @var array<string, mixed>|null */
    private static ?array $stored = null;

    /**
     * @return array<string, mixed>
     */
    public static function stored(): array
    {
        if (self::$stored === null) {
            try {
                self::$stored = Schema::hasTable('settings') ? Setting::query()->pluck('value', 'key')->all() : [];
            } catch (Throwable) {
                self::$stored = [];
            }
        }

        return self::$stored;
    }

    public static function forget(): void
    {
        self::$stored = null;
    }

    /**
     * Valeur d’origine d’un chemin de configuration (admin.*).
     */
    public static function default(string $path): mixed
    {
        return array_key_exists($path, self::$defaults) ? self::$defaults[$path] : config("admin.{$path}");
    }

    /**
     * Valeur actuelle d’un champ : enregistrée, sinon valeur par défaut.
     *
     * @param  array<string, mixed>  $field
     */
    public static function value(array $field): mixed
    {
        $stored = self::stored();

        return array_key_exists($field['code'], $stored) ? $stored[$field['code']] : self::fallback($field);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function fallback(array $field): mixed
    {
        return isset($field['config']) ? self::default($field['config']) : ($field['default'] ?? null);
    }

    /**
     * Applique les paramètres enregistrés à la configuration (taux désactivés ramenés à 0, métiers actifs).
     */
    public static function apply(): void
    {
        $stored = self::stored();
        foreach (array_keys(self::SECTIONS) as $section) {
            foreach (self::fields($section, false) as $f) {
                if (! isset($f['config'])) {
                    continue;
                }
                self::$defaults[$f['config']] ??= config("admin.{$f['config']}");
                $value = array_key_exists($f['code'], $stored) ? $stored[$f['code']] : self::$defaults[$f['config']];
                if (isset($f['switch']) && ($f['zeroWhenOff'] ?? false) && ! (array_key_exists($f['switch'], $stored) ? $stored[$f['switch']] : true)) {
                    $value = 0;
                }
                config(["admin.{$f['config']}" => $value]);
            }
        }
        config(['admin.trades' => self::activeTrades()]);
    }

    /**
     * Métiers proposés aux ouvriers : catalogue par défaut, ajouts et suppressions, métiers désactivés exclus.
     *
     * @return array<int, string>
     */
    public static function activeTrades(): array
    {
        return array_values(array_map(fn (array $t): string => $t['name'], array_filter(self::tradeCatalog(), fn (array $t): bool => $t['active'])));
    }

    /**
     * Catalogue des métiers : nom et statut (actif ou désactivé).
     *
     * @return array<int, array{name: string, active: bool}>
     */
    public static function tradeCatalog(): array
    {
        $stored = self::stored()['trades'] ?? null;

        return $stored ?? array_map(fn (string $t): array => ['name' => $t, 'active' => true], Catalog::TRADES);
    }

    /**
     * @return array<string, mixed>
     */
    private static function num(string $code, string $label, string $desc, string $unit, int|float $min, int|float $max, array $o = []): array
    {
        return ['code' => $code, 'label' => $label, 'desc' => $desc, 'type' => 'num', 'unit' => $unit, 'min' => $min, 'max' => $max, ...$o];
    }

    /**
     * @param  array{0: string, 1: string}  $labels
     * @return array<string, mixed>
     */
    private static function toggle(string $code, string $label, string $desc, array $labels = ['Activé', 'Désactivé'], array $o = []): array
    {
        return ['code' => $code, 'label' => $label, 'desc' => $desc, 'type' => 'bool', 'labels' => $labels, 'default' => true, ...$o];
    }

    /**
     * @param  array<int, string>  $options
     * @return array<string, mixed>
     */
    private static function choice(string $code, string $label, string $desc, array $options, array $o = []): array
    {
        return ['code' => $code, 'label' => $label, 'desc' => $desc, 'type' => 'select', 'options' => $options, 'default' => $options[0], ...$o];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $code, string $label, string $desc, string $default, array $o = []): array
    {
        return ['code' => $code, 'label' => $label, 'desc' => $desc, 'type' => 'text', 'default' => $default, ...$o];
    }

    /**
     * Définition d’une section : titre, description, bandeau et groupes de champs.
     *
     * @return array{title: string, desc: string, icon: string, banner: string, groups: array<int, array<string, mixed>>}
     */
    public static function definition(string $section, bool $withUsage = true): array
    {
        $icon = self::SECTIONS[$section][1];
        $def = fn (string $title, string $desc, string $banner, array $groups): array => ['title' => $title, 'desc' => $desc, 'icon' => $icon, 'banner' => $banner, 'groups' => $groups];

        return match ($section) {
            'general' => $def('Informations générales', 'Identité de la plateforme, coordonnées, localisation et formats d’affichage', 'Ces informations apparaissent sur les reçus, les e-mails et les documents générés par la plateforme.', [
                ['icon' => 'domain', 'title' => 'Identité de la plateforme', 'fields' => [self::text('plat.nom', 'Nom de la plateforme', 'Nom affiché dans les applications', 'O’CHANTIER'), self::text('plat.raison', 'Raison sociale', 'Mentionnée sur les reçus et factures', 'O’CHANTIER SAS'), self::text('plat.rccm', 'Registre de commerce (RCCM)', 'Numéro d’immatriculation', 'CI-ABJ-03-2021-B13-04502'), self::text('plat.ncc', 'Identifiant fiscal (NCC)', 'Numéro de compte contribuable', '2104587 K')]],
                ['icon' => 'contact_mail', 'title' => 'Coordonnées', 'fields' => [self::text('plat.email', 'E-mail général', 'Adresse de contact principale', 'contact@ochantier.ci', ['email' => true]), self::text('plat.support', 'E-mail du support', 'Réponses aux promoteurs et ouvriers', 'support@ochantier.ci', ['email' => true]), self::text('plat.tel', 'Téléphone', 'Numéro affiché sur les documents', '+225 27 22 45 18 90'), self::text('plat.adresse', 'Adresse', 'Siège social', 'Cocody Riviera 3, Abidjan'), self::text('plat.horaires', 'Horaires du support', 'Affichés dans l’aide', 'Lun–Ven 08:00–18:00 · Sam 09:00–13:00'), self::num('plat.slaSupport', 'Délai de réponse du support', 'Engagement de première réponse', 'h', 1, 72, ['default' => 24])]],
                ['icon' => 'language', 'title' => 'Localisation et formats', 'fields' => [self::choice('plat.pays', 'Pays', 'Pays d’activité', ['Côte d’Ivoire']), self::choice('plat.devise', 'Devise', 'Tous les montants sont affichés dans cette devise', ['Franc CFA (FCFA)']), self::choice('plat.tz', 'Fuseau horaire', 'Utilisé pour les pointages et les échéances', ['Africa/Abidjan (UTC+0)']), self::choice('plat.lang', 'Langue par défaut', 'Langue des applications et notifications', ['Français', 'Anglais']), self::choice('fmt.date', 'Format de date', 'Appliqué à tous les écrans', ['JJ/MM/AAAA']), self::choice('fmt.heure', 'Format horaire', 'Appliqué à tous les écrans', ['24 h (HH:MM)'])]],
            ]),
            'metiers' => $def('Métiers & profils ouvriers', 'Métiers proposés sur la plateforme et règles de qualité des profils', 'Un métier utilisé par des ouvriers validés ne peut pas être désactivé ; un métier utilisé par un ouvrier ne peut pas être supprimé.', [
                ['icon' => 'badge', 'title' => 'Règles du profil ouvrier', 'fields' => [self::num('w.qualMin', 'Qualité minimale du profil', 'En dessous, le profil n’est pas proposé au Matching', '%', 0, 100, ['default' => 70]), self::num('w.complete', 'Seuil de profil complet', 'Pourcentage de complétude exigé', '%', 0, 100, ['default' => 80]), self::num('w.validDelai', 'Délai de validation', 'Traitement d’un nouveau profil', 'h', 1, 240, ['default' => 48]), self::num('w.docAlert', 'Alerte de document expiré', 'Notification avant expiration', 'jours', 1, 90, ['default' => 30]), self::num('w.tarifMin', 'Tarif journalier indicatif minimum', 'Borne basse proposée à l’ouvrier', 'FCFA', 0, 100000, ['default' => 5000]), self::num('w.tarifMax', 'Tarif journalier indicatif maximum', 'Borne haute proposée à l’ouvrier', 'FCFA', 0, 200000, ['default' => 40000])]],
                ['icon' => 'handyman', 'title' => 'Métiers proposés', 'fields' => [['code' => 'trades', 'label' => 'Métiers proposés', 'desc' => '', 'type' => 'trades', 'usage' => $withUsage ? self::tradeUsage() : []]]],
            ]),
            'commissions' => $def('Commissions & frais', 'Acompte, commissions O’CHANTIER, seuils de paiement et modes de rémunération', 'Les taux s’appliquent aux nouvelles opérations ; chaque commission conserve la version de règle en vigueur à sa date.', [
                ['icon' => 'percent', 'title' => 'Règles financières', 'kind' => 'rules', 'fields' => [
                    self::num('x', 'Acompte initial promoteur (X)', 'Pourcentage exigé lors de la validation d’une offre', '%', 0, 100, ['config' => 'need_rules.deposit_rate', 'switch' => 'x.on', 'zeroWhenOff' => true, 'icon' => 'paid', 'tone' => 'warning', 'labels' => ['Actif', 'Inactif']]),
                    self::num('y', 'Commission promoteur (Y)', 'Frais de service ajoutés au montant payé par le promoteur', '%', 0, 100, ['config' => 'need_rules.promoter_commission', 'switch' => 'y.on', 'zeroWhenOff' => true, 'icon' => 'person', 'tone' => 'danger', 'labels' => ['Actif', 'Inactif']]),
                    self::num('z', 'Commission ouvrier (Z)', 'Commission prélevée sur le coût journalier de l’ouvrier', '%', 0, 100, ['config' => 'worker_commission_rate', 'switch' => 'z.on', 'zeroWhenOff' => true, 'icon' => 'groups', 'tone' => 'info', 'labels' => ['Actif', 'Inactif']]),
                    self::num('r', 'Seuil de paiement chantier (R)', 'Niveau d’exécution déclenchant une nouvelle demande de paiement', '%', 0, 100, ['config' => 'need_rules.payment_threshold', 'switch' => 'r.on', 'icon' => 'bar_chart', 'tone' => 'success', 'labels' => ['Notification automatique', 'Notification désactivée']]),
                ]],
                ['icon' => 'stacked_line_chart', 'title' => 'Seuils de paiement chantier', 'kind' => 'thresholds', 'fields' => [['code' => 'rsx', 'label' => 'Seuils suivants', 'desc' => 'Paliers d’exécution déclenchant les demandes de paiement suivantes', 'type' => 'list', 'default' => [80], 'max' => 8]]],
                ['icon' => 'payments', 'title' => 'Modes de paiement', 'kind' => 'modes', 'fields' => [
                    self::toggle('mode.j', 'Journalier', 'Paiement à la journée de travail effectuée', ['Activé', 'Désactivé'], ['icon' => 'calendar_month', 'tone' => 'success']),
                    self::toggle('mode.h', 'Hebdomadaire', 'Paiement chaque semaine selon les heures validées', ['Activé', 'Désactivé'], ['icon' => 'date_range', 'tone' => 'info']),
                    self::toggle('mode.c', 'Contractuel', 'Paiement selon les jalons du contrat', ['Activé', 'Désactivé'], ['icon' => 'description', 'tone' => 'warning']),
                ]],
            ]),
            'paiements' => $def('Paiements', 'Règles de paiement, reversements et frais des opérateurs', 'Les frais des opérateurs ne font jamais partie des commissions O’CHANTIER. Les opérations clôturées ne sont pas recalculées.', [
                ['icon' => 'rule', 'title' => 'Règles de paiement', 'fields' => [
                    self::num('py.dbl', 'Seuil de double validation', 'Au-delà, deux validations sont exigées', 'FCFA', 0, 100000000, ['config' => 'payments.double_validation']),
                    self::choice('py.feePayer', 'Payeur des frais opérateurs', 'Frais d’encaissement', ['Promoteur', 'O’CHANTIER'], ['config' => 'payments.fee_payer']),
                    self::choice('py.refund', 'Remboursement de commission', 'Traitement de la commission en cas de remboursement', ['Remboursement proportionnel', 'Aucun remboursement de commission', 'Remboursement intégral'], ['config' => 'payments.refund_rule']),
                    self::num('py.tax', 'Taxe collectée', 'Collectée pour le compte de l’administration', '%', 0, 30, ['config' => 'payments.tax', 'dec' => true]),
                    self::num('py.revDelai', 'Délai de reversement', 'Après encaissement confirmé', 'h', 1, 240, ['default' => 24]),
                    self::toggle('py.lot', 'Traitement par lots', 'Reversements groupés'),
                ]],
                ['icon' => 'account_balance', 'title' => 'Frais des opérateurs', 'fields' => collect(array_keys(config('admin.payments.fees')))->flatMap(fn (string $m): array => [
                    self::num("fee.{$m}.p", "{$m} · pourcentage", 'Frais proportionnels', '%', 0, 10, ['config' => "payments.fees.{$m}.0", 'dec' => true]),
                    self::num("fee.{$m}.f", "{$m} · montant fixe", 'Frais fixes par opération', 'FCFA', 0, 10000, ['config' => "payments.fees.{$m}.1"]),
                ])->all()],
            ]),
            'matching' => $def('Matching & affectations', 'Pondérations du score, seuils, distances et règles d’affectation', 'Les résultats de Matching déjà calculés sont conservés jusqu’au prochain recalcul.', [
                ['icon' => 'balance', 'title' => 'Pondérations du Matching', 'sum' => 100, 'fields' => array_map(fn (string $k, array $w): array => self::num("mx.w.{$k}", $w[0], 'Poids dans le score global', '%', 0, 100, ['config' => "matching.weights.{$k}.1"]), array_keys(config('admin.matching.weights')), config('admin.matching.weights'))],
                ['icon' => 'military_tech', 'title' => 'Seuils de qualification', 'fields' => array_map(fn (string $label, int $i): array => self::num("mx.s.{$i}", $label, 'Score minimal', 'pts', 0, 100, ['config' => "matching.thresholds.{$label}"]), array_keys(config('admin.matching.thresholds')), array_keys(array_keys(config('admin.matching.thresholds'))))],
                ['icon' => 'distance', 'title' => 'Distance, budget et résultats', 'fields' => [
                    self::num('mx.rayon', 'Rayon de recherche', 'Distance maximale recommandée', 'km', 1, 200, ['config' => 'matching.radius']), self::num('mx.rayonMax', 'Distance bloquante', 'Au-delà, le profil est exclu', 'km', 1, 300, ['config' => 'matching.radius_max']),
                    self::num('mx.tol', 'Tolérance budgétaire', 'Dépassement toléré du coût', '%', 0, 100, ['config' => 'matching.tolerance']), self::num('mx.tolMax', 'Tolérance budgétaire maximale', 'Avec dérogation', '%', 0, 100, ['config' => 'matching.tolerance_max']),
                    self::num('mx.limite', 'Limite de profils proposés', 'Par besoin', 'profils', 1, 50, ['config' => 'matching.limit']), self::num('mx.obsolete', 'Validité des résultats', 'Avant recalcul obligatoire', 'jours', 1, 30, ['config' => 'matching.obsolete_days']),
                    self::toggle('mx.verif', 'Critères bloquants', 'Métier, disponibilité et distance', ['Actifs', 'Désactivés'], ['config' => 'matching.identity_check']),
                ]],
                ['icon' => 'event_available', 'title' => 'Affectations', 'fields' => [self::num('aff.confDelai', 'Délai de confirmation', 'Réponse attendue de l’ouvrier', 'h', 1, 168, ['default' => 24]), self::num('aff.exp', 'Expiration sans réponse', 'La proposition est retirée', 'h', 1, 336, ['default' => 48]), self::num('aff.rempl', 'Délai de remplacement', 'Proposition d’un remplaçant', 'h', 1, 72, ['default' => 12]), self::toggle('aff.overlap', 'Chevauchement d’affectations', 'Un ouvrier sur deux chantiers le même jour', ['Autorisé', 'Interdit'], ['default' => false])]],
            ]),
            'utilisateurs' => $def('Utilisateurs & rôles', 'Comptes administratifs, rôles et règles d’accès', 'Aucun mot de passe n’est défini ni affiché par l’administration : les accès passent par un lien d’activation.', [
                ['icon' => 'manage_accounts', 'title' => 'Règles d’accès', 'fields' => [self::num('usr.invite', 'Expiration des invitations', 'Validité du lien d’activation', 'h', 1, 336, ['default' => 72]), self::choice('usr.2fa', 'Double authentification', 'Politique appliquée aux comptes', ['Obligatoire pour tous les administrateurs', 'Obligatoire pour les rôles critiques', 'Facultative']), self::num('usr.maxAdmins', 'Administrateurs maximum', 'Comptes Super administrateur et Administrateur', 'comptes', 1, 20, ['default' => 5]), self::toggle('usr.expire', 'Expiration des comptes inactifs', 'Désactivation après 90 jours sans connexion', ['Activée', 'Désactivée'])]],
            ]),
            'notifications' => $def('Notifications', 'Canaux, événements notifiés et règles d’envoi', 'Les notifications critiques (échec de paiement, sécurité) sont toujours envoyées, même pendant les horaires silencieux.', [
                ['icon' => 'campaign', 'title' => 'Canaux', 'fields' => array_map(fn (array $c): array => self::toggle("notif.{$c[0]}", $c[1], 'Canal d’envoi'), [['push', 'Notification push'], ['sms', 'SMS'], ['email', 'E-mail'], ['inapp', 'Notification interne']])],
                ['icon' => 'rule', 'title' => 'Règles d’envoi', 'fields' => [self::text('notif.quiet', 'Horaires silencieux', 'Aucun envoi non critique', '21:00 – 07:00'), self::num('notif.relance', 'Relances maximales', 'Pour une action attendue', 'relances', 0, 10, ['default' => 3]), self::num('notif.group', 'Regroupement', 'Fenêtre de regroupement', 'min', 0, 240, ['default' => 15])]],
                ['icon' => 'notifications_active', 'title' => 'Événements notifiés', 'fields' => array_map(fn (string $e, int $i): array => self::toggle("notif.ev.{$i}", $e, 'Envoi automatique', ['Activé', 'Désactivé'], ['default' => $i !== 8]), $events = ['Profil ouvrier validé', 'Offre publiée', 'Proposition de Matching', 'Affectation à confirmer', 'Retard de pointage', 'Paiement reçu', 'Reversement effectué', 'Échec de paiement', 'Avis disponible', 'Litige ouvert', 'SLA bientôt dépassé'], array_keys($events))],
            ]),
            'securite' => $def('Sécurité', 'Mots de passe, connexion, sessions et surveillance', 'Ces règles s’appliquent aux comptes administratifs du Back-office.', [
                ['icon' => 'password', 'title' => 'Mots de passe', 'fields' => [self::num('sec.pwdMin', 'Longueur minimale', 'Nombre de caractères', 'car.', 8, 64, ['default' => 12]), self::choice('sec.pwdCx', 'Complexité', 'Types de caractères exigés', ['Majuscule, minuscule, chiffre et symbole', 'Majuscule, minuscule et chiffre']), self::num('sec.pwdHist', 'Historique', 'Anciens mots de passe interdits', 'derniers', 0, 24, ['default' => 5]), self::num('sec.pwdExp', 'Expiration', '0 = sans expiration', 'jours', 0, 365, ['default' => 90])]],
                ['icon' => 'login', 'title' => 'Connexion et sessions', 'fields' => [self::num('sec.tries', 'Tentatives maximales', 'Avant verrouillage du compte', 'essais', 3, 10, ['default' => 5]), self::num('sec.lock', 'Durée du verrouillage', 'Après échecs répétés', 'min', 1, 1440, ['default' => 15]), self::num('sec.otp', 'Validité du code OTP', 'Code envoyé par SMS', 'min', 1, 30, ['default' => 5]), self::num('sec.session', 'Expiration de session', 'Après inactivité', 'min', 5, 480, ['default' => 30]), self::num('sec.simult', 'Connexions simultanées', 'Par utilisateur', 'sessions', 1, 10, ['default' => 2])]],
                ['icon' => 'shield', 'title' => 'Surveillance', 'fields' => [self::choice('sec.ip', 'Restriction par adresse IP', 'Contrôle des connexions', ['Alerte hors plages connues', 'Désactivée', 'Blocage hors liste blanche']), self::toggle('sec.alert', 'Alertes de sécurité', 'Connexion inhabituelle, échecs répétés', ['Activées', 'Désactivées']), self::toggle('sec.newDevice', 'Validation des nouveaux appareils', 'Code OTP à la première connexion', ['Activée', 'Désactivée'])]],
            ]),
            'integrations' => $def('Intégrations', 'Services connectés : paiement, SMS, e-mail, cartographie, stockage…', 'Les identifiants et secrets ne sont jamais affichés en clair.', []),
            default => $def('Journal d’audit', 'Toutes les modifications des paramètres, avec auteur, date et heure', 'Le journal ne peut pas être modifié. Les secrets n’y apparaissent jamais.', []),
        };
    }

    /**
     * Ouvriers et ouvriers validés par métier.
     *
     * @return array<string, array{workers: int, validated: int}>
     */
    public static function tradeUsage(): array
    {
        return Worker::query()->get(['trade', 'status'])->groupBy('trade')->map(fn ($g): array => ['workers' => $g->count(), 'validated' => $g->filter(fn (Worker $w): bool => $w->status === WorkerStatus::Validated)->count()])->all();
    }

    /**
     * Champs d’une section (y compris les interrupteurs des règles financières).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields(string $section, bool $withUsage = true): array
    {
        $fields = collect(self::definition($section, $withUsage)['groups'])->flatMap(fn (array $g): array => $g['fields']);
        $switches = $fields->filter(fn (array $f): bool => isset($f['switch']))->map(fn (array $f): array => ['code' => $f['switch'], 'label' => $f['label'], 'type' => 'bool', 'labels' => $f['labels'], 'default' => true]);

        return $fields->concat($switches)->values()->all();
    }

    /**
     * Valeurs actuelles d’une section : code → valeur.
     *
     * @return array<string, mixed>
     */
    public static function values(string $section): array
    {
        return collect(self::fields($section, false))->mapWithKeys(fn (array $f): array => [$f['code'] => $f['type'] === 'trades' ? self::tradeCatalog() : self::value($f)])->all();
    }

    /**
     * Valeurs par défaut d’une section (réinitialisation).
     *
     * @return array<string, mixed>
     */
    public static function defaults(string $section): array
    {
        return collect(self::fields($section, false))->mapWithKeys(fn (array $f): array => [$f['code'] => $f['type'] === 'trades' ? self::tradeCatalog() : self::fallback($f)])->all();
    }

    /**
     * Texte d’une valeur dans le journal.
     *
     * @param  array<string, mixed>  $f
     */
    public static function display(array $f, mixed $v): string
    {
        return match ($f['type']) {
            'bool' => mb_strtolower(($f['labels'] ?? ['Activé', 'Désactivé'])[$v ? 0 : 1]),
            'num' => $f['unit'] === 'FCFA' ? number_format((float) $v, 0, ',', ' ').' FCFA' : str_replace('.', ',', (string) $v).' '.$f['unit'],
            'list' => implode(' → ', array_map(fn ($x): string => "{$x} %", (array) $v)),
            default => (string) $v,
        };
    }

    /**
     * Valide les valeurs soumises : erreurs par code de champ (et par groupe pour les sommes).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function validate(string $section, array $values): array
    {
        $errors = [];
        $num = fn ($v): ?float => is_numeric(str_replace([' ', ','], ['', '.'], (string) $v)) ? (float) str_replace([' ', ','], ['', '.'], (string) $v) : null;
        foreach (self::fields($section) as $f) {
            $v = $values[$f['code']] ?? null;
            $error = match ($f['type']) {
                'num' => match (true) {
                    trim((string) $v) === '' => 'Ce champ est obligatoire.',
                    $num($v) === null || (! ($f['dec'] ?? false) && floor($num($v)) !== $num($v)) => ($f['dec'] ?? false) ? 'Nombre attendu.' : 'Nombres entiers uniquement.',
                    $num($v) < $f['min'] => 'La valeur minimale est '.self::display($f, $f['min']).'.',
                    $num($v) > $f['max'] => 'La valeur maximale est '.self::display($f, $f['max']).'.',
                    default => null,
                },
                'text' => match (true) {
                    trim((string) $v) === '' => 'Ce champ est obligatoire.',
                    ($f['email'] ?? false) && ! filter_var(trim((string) $v), FILTER_VALIDATE_EMAIL) => 'Adresse e-mail invalide.',
                    default => null,
                },
                'select' => in_array($v, $f['options'], true) ? null : 'Valeur non autorisée.',
                'bool' => is_bool($v) ? null : 'Valeur non autorisée.',
                'list' => self::validateThresholds((array) $v, $num($values['r'] ?? null), $f['max']),
                'trades' => self::validateTrades((array) $v, $f['usage'] ?? self::tradeUsage()),
                default => null,
            };
            if ($error) {
                $errors[$f['code']] = $error;
            }
        }
        $check = function (string $code, string $other, string $message, bool $strict = false) use ($values, $num, &$errors): void {
            if (! isset($errors[$code]) && array_key_exists($code, $values) && $num($values[$code]) !== null && $num($values[$other] ?? null) !== null && ($strict ? $num($values[$code]) >= $num($values[$other]) : $num($values[$code]) > $num($values[$other]))) {
                $errors[$code] = $message;
            }
        };
        $check('mx.rayon', 'mx.rayonMax', 'Le rayon dépasse la distance bloquante.');
        $check('mx.tol', 'mx.tolMax', 'Dépasse la tolérance maximale.');
        $check('w.tarifMin', 'w.tarifMax', 'Supérieur au tarif maximum.');
        $check('aff.confDelai', 'aff.exp', 'Doit être inférieur au délai d’expiration.');
        for ($i = 1; array_key_exists("mx.s.{$i}", $values); $i++) {
            $check("mx.s.{$i}", 'mx.s.'.($i - 1), 'Doit être inférieur au seuil précédent.', true);
        }
        foreach (self::definition($section, false)['groups'] as $i => $g) {
            if (isset($g['sum'])) {
                $total = collect($g['fields'])->sum(fn (array $f): float => $num($values[$f['code']] ?? 0) ?? 0);
                if ((int) round($total) !== $g['sum']) {
                    $errors["group.{$i}"] = "La somme des pondérations doit être égale à {$g['sum']} % (actuellement ".round($total).' %).';
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, mixed>  $list
     */
    private static function validateThresholds(array $list, ?float $first, int $max): ?string
    {
        if (count($list) > $max) {
            return "{$max} seuils au maximum.";
        }
        $previous = $first;
        foreach ($list as $i => $v) {
            if (! preg_match('/^\d+$/', (string) $v) || (int) $v > 100) {
                return 'Seuil '.($i + 2).' : entier entre 0 et 100 %.';
            }
            if ($previous !== null && (int) $v <= $previous) {
                return 'Seuil '.($i + 2)." : doit être supérieur au seuil précédent ({$previous} %).";
            }
            $previous = (int) $v;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $catalog
     * @param  array<string, array{workers: int, validated: int}>  $usage
     */
    private static function validateTrades(array $catalog, array $usage): ?string
    {
        $names = [];
        foreach ($catalog as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if (mb_strlen($name) < 3) {
                return 'Nom de métier invalide (3 caractères minimum).';
            }
            if (in_array(mb_strtolower($name), $names, true)) {
                return "Le métier « {$name} » existe déjà.";
            }
            $names[] = mb_strtolower($name);
            if (! ($t['active'] ?? true) && ($usage[$name]['validated'] ?? 0)) {
                return "Désactivation impossible : {$usage[$name]['validated']} ouvrier(s) validé(s) utilisent « {$name} ».";
            }
        }
        foreach ($usage as $name => $u) {
            if ($u['workers'] && ! in_array(mb_strtolower($name), $names, true)) {
                return "Suppression impossible : {$u['workers']} ouvrier(s) ont le métier « {$name} ». Désactivez-le plutôt.";
            }
        }

        return null;
    }

    /**
     * Normalise une valeur soumise selon le type du champ.
     *
     * @param  array<string, mixed>  $f
     */
    private static function cast(array $f, mixed $v): mixed
    {
        return match ($f['type']) {
            'num' => ($f['dec'] ?? false) ? (float) str_replace([' ', ','], ['', '.'], (string) $v) : (int) str_replace(' ', '', (string) $v),
            'text' => trim((string) $v),
            'list' => array_map('intval', (array) $v),
            'trades' => array_values(array_map(fn (array $t): array => ['name' => trim($t['name']), 'active' => (bool) ($t['active'] ?? true)], (array) $v)),
            default => $v,
        };
    }

    /**
     * Enregistre les valeurs modifiées d’une section, trace chaque changement et applique la configuration.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string> libellés des changements
     */
    public static function save(string $section, array $values): array
    {
        $label = self::SECTIONS[$section][0];
        $changes = [];
        foreach (self::fields($section) as $f) {
            if (! array_key_exists($f['code'], $values)) {
                continue;
            }
            $old = $f['type'] === 'trades' ? self::tradeCatalog() : self::value($f);
            $new = self::cast($f, $values[$f['code']]);
            if ($f['type'] === 'num' ? (float) $old === (float) $new : $old === $new) {
                continue;
            }
            Setting::query()->updateOrCreate(['key' => $f['code']], ['value' => $new]);
            $action = match ($f['type']) {
                'bool' => "{$f['label']} ".self::display($f, $new),
                'trades' => self::tradeChanges($old, $new),
                'list' => 'Seuils de paiement chantier : '.self::display($f, [(int) ($values['r'] ?? self::value(['code' => 'r', 'config' => 'need_rules.payment_threshold'])), ...$new]),
                default => $f['code'] === 'r' ? 'Seuil de paiement fixé à '.self::display($f, $new) : "{$f['label']} modifié de ".self::display($f, $old).' à '.self::display($f, $new),
            };
            SettingChange::record($label, $action, $f['type'] === 'trades' ? null : self::display($f, $old), $f['type'] === 'trades' ? null : self::display($f, $new));
            $changes[] = $action;
        }
        self::forget();
        self::apply();

        return $changes;
    }

    /**
     * @param  array<int, array{name: string, active: bool}>  $old
     * @param  array<int, array{name: string, active: bool}>  $new
     */
    private static function tradeChanges(array $old, array $new): string
    {
        $o = collect($old)->keyBy('name');
        $n = collect($new)->keyBy('name');
        $parts = array_filter([
            ($added = $n->keys()->diff($o->keys()))->isNotEmpty() ? 'ajoutés : '.$added->implode(', ') : null,
            ($removed = $o->keys()->diff($n->keys()))->isNotEmpty() ? 'supprimés : '.$removed->implode(', ') : null,
            ($off = $n->filter(fn (array $t, string $k): bool => ! $t['active'] && ($o[$k]['active'] ?? true))->keys())->isNotEmpty() ? 'désactivés : '.$off->implode(', ') : null,
            ($on = $n->filter(fn (array $t, string $k): bool => $t['active'] && isset($o[$k]) && ! $o[$k]['active'])->keys())->isNotEmpty() ? 'réactivés : '.$on->implode(', ') : null,
        ]);

        return 'Métiers '.implode(' · ', $parts);
    }

    /**
     * Version d’origine d’un chemin de configuration sous forme lisible (pour les tests et le débogage).
     */
    public static function configured(string $path): mixed
    {
        return Arr::get(config('admin'), $path);
    }
}
