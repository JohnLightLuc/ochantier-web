<?php

namespace Database\Seeders;

use App\Models\Promoter;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Formules Solo, Équipe et Pro, et un abonnement par promoteur : formule et statut selon un motif fixe (actif, essai,
 * impayé, suspendu, résilié), collaborateurs invités, trois dernières factures et journal.
 */
class SubscriptionSeeder extends Seeder
{
    private const PLANS = [
        ['solo', 'Solo', 1, 0, 'pour toujours', '1 utilisateur', 'Compte à utilisateur unique · propriétaire', ['Propriétaire uniquement', 'Chantiers, réservations et pointage', 'Paiement en une fois · sans rapport global'], false],
        ['equipe', 'Équipe', 5, 15000, 'par mois', 'Jusqu’à 5 utilisateurs', 'Compte équipe · rôles clés', ['Jusqu’à 5 utilisateurs · rôles clés', 'Paiement flexible en 2 ou 3 fois', 'Rapport global de fin de chantier'], true],
        ['pro', 'Pro', 15, 35000, 'par mois', 'Jusqu’à 15 utilisateurs', 'Compte entreprise · équipes multiples', ['Jusqu’à 15 utilisateurs', 'Paiement flexible et rapports de fin de chantier', 'Journal d’activité détaillé'], false],
    ];

    /**
     * Formule et statut par promoteur (dans l’ordre) : les sept premiers couvrent tous les cas de la maquette.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const PATTERN = [
        ['equipe', 'Actif'], ['solo', 'Actif'], ['pro', 'Actif'], ['equipe', 'Impayé'], ['equipe', 'Suspendu'], ['equipe', 'Essai'], ['pro', 'Résilié'],
        ['solo', 'Actif'], ['equipe', 'Actif'], ['solo', 'Actif'], ['equipe', 'Actif'], ['pro', 'Actif'],
    ];

    private const METHODS = ['Orange Money', 'MTN Mobile Money', 'Wave', 'Carte bancaire', 'Moov Money'];

    private const FIRST_NAMES = ['Awa', 'Koffi', 'Mariam', 'Yao', 'Fatou', 'Ibrahim', 'Adjoua', 'Serge', 'Aminata', 'Didier', 'Nadia', 'Paul', 'Salimata', 'Hervé', 'Clarisse'];

    private const LAST_NAMES = ['Kouassi', 'Traoré', 'Koné', 'Bamba', 'Diallo', 'N’Guessan', 'Ouattara', 'Touré', 'Coulibaly', 'Yapi'];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (self::PLANS as $i => [$key, $name, $max, $price, $period, $subtitle, $description, $features, $popular]) {
            SubscriptionPlan::create(['key' => $key, 'name' => $name, 'max_users' => $max, 'price' => $price, 'period' => $period, 'subtitle' => $subtitle, 'description' => $description, 'features' => $features, 'popular' => $popular, 'position' => $i]);
        }
        $plans = SubscriptionPlan::query()->get()->keyBy('key');
        $today = Subscription::referenceDate();

        foreach (Promoter::query()->orderBy('id')->get()->values() as $i => $promoter) {
            [$planKey, $status] = self::PATTERN[$i % count(self::PATTERN)];
            $plan = $plans[$planKey];
            $since = $today->subDays(40 + ($i * 37) % 420);
            $next = match (true) {
                $planKey === 'solo' || $status === 'Résilié' => null,
                $status === 'Impayé' => $today->subDays(3 + $i % 6),
                $status === 'Essai' => $today->addDays(3 + $i % 9),
                default => $today->addDays(1 + ($i * 5) % 28),
            };
            $method = self::METHODS[$i % count(self::METHODS)];
            $subscription = Subscription::create([
                'promoter_id' => $promoter->id, 'plan_key' => $status === 'Résilié' ? 'solo' : $planKey, 'previous_plan_key' => $planKey, 'status' => $status,
                'since' => $since, 'next_due' => $next, 'payment_method' => $planKey === 'solo' ? null : $method,
            ]);

            $owner = $promoter->representative ?: $promoter->name;
            $subscription->users()->create(['name' => $owner, 'role' => 'Propriétaire', 'via' => 'Compte principal', 'contact' => $promoter->phone, 'status' => 'Actif', 'owner' => true]);
            $collaborators = $planKey === 'solo' || $status === 'Résilié' ? 0 : min($plan->max_users - 1, ($planKey === 'pro' ? 4 : 1) + ($i * 3) % ($plan->max_users - 1));
            for ($j = 0; $j < $collaborators; $j++) {
                $subscription->users()->create([
                    'name' => self::FIRST_NAMES[($i * 5 + $j * 3) % 15].' '.self::LAST_NAMES[($i + $j * 7) % 10], 'role' => Subscription::ROLES[($i + $j) % 4],
                    'via' => ($i + $j) % 3 ? 'Invité par SMS' : 'Invité par e-mail', 'status' => $j === $collaborators - 1 && $i % 2 ? 'Invitation envoyée' : 'Actif',
                ]);
            }

            if ($planKey !== 'solo' && $status !== 'Essai') {
                for ($k = 0; $k < 3; $k++) {
                    $date = ($next ?? $today->subDays(10))->subDays(30 * ($k + ($status === 'Impayé' ? 0 : 1)));
                    $subscription->invoices()->create([
                        'reference' => 'ABO-'.$date->format('Ym').'-'.sprintf('%04d', 100 + $i), 'date' => $date, 'amount' => $plan->price,
                        'label' => "Formule {$plan->name}", 'status' => $status === 'Impayé' && $k === 0 ? 'Échouée' : 'Payée',
                    ]);
                }
            }

            $subscription->activities()->create(['date' => $since, 'text' => $planKey === 'solo' ? 'Compte créé en formule Solo' : "Souscription à la formule {$plan->name}".($status === 'Essai' ? ' · essai de 14 jours' : '')]);
            match ($status) {
                'Impayé' => $subscription->activities()->create(['date' => $next, 'text' => "Échec du prélèvement {$method}"]),
                'Suspendu' => $subscription->activities()->create(['date' => $today->subDays(5), 'text' => 'Abonnement suspendu · impayé prolongé']),
                'Résilié' => $subscription->activities()->create(['date' => $today->subDays(12), 'text' => 'Abonnement résilié · retour à la formule Solo']),
                default => null,
            };
        }
    }
}
