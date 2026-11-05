<?php

namespace App\Support\Matching;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\SuggestionStatus;
use App\Enums\WorkerReservationStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Document;
use App\Models\MatchingWeightSet;
use App\Models\Need;
use App\Models\Setting;
use App\Models\Worker;
use App\Models\WorkerTrade;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de matching : pour un besoin, écarte les ouvriers qui ne peuvent pas être proposés, puis classe les autres
 * selon le barème actif. Chaque résultat garde le détail de son calcul pour être expliqué à l’opérateur.
 *
 * Les exclusions absolues ne sont jamais levées ; les autres pourront l’être par une dérogation.
 */
class MatchingEngine
{
    public const CRITERIA = [
        'metier' => 'Métier et spécialité',
        'disponibilite' => 'Disponibilité et planning',
        'experience' => 'Expérience',
        'proximite' => 'Proximité du chantier',
        'cout' => 'Compatibilité du coût',
        'qualite_profil' => 'Qualité du profil',
        'note' => 'Note et fiabilité',
        'historique' => 'Historique',
    ];

    /**
     * @return Collection<int, array<string, mixed>> Évaluations triées : proposables d’abord, par score.
     */
    public function evaluate(Need $need, MatchingWeightSet $weights): Collection
    {
        $need->loadMissing(['site.commune', 'requestedWorkers']);
        $start = CarbonImmutable::parse($need->start_date);
        $end = CarbonImmutable::parse($need->end_date ?? $start->addDays(max(0, $need->duration_days - 1)));
        $workers = $this->candidates($need);
        $ids = $workers->pluck('id')->all();
        $period = '['.$start->toDateString().','.$end->toDateString().']';
        $busy = $this->busyWorkers($ids, $period);
        $refused = $need->suggestions()->whereIn('status', [SuggestionStatus::Refuse, SuggestionStatus::Ecarte])->pluck('worker_id')->flip();
        $requested = $need->requestedWorkers->pluck('worker_id')->flip();
        $rules = $weights->exclusion_rules ?? [];
        $levels = $this->levels();

        return $workers
            ->map(fn (Worker $worker) => $this->score($need, $worker, $weights, $rules, $levels, $busy->has($worker->id), $refused->has($worker->id), $requested->has($worker->id)))
            ->sortBy([
                fn (array $a, array $b) => $b['eligible'] <=> $a['eligible'],
                fn (array $a, array $b) => $b['requested'] <=> $a['requested'],
                fn (array $a, array $b) => $b['score'] <=> $a['score'],
                // À score égal, celui qui attend une mission depuis le plus longtemps passe d’abord.
                fn (array $a, array $b) => ($a['last_assignment_at'] ?? '') <=> ($b['last_assignment_at'] ?? ''),
            ])
            ->values();
    }

    /**
     * Ouvriers du métier, validés ou suspendus (ces derniers pour expliquer leur absence), avec leur distance au chantier.
     *
     * @return Collection<int, Worker>
     */
    private function candidates(Need $need): Collection
    {
        return Worker::query()
            ->whereIn('verification_status', [WorkerVerificationStatus::Valide, WorkerVerificationStatus::Suspendu])
            ->whereNull('merged_into_worker_id')
            ->whereHas('workerTrades', fn ($query) => $query->where('trade_id', $need->trade_id))
            ->select('workers.*')
            ->selectRaw('ST_Distance(workers.residence_location, (select location from sites where id = ?)) / 1000 as distance_km', [$need->site_id])
            ->with(['workerTrades', 'specialties', 'residenceCommune', 'documents.documentType'])
            ->get();
    }

