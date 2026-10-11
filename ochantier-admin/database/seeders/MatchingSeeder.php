<?php

namespace Database\Seeders;

use App\Models\MatchingCycle;
use App\Models\MatchingNotification;
use App\Models\MatchingSelection;
use App\Models\Need;
use App\Models\NeedOffer;
use App\Support\MatchingEngine;
use Illuminate\Database\Seeder;

/**
 * Historique du Matching de la maquette : dix cycles, dernier calcul de chaque offre ouverte,
 * deux sélections soumises (dont une à corriger) et les notifications récentes.
 */
class MatchingSeeder extends Seeder
{
    /**
     * Jour (J±n), périmètre, besoins, ouvriers, recommandations, sélections, validations, affectations, réussis, échecs, durée (s), initiateur, statut, algorithme.
     *
     * @var array<int, array<int, mixed>>
     */
    private const CYCLES = [
        [-58, 'Tous les besoins éligibles', 14, 212, 96, 31, 27, 24, 21, 2, 48, 'Système (planifié)', 'Terminé', 'MX-2.2'],
        [-45, 'Chantier Tour Horizon', 3, 64, 22, 8, 7, 6, 6, 0, 12, 'A. Koné', 'Terminé', 'MX-2.2'],
        [-38, 'Besoins urgents', 5, 118, 40, 12, 10, 9, 7, 1, 21, 'N. Yao', 'Terminé', 'MX-2.2'],
        [-30, 'Métier Électricien', 4, 58, 26, 9, 8, 8, 7, 1, 15, 'M. Diabaté', 'Partiellement terminé', 'MX-2.3'],
        [-22, 'Tous les besoins éligibles', 16, 238, 112, 36, 31, 28, 24, 3, 52, 'Système (planifié)', 'Terminé', 'MX-2.3'],
        [-15, 'Zone Abidjan Nord-Ouest', 6, 91, 38, 11, 9, 8, 6, 2, 19, 'A. Koné', 'Terminé', 'MX-2.3'],
        [-9, 'Promoteur SICOGI', 4, 72, 29, 7, 0, 0, 0, 0, 6, 'N. Yao', 'Échec', 'MX-2.3'],
        [-6, 'Besoins urgents', 3, 55, 21, 6, 5, 4, 2, 0, 11, 'N. Yao', 'Terminé', 'MX-2.3'],
        [-2, 'Tous les besoins éligibles', 12, 205, 88, 18, 12, 9, 3, 0, 44, 'Système (planifié)', 'Terminé', 'MX-2.3'],
        [-1, 'Chantier Cité Belle Rive', 2, 40, 14, 3, 0, 0, 0, 0, 8, 'S. Kouamé', 'Annulé', 'MX-2.3'],
    ];

    private const TIMES = ['06:00', '09:42', '14:15', '10:08', '06:00', '11:30', '16:05', '08:50', '06:00', '15:20'];

    /**
     * Type, destinataire, canal, contenu, action associée.
     *
     * @var array<int, array<int, string>>
     */
    private const NOTIFICATIONS = [
        ['Résultat de Matching disponible', 'N. Yao', 'Notification interne', 'Cycle CYC-2026-039 : 88 recommandations pour 12 besoins.', 'Consulter les résultats'],
        ['Sélection soumise', 'Responsable Matching', 'E-mail', 'Sélection de 2 profils soumise à validation.', 'Valider'],
        ['Conflit détecté', 'N. Yao', 'Notification interne', 'Chevauchement de planning sur un profil sélectionné.', 'Voir le profil'],
        ['Proposition d’affectation créée', 'Module Affectations', 'Notification interne', '3 propositions transmises aux Affectations.', 'Suivre'],
        ['Affectation confirmée', 'N. Yao', 'Notification push', 'Un ouvrier issu du Matching a confirmé.', 'Voir'],
        ['Besoin sans résultat', 'Commerciaux Abidjan Sud', 'SMS', 'Aucun profil compatible pour un besoin de soudeur.', 'Relancer'],
        ['Score recalculé', 'N. Yao', 'Notification interne', 'Score recalculé après mise à jour de la disponibilité.', 'Voir le match'],
        ['Matching expiré', 'N. Yao', 'Notification interne', 'Résultats de plus de 3 jours à relancer.', 'Relancer'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $today = Need::referenceDate();
        $cycles = [];
        foreach (self::CYCLES as $i => [$day, $scope, $needs, $workers, $rec, $sel, $val, $aff, $ok, $ko, $duration, $by, $status, $algorithm]) {
            $cycles[] = MatchingCycle::create([
                'reference' => 'CYC-2026-0'.(31 + $i), 'ran_at' => $today->addDays($day)->format('Y-m-d').' '.self::TIMES[$i], 'scope' => $scope,
                'needs' => $needs, 'workers' => $workers, 'recommendations' => $rec, 'selections' => $sel, 'validations' => $val, 'assignments' => $aff, 'successes' => $ok, 'failures' => $ko,
                'duration' => $duration, 'initiator' => $by, 'status' => $status, 'algorithm' => $algorithm,
                'zone' => str_starts_with($scope, 'Zone') ? substr($scope, 5) : 'Toutes zones',
                'trade' => str_starts_with($scope, 'Métier') ? mb_substr($scope, 7) : 'Tous métiers',
            ]);
        }

        $offers = NeedOffer::query()->whereNull('manual_status')->orderBy('id')->get();
        foreach ($offers as $offer) {
            $h = crc32("offer-{$offer->id}");
            $cycle = $cycles[8 - $h % 3];
            $offer->update(['last_cycle' => $cycle->reference, 'last_matched_on' => $today->subDays($h % 6), 'last_matched_by' => $cycle->initiator]);
        }

        $engine = new MatchingEngine;
        foreach ([1, 3] as $k => $index) {
            $offer = $engine->offer($offers[$index]->id);
            $keys = array_map(fn (array $x): string => $x['p']['key'], array_slice($engine->results($offer)['ok'], 0, min(2, $offer->stats()['rest'])));
            MatchingSelection::create([
                'need_offer_id' => $offer->id, 'main' => $keys, 'replacements' => [],
                'status' => $k ? 'Correction demandée' : 'En attente de validation', 'author' => $k ? 'A. Koné' : 'M. Diabaté',
                'updated_on' => $today->subDay(), 'note' => $k ? 'Vérifier la disponibilité du 2e profil.' : null,
            ]);
        }

        foreach (self::NOTIFICATIONS as $i => [$type, $recipient, $channel, $content, $action]) {
            MatchingNotification::create([
                'type' => $type, 'recipient' => $recipient, 'channel' => $channel, 'sent_at' => $today->subDays($i)->format('Y-m-d').' 0'.(8 + $i % 2).':'.(10 + $i * 5),
                'content' => $content, 'action' => $action, 'status' => $i === 5 ? 'Partiellement envoyée' : 'Envoyée',
            ]);
        }
    }
}
