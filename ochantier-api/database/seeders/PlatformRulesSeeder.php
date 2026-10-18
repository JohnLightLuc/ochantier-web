<?php

namespace Database\Seeders;

use App\Enums\AppName;
use App\Enums\AppPlatform;
use App\Enums\Audience;
use App\Enums\ParameterStatus;
use App\Enums\PricingParameterScope;
use App\Enums\PricingVariable;
use App\Enums\RetentionAction;
use App\Models\Admin;
use App\Models\AppVersion;
use App\Models\DataRetentionPolicy;
use App\Models\LegalDocument;
use App\Models\MatchingWeightSet;
use App\Models\PricingParameter;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Règles de lancement : textes légaux, variables financières X, Y, Z et R, barème du matching, paramètres
 * métier, durées de conservation et versions minimales des applications.
 *
 * Valeurs reprises du module Paramètres de la maquette du Back Office. Les lignes existantes ne sont jamais
 * modifiées : une valeur changée depuis le Back Office est conservée.
 */
class PlatformRulesSeeder extends Seeder
{
    private const LAUNCH_DATE = '2026-01-01 00:00:00';

    /**
     * Code => [destinataire, titre].
     *
     * @var array<string, array{0: Audience, 1: string}>
     */
    private const LEGAL_DOCUMENTS = [
        'cgu' => [Audience::All, 'Conditions générales d’utilisation'],
        'privacy' => [Audience::All, 'Politique de confidentialité'],
        'financial_terms' => [Audience::Promoter, 'Conditions financières (acompte, commissions et échéances)'],
        'commercial_charter' => [Audience::Commercial, 'Charte du commercial O’CHANTIER'],
        'data_processing' => [Audience::Worker, 'Consentement au traitement des données de l’ouvrier'],
        'photo_usage' => [Audience::Worker, 'Autorisation d’utilisation des photos de pointage'],
    ];

    /**
     * Variable => [valeur (%), motif].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PRICING = [
        'X' => ['30', 'Acompte promoteur : 30 % du coût global de l’offre, déduit des échéances suivantes.'],
        'Y' => ['8', 'Commission promoteur : 8 % des montants payés.'],
        'Z' => ['10', 'Commission ouvrier : 10 % ajoutés au tarif net déclaré par l’ouvrier.'],
        'R' => ['50', 'Seuil calendaire : paiement exigible à 50 % de la période écoulée.'],
    ];

    /**
     * @var array<string, array{0: mixed, 1: string}>
     */
    private const SETTINGS = [
        'support.phone' => ['+225 27 22 00 00 00', 'Numéro du support affiché dans le centre d’aide.'],
        'support.whatsapp' => ['+225 07 00 00 00 00', 'Numéro WhatsApp du support.'],
        'support.email' => ['support@ochantier.ci', 'Adresse e-mail du support.'],
        'support.hours' => ['Du lundi au samedi, de 8 h à 18 h', 'Horaires du support.'],
        'needs.start_alert_days' => [7, 'Alerte avant le démarrage d’un besoin non couvert (jours).'],
        'needs.minimum_coverage_percent' => [80, 'Couverture minimale d’un besoin avant démarrage (%).'],
        'needs.processing_hours' => [48, 'Délai de traitement d’une offre publiée (heures).'],
        'needs.reminder_days' => [3, 'Relance du promoteur sans réponse (jours).'],
        'needs.expiry_alert_days' => [3, 'Alerte avant l’expiration d’une offre (jours).'],
        'matching.algorithm' => ['MX-2.3', 'Version de l’algorithme de matching.'],
        'matching.levels' => [['excellent' => 85, 'tres_bon' => 75, 'bon' => 65, 'acceptable' => 55, 'faible' => 40], 'Seuils de niveau de correspondance (score sur 100).'],
        'matching.profiles_shown' => [8, 'Nombre de profils proposés par besoin.'],
        'matching.obsolete_after_days' => [3, 'Ancienneté d’un résultat avant recalcul (jours).'],
        'matching.success_rate_days' => [90, 'Période de calcul du taux de réussite (jours).'],
        'attendance.late_tolerance_minutes' => [10, 'Tolérance de retard à l’arrivée.'],
        'attendance.early_departure_tolerance_minutes' => [15, 'Tolérance de départ anticipé.'],
        'attendance.break_minutes' => [60, 'Pause exclue du temps travaillé.'],
        'attendance.rounding_minutes' => [5, 'Arrondi des durées pointées.'],
        'attendance.normal_day_minutes' => [480, 'Durée d’une journée normale.'],
        'attendance.overtime_threshold_minutes' => [30, 'Dépassement à partir duquel des heures supplémentaires sont comptées.'],
        'attendance.absence_after_minutes' => [120, 'Retard au-delà duquel l’ouvrier est déclaré absent.'],
        'attendance.min_day_minutes' => [240, 'Durée minimale validable pour une journée.'],
        'attendance.max_day_minutes' => [720, 'Durée maximale validable pour une journée.'],
        'attendance.site_radius_meters' => [150, 'Rayon autorisé autour du chantier.'],
        'attendance.zone_tolerance_meters' => [300, 'Tolérance hors zone avant anomalie.'],
        'attendance.gps_precision_meters' => [50, 'Précision GPS minimale exigée.'],
        'attendance.required_proofs' => [['photo', 'localisation', 'signature'], 'Preuves exigées à chaque pointage.'],
        'attendance.auto_close_time' => ['23:59', 'Heure de clôture automatique d’une journée non clôturée.'],
        'payments.dual_validation_threshold' => [1000000, 'Montant (XOF) à partir duquel un paiement requiert deux validations.'],
    ];