    /**
     * Ouvriers déjà engagés sur la période : affectation confirmée ou réservation active.
     *
     * @param  list<string>  $ids
     * @return Collection<string, mixed>
     */
    private function busyWorkers(array $ids, string $period): Collection
    {
        $assigned = DB::table('assignments')->whereIn('worker_id', $ids)->whereIn('status', [AssignmentStatus::Confirmee->value, AssignmentStatus::Active->value])->whereRaw('period && ?::daterange', [$period])->pluck('worker_id');
        $reserved = DB::table('worker_reservations')->whereIn('worker_id', $ids)->where('status', WorkerReservationStatus::Active->value)->whereRaw('period && ?::daterange', [$period])->pluck('worker_id');

        return $assigned->concat($reserved)->flip();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, int>  $levels
     * @return array<string, mixed>
     */
    private function score(Need $need, Worker $worker, MatchingWeightSet $weights, array $rules, array $levels, bool $busy, bool $refused, bool $requested): array
    {
        /** @var WorkerTrade $trade */
        $trade = $worker->workerTrades->firstWhere('trade_id', $need->trade_id);
        $km = $worker->distance_km !== null ? round((float) $worker->distance_km, 1) : null;
        $sameCommune = $need->site->commune_id && $need->site->commune_id === $worker->residence_commune_id;
        $budget = $need->indicative_daily_budget ?: null;
        $rate = $worker->estimated_daily_rate;
        $years = (int) $trade->years_experience;
        $minimum = (int) $need->min_experience_years;
        $radius = (float) ($rules['rayon_km'] ?? 20);
        $radiusMax = (float) ($rules['rayon_max_km'] ?? 40);
        $toleranceMax = (float) ($rules['tolerance_budget_max_pct'] ?? 25);
        $exclusions = [];
        $warnings = [];

        $exclude = function (string $criterion, string $expected, string $found, bool $absolute = false) use (&$exclusions): void {
            $exclusions[] = compact('criterion', 'expected', 'found', 'absolute');
        };

        if ($worker->verification_status !== WorkerVerificationStatus::Valide) {
            $exclude('statut', 'Ouvrier validé', $worker->verification_status->label(), true);
        }

        if ($busy) {
            $exclude('planning', 'Libre sur la période', 'Déjà engagé sur ces dates', true);
        }

        if ($refused) {
            $exclude('refus', 'Pas de refus sur ce besoin', 'A refusé ou a été écarté', true);
        }

        if ($rate === null) {
            $exclude('tarif', 'Tarif renseigné', 'Tarif manquant', true);
        }

        if ($worker->availability_status === AvailabilityStatus::Indisponible) {
            $exclude('disponibilite', 'Disponible', 'Indisponible');
        }

        if ($km !== null && $km > $radiusMax) {
            $exclude('distance', "{$radiusMax} km au plus", "{$km} km");
        }

        if ($budget && $rate !== null && $rate > $budget * (1 + $toleranceMax / 100)) {
            $exclude('cout', number_format($budget * (1 + $toleranceMax / 100), 0, ',', ' ').' FCFA/j au plus', number_format($rate, 0, ',', ' ').' FCFA/j');
        }

        if (($rules['identite_verifiee'] ?? true) && ! $this->hasValidIdentity($worker)) {
            $exclude('identite', 'Pièce d’identité vérifiée et en cours de validité', 'Pièce absente, à vérifier ou expirée');
        }

        if ($minimum && $years < $minimum) {
            $warnings[] = "Expérience inférieure au minimum ({$years} ans pour {$minimum})";
        }

        if ($km === null) {
            $warnings[] = 'Position de l’ouvrier inconnue : proximité estimée sur la commune';
        } elseif ($km > $radius) {
            $warnings[] = "Au-delà du rayon prioritaire ({$radius} km)";
        }

        if ($budget && $rate !== null && $rate > $budget) {
            $warnings[] = 'Coût supérieur au budget indicatif ('.round(($rate / $budget - 1) * 100).' %)';
        }

        $hasSpecialty = ! $need->specialty_id || $worker->specialties->contains('id', $need->specialty_id);
        $values = [
            'metier' => [$trade->is_primary ? ($hasSpecialty ? 100 : 80) : ($hasSpecialty ? 75 : 60), ($trade->is_primary ? 'Métier principal' : 'Métier secondaire').($hasSpecialty ? '' : ', sans la spécialité')],
            'disponibilite' => [match ($worker->availability_status) {
                AvailabilityStatus::Disponible => 100,
                AvailabilityStatus::Occupe => 60,
                default => 0,
            }, (string) $worker->availability_status?->label()],
            'experience' => [$minimum ? min(100, (int) round($years / $minimum * 100)) : 100, "{$years} ans".($minimum ? " pour {$minimum} demandés" : '')],
            'proximite' => [$km !== null ? max(0, (int) round(100 - max(0, $km - 3) / max(1, $radiusMax) * 100)) : ($sameCommune ? 80 : 50), $km !== null ? "{$km} km du chantier" : ($sameCommune ? 'Même commune' : 'Distance inconnue')],
            'cout' => [! $budget || $rate === null || $rate <= $budget ? 100 : max(0, (int) round(100 - ($rate - $budget) / $budget * 400)), $rate !== null ? number_format($rate, 0, ',', ' ').' FCFA/j' : 'Tarif manquant'],
            'qualite_profil' => [$this->profileQuality($worker), 'Dossier et pièces'],
            'note' => $this->reliability($worker),
            'historique' => $this->history($worker),
        ];

        $criteria = [];
        $total = 0.0;

        foreach (self::CRITERIA as $key => $label) {
            $weight = (float) ($weights->weights[$key] ?? 0);
            [$value, $detail] = $values[$key];
            $contribution = round($value * $weight, 2);
            $total += $contribution;
            $criteria[] = ['key' => $key, 'label' => $label, 'weight' => (int) round($weight * 100), 'value' => $value, 'contribution' => $contribution, 'detail' => $detail];
        }

        $score = round($total, 1);

        return [
            'worker' => $worker,
            'eligible' => $exclusions === [],
            'score' => $score,
            'level' => $this->level($score, $levels),
            'requested' => $requested,
            'distance_km' => $km,
            'criteria' => $criteria,
            'exclusions' => $exclusions,
            'warnings' => $warnings,
            'last_assignment_at' => $worker->last_assignment_at?->toIso8601String(),
        ];
    }

    private function hasValidIdentity(Worker $worker): bool
    {
        return $worker->documents->contains(fn (Document $document) => $document->documentType?->is_identity
            && $document->verification_status?->value === 'valide'
            && ($document->expires_at === null || ! $document->expires_at->isPast()));
    }

    private function profileQuality(Worker $worker): int
    {
        $checks = [
            $this->hasValidIdentity($worker),
            $worker->photo_media_id !== null,
            $worker->residence_commune_id !== null,
            $worker->estimated_daily_rate !== null,
            $worker->emergency_contact_phone !== null,
        ];

        return (int) round(count(array_filter($checks)) / count($checks) * 100);
    }

    /**
     * Un ouvrier sans avis reçoit une valeur neutre, pour ne pas écarter les nouveaux inscrits.
     *
     * @return array{0: int, 1: string}
     */
    private function reliability(Worker $worker): array
    {
        if ($worker->rating_avg === null) {
            return [60, 'Pas encore d’avis'];
        }

        $acceptance = $worker->acceptance_rate !== null ? (float) $worker->acceptance_rate : 100;

        return [(int) round((float) $worker->rating_avg / 5 * 70 + $acceptance * 0.3), str_replace('.', ',', (string) round((float) $worker->rating_avg, 1)).' / 5 sur '.$worker->rating_count.' avis'];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function history(Worker $worker): array
    {
        if ((int) $worker->missions_completed === 0) {
            return [50, 'Première mission'];
        }

        $presence = $worker->attendance_rate !== null ? (float) $worker->attendance_rate : 100;

        return [(int) round(min(100, $worker->missions_completed * 4) * $presence / 100), $worker->missions_completed.' mission(s), présence '.round($presence).' %'];
    }

    /**
     * @return array<string, int>
     */
    private function levels(): array
    {
        $value = Setting::query()->whereKey('matching.levels')->value('value');

        return is_array($value) && $value !== [] ? $value : ['excellent' => 85, 'tres_bon' => 75, 'bon' => 65, 'acceptable' => 55, 'faible' => 40];
    }

    /**
     * @param  array<string, int>  $levels
     */
    private function level(float $score, array $levels): string
    {
        arsort($levels);

        foreach ($levels as $key => $threshold) {
            if ($score >= $threshold) {
                return $key;
            }
        }

        return 'non_recommande';
    }
}
