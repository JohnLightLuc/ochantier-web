<?php

namespace Database\Seeders;

use App\Enums\BillingPeriod;
use App\Models\Country;
use App\Models\PaymentOperator;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Opérateurs Mobile Money de Côte d’Ivoire et formules d’abonnement des promoteurs (Solo, Équipe, Pro).
 */
class CommercialOfferSeeder extends Seeder
{
    /**
     * Code => [nom, prestataire, motif du numéro local à 10 chiffres].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const OPERATORS = [
        'orange_money' => ['Orange Money', 'orange', '^07\d{8}$'],
        'mtn_momo' => ['MTN Mobile Money', 'mtn', '^05\d{8}$'],
        'moov_money' => ['Moov Money', 'moov', '^01\d{8}$'],
        'wave' => ['Wave', 'wave', '^(01|05|07)\d{8}$'],
    ];

    /**
     * Code => [nom, sous-titre, description, prix mensuel (XOF), places, fonctionnalités, mise en avant].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int, 4: int, 5: list<string>, 6: bool}>
     */
    private const PLANS = [
        'solo' => ['Solo', '1 utilisateur', 'Compte à utilisateur unique · propriétaire', 0, 1, ['Propriétaire uniquement', 'Chantiers, réservations et pointage', 'Paiement en une fois · sans rapport global'], false],
        'equipe' => ['Équipe', 'Jusqu’à 5 utilisateurs', 'Compte équipe · rôles clés', 15000, 5, ['Jusqu’à 5 utilisateurs · rôles clés', 'Paiement flexible en 2 ou 3 fois', 'Rapport global de fin de chantier'], true],
        'pro' => ['Pro', 'Jusqu’à 15 utilisateurs', 'Compte entreprise · équipes multiples', 35000, 15, ['Jusqu’à 15 utilisateurs', 'Paiement flexible et rapports de fin de chantier', 'Journal d’activité détaillé'], false],
        'entreprise' => ['Entreprise', 'Jusqu’à 50 utilisateurs', 'Grands comptes · multi-agences', 75000, 50, ['Jusqu’à 50 utilisateurs', 'Paiement flexible, rapports et multi-agences', 'Support prioritaire dédié'], false],
    ];

    /**
     * Formules des entrepreneurs (application Entrepreneurs et Techniciens) : code => [nom, sous-titre, prix
     * mensuel (XOF), places (utilisateurs du compte et ouvriers actifs par chantier), fonctionnalités, mise en avant].
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: int, 4: list<string>, 5: bool}>
     */
    private const CONTRACTOR_PLANS = [
        'entrepreneur_solo' => ['Solo', '1 utilisateur — vous uniquement', 0, 1, ['Suivi des chantiers attribués', 'Réponse aux demandes de devis et offres', 'Sans équipe, pointage ni rapport de fin de chantier'], false],
        'entrepreneur_essentiel' => ['Essentiel', 'Jusqu’à 5 membres d’équipe', 15000, 5, ['Pointage journalier avec photo et signature', 'Recherche et affectation d’ouvriers', 'Rapport de fin de chantier PDF'], false],
        'entrepreneur_pro' => ['Pro', 'Jusqu’à 20 membres d’équipe', 35000, 20, ['Tout Essentiel, plusieurs chantiers en parallèle', 'Évaluation des équipes et historique', 'Rapports de fin de chantier'], true],
    ];

    public function run(): void
    {
        $country = Country::query()->where('iso_code', 'CI')->firstOrFail();

        foreach (self::OPERATORS as $code => [$name, $provider, $pattern]) {
            PaymentOperator::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'country_id' => $country->getKey(),
                'provider' => $provider,
                'number_pattern' => $pattern,
                'supports_collection' => true,
                'supports_payout' => true,
                'is_active' => true,
            ]);
        }

        $order = 0;

        $rank = 100;

        foreach (self::CONTRACTOR_PLANS as $code => [$name, $subtitle, $price, $seats, $features, $featured]) {
            SubscriptionPlan::query()->firstOrCreate(['code' => $code], [
                'audience' => 'contractor',
                'name' => $name,
                'subtitle' => $subtitle,
                'price_amount' => $price,
                'billing_period' => BillingPeriod::Mois,
                'max_users' => $seats,
                'features' => $features,
                'is_featured' => $featured,
                'sort_order' => $rank += 10,
                'is_active' => true,
            ]);
        }

        foreach (self::PLANS as $code => [$name, $subtitle, $description, $price, $seats, $features, $featured]) {
            SubscriptionPlan::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'subtitle' => $subtitle,
                'description' => $description,
                'price_amount' => $price,
                'billing_period' => BillingPeriod::Mois,
                'max_users' => $seats,
                'features' => $features,
                'is_featured' => $featured,
                'sort_order' => $order += 10,
                'is_active' => true,
            ]);
        }
    }
}