    /**
     * Catégorie => [durée (jours), action].
     *
     * @var array<string, array{0: int, 1: RetentionAction}>
     */
    private const RETENTION = [
        'pieces_kyc' => [1825, RetentionAction::Supprimer],
        'photos_pointage' => [365, RetentionAction::Supprimer],
        'positions_gps' => [180, RetentionAction::Supprimer],
        'codes_otp' => [30, RetentionAction::Supprimer],
        'logs_acces' => [365, RetentionAction::Supprimer],
        'comptes_inactifs' => [1095, RetentionAction::Anonymiser],
        'journal_audit' => [3650, RetentionAction::Archiver],
        'pieces_comptables' => [3650, RetentionAction::Archiver],
    ];

    public function run(): void
    {
        $author = Admin::query()->whereHas('roles', fn ($roles) => $roles->where('code', 'super_admin'))->oldest()->firstOrFail();

        foreach (self::LEGAL_DOCUMENTS as $code => [$audience, $title]) {
            LegalDocument::query()->firstOrCreate(['code' => $code, 'version' => '1.0'], [
                'audience' => $audience,
                'title' => $title,
                'content' => 'Texte provisoire : à remplacer par la version validée par le service juridique.',
                'effective_from' => self::LAUNCH_DATE,
                'published_by_admin_id' => $author->getKey(),
            ]);
        }

        foreach (self::PRICING as $code => [$value, $reason]) {
            PricingParameter::query()->firstOrCreate(['code' => PricingVariable::from($code), 'scope' => PricingParameterScope::Global], [
                'value' => $value,
                'effective_from' => self::LAUNCH_DATE,
                'reason' => $reason,
                'status' => ParameterStatus::Actif,
                'created_by_admin_id' => $author->getKey(),
            ]);
        }

        if (MatchingWeightSet::query()->doesntExist()) {
            MatchingWeightSet::query()->create([
                'version' => 1,
                'weights' => [
                    'metier' => 0.25, 'disponibilite' => 0.20, 'experience' => 0.15, 'proximite' => 0.15,
                    'cout' => 0.10, 'qualite_profil' => 0.05, 'note' => 0.05, 'historique' => 0.05,
                ],
                'exclusion_rules' => [
                    'rayon_km' => 20, 'rayon_max_km' => 40, 'tolerance_budget_pct' => 10, 'tolerance_budget_max_pct' => 25,
                    'identite_verifiee' => true,
                ],
                'fairness_rules' => ['profils_affiches' => 8],
                'reason' => 'Barème de lancement (PM-2026-09 · v4).',
                'is_active' => true,
                'effective_from' => self::LAUNCH_DATE,
                'created_by_admin_id' => $author->getKey(),
            ]);
        }

        foreach (self::SETTINGS as $key => [$value, $description]) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value, 'description' => $description]);
        }

        foreach (self::RETENTION as $category => [$days, $action]) {
            DataRetentionPolicy::query()->firstOrCreate(['data_category' => $category], ['retention_days' => $days, 'action' => $action]);
        }

        foreach ([AppName::Promoteur, AppName::Commercial] as $app) {
            foreach (AppPlatform::cases() as $platform) {
                AppVersion::query()->firstOrCreate(['app_name' => $app, 'platform' => $platform], ['min_version' => '1.0.0', 'latest_version' => '1.0.0']);
            }
        }
    }
}
