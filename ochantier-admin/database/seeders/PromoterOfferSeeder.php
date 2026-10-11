<?php

namespace Database\Seeders;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedUrgency;
use App\Enums\PromoterOfferProfileStatus;
use App\Models\Need;
use App\Models\PromoterOffer;
use App\Models\Site;
use App\Support\WorkerPool;
use Illuminate\Database\Seeder;

/**
 * Offres des promoteurs de la maquette : profils choisis dans l’application promoteur, à différents stades
 * (à contacter, contactés, validés, refusés) ; une offre est déjà comblée (acompte payé).
 */
class PromoterOfferSeeder extends Seeder
{
    /**
     * Chantier, métier, spécialité, urgence, envoi (J±n), démarrage (J+n), durée (jours), coût journalier,
     * statuts des profils (p : en attente, c : contacté, a : validé, r : refusé, o : refusé par l’ouvrier), message.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int, 8: string, 9: string}>
     */
    private const OFFERS = [
        ['Résidence Les Oliviers', 'Maçon', 'Maçonnerie générale', 'Élevé', -2, 5, 30, 12000, 'ppa', 'Profils déjà appréciés sur la phase gros œuvre.'],
        ['Tour Horizon', 'Électricien', 'Courant fort', 'Urgent', -1, 3, 14, 13000, 'cop', 'Besoin rapide pour la mise en service du TGBT.'],
        ['Cité Belle Rive', 'Plombier', 'Sanitaires', 'Normal', -4, 10, 21, 11000, 'aao', 'Pose des sanitaires des bâtiments B et C.'],
        ['Complexe scolaire SA', 'Peintre', 'Peinture intérieure', 'Normal', -6, 2, 20, 9500, 'aa', 'Finitions des salles de classe.'],
        ['Centre de santé d’Anyama', 'Coffreur', 'Coffrage bois', 'Élevé', -3, 8, 45, 13000, 'ccpp', 'Équipe de coffrage pour les voiles du R+1.'],
        ['Marché de Koumassi', 'Menuisier', 'Menuiserie bois', 'Faible', -8, 3, 30, 12000, 'aaa', 'Pose des étals en bois.'],
        ['Entrepôt logistique Vridi', 'Soudeur', 'Soudure à l’arc', 'Normal', -2, 12, 60, 12500, 'pr', 'Charpente métallique du quai de chargement.'],
        ['Tour Horizon', 'Carreleur', 'Carrelage sol', 'Critique', 0, 2, 10, 10000, 'ppo', 'Remplacement de l’équipe carrelage du hall.'],
    ];

    private const STATUSES = ['p' => PromoterOfferProfileStatus::Pending, 'c' => PromoterOfferProfileStatus::Contacted, 'a' => PromoterOfferProfileStatus::Accepted, 'r' => PromoterOfferProfileStatus::Refused, 'o' => PromoterOfferProfileStatus::RefusedByWorker];

    private const SOURCES = ['Candidature', 'Matching', 'Manuel'];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $pool = new WorkerPool;
        $sites = Site::query()->get()->keyBy('name');
        $today = Need::referenceDate();

        foreach (self::OFFERS as $i => [$siteName, $trade, $specialty, $urgency, $sent, $start, $duration, $cost, $statuses, $message]) {
            $site = $sites->get($siteName) ?? $sites->values()[$i % $sites->count()];
            $number = 51 + $i;
            $sentOn = $today->addDays($sent);
            $allAccepted = ! preg_match('/[^a]/', $statuses);
            $offer = PromoterOffer::create([
                'slug' => "ofp-2026-0{$number}",
                'reference' => "OFP-2026-0{$number}",
                'site_id' => $site->id,
                'trade' => $trade,
                'specialty' => $specialty,
                'urgency' => NeedUrgency::fromLabel($urgency),
                'sent_on' => $sentOn,
                'starts_on' => $today->addDays($start),
                'ends_on' => $today->addDays($start + $duration),
                'daily_cost' => $cost,
                'message' => $message,
                'notified_on' => $allAccepted ? $sentOn->addDays(2) : null,
                'payment_reference' => $i === 3 ? 'Mobile Money · MM-58214' : null,
            ]);

            $people = $pool->forTrade($trade);
            $h = crc32($offer->slug);
            foreach (str_split($statuses) as $q => $code) {
                $p = $people[($q * 3 + $h) % count($people)];
                $offer->profiles()->create([
                    'worker_id' => $p['workerId'], 'name' => $p['name'], 'trade' => $trade, 'commune' => $p['commune'], 'experience' => $p['experience'],
                    'rating' => $p['rating'], 'daily_cost' => $p['cost'], 'availability' => $p['availability'], 'status' => self::STATUSES[$code],
                    'source' => self::SOURCES[($q + $i) % 3],
                    'contacted_on' => $code !== 'p' ? $sentOn->addDay() : null,
                    'channel' => $code !== 'p' ? 'Appel téléphonique' : null,
                    'reason' => match ($code) {
                        'o' => 'Indisponible sur la période (déjà en mission)',
                        'r' => 'Coût journalier supérieur au budget du promoteur',
                        default => null,
                    },
                    'assignment_reference' => $code === 'a' ? 'AFF-2026-'.(1400 + $i * 10 + $q) : null,
                ]);
            }

            $offer->activities()->create([
                'occurred_at' => $sentOn->format('Y-m-d').' 10:15', 'author' => $site->promoter_name, 'category' => NeedActivityCategory::Creation,
                'action' => 'a envoyé l’offre à '.strlen($statuses).' profil(s) sélectionné(s)', 'new_value' => PromoterOffer::TO_PROCESS, 'comment' => $message, 'origin' => 'Application Promoteur',
            ]);
            if ($allAccepted) {
                $offer->activities()->create([
                    'occurred_at' => $sentOn->addDays(2)->format('Y-m-d').' 09:00', 'author' => 'Système', 'category' => NeedActivityCategory::Notification,
                    'action' => 'a notifié automatiquement le promoteur', 'old_value' => 'Traitement terminé', 'new_value' => PromoterOffer::DEPOSIT, 'origin' => 'Système',
                ]);
            }
            if ($offer->payment_reference) {
                $offer->activities()->create([
                    'occurred_at' => $sentOn->addDays(3)->format('Y-m-d').' 16:20', 'author' => 'N. Yao', 'category' => NeedActivityCategory::Validation,
                    'action' => 'a validé le paiement de l’acompte', 'old_value' => PromoterOffer::DEPOSIT, 'new_value' => PromoterOffer::DONE, 'comment' => $offer->payment_reference, 'origin' => 'Back-office',
                ]);
            }
        }
    }
}
