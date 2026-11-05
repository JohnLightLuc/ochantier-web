<?php

namespace App\Support\Admin;

use App\Enums\CommercialObjectiveIndicator;
use App\Enums\ZoneRequestStatus;
use App\Models\Admin;
use App\Models\CommercialObjective;
use App\Models\CommercialProfile;
use App\Models\CommercialZone;
use App\Models\CommercialZoneRequest;
use App\Models\Commune;
use App\Support\Commercial\CommercialAccount;
use App\Support\Commercial\CommercialNotifier;
use App\Support\Commercial\CommercialPerformance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Zones d’un commercial, demandes de changement envoyées depuis l’application, et objectif mensuel d’ouvriers validés.
 * Une zone retirée garde son historique, sauf si elle avait été attribuée le jour même.
 */
class CommercialTerritory
{
    public function __construct(private CommercialAccount $account, private CommercialPerformance $performance, private CommercialNotifier $notifier) {}

    /**
     * @return array{requests: list<array<string, mixed>>}
     */
    public function requests(CommercialProfile $commercial): array
    {
        return ['requests' => $commercial->zoneRequests()->with('decidedByAdmin')->latest()->limit(10)->get()->map(fn (CommercialZoneRequest $request) => [
            ...$this->account->request($request),
            'decision_comment' => $request->decision_comment,
            'decided_by' => $request->decidedByAdmin ? trim($request->decidedByAdmin->first_name.' '.$request->decidedByAdmin->last_name) : null,
            'decided_at' => $request->decided_at?->toIso8601String(),
        ])->values()->all()];
    }

    /**
     * Ajoute une commune. En zone principale, l’ancienne principale devient secondaire.
     */
    public function addZone(CommercialProfile $commercial, string $communeId, bool $primary, Admin $admin, Request $request): void
    {
        $zones = $this->current($commercial);

        if ($zones->contains('commune_id', $communeId)) {
            throw ValidationException::withMessages(['commune_id' => 'Cette commune est déjà attribuée à ce commercial.']);
        }

        $primaryId = $primary ? $communeId : ($zones->firstWhere('is_primary', true)?->commune_id ?? $communeId);
        $this->apply($commercial, [...$zones->pluck('commune_id')->all(), $communeId], $primaryId, $admin, $request);
    }

    public function removeZone(CommercialProfile $commercial, string $communeId, Admin $admin, Request $request): void
    {
        $zones = $this->current($commercial);
        $zone = $zones->firstWhere('commune_id', $communeId);

        if (! $zone) {
            throw ValidationException::withMessages(['commercial' => 'Cette commune n’est pas attribuée à ce commercial.']);
        }

        if ($zone->is_primary) {
            throw ValidationException::withMessages(['commercial' => 'Choisissez d’abord une autre zone principale.']);
        }

        $this->apply($commercial, $zones->pluck('commune_id')->reject(fn (string $id) => $id === $communeId)->values()->all(), $zones->firstWhere('is_primary', true)->commune_id, $admin, $request);
    }

    /**
     * Accepte la demande : ses communes remplacent les zones actuelles. Zone principale : celle choisie, sinon
     * l’actuelle si elle reste demandée, sinon la première.
     */
    public function accept(CommercialProfile $commercial, CommercialZoneRequest $zoneRequest, ?string $primaryId, ?string $comment, Admin $admin, Request $request): void
    {
        $communes = $this->pending($zoneRequest)->commune_ids;

        if ($primaryId && ! in_array($primaryId, $communes, true)) {
            throw ValidationException::withMessages(['primary_commune_id' => 'La zone principale doit faire partie des communes demandées.']);
        }

        $current = $this->current($commercial)->firstWhere('is_primary', true)?->commune_id;
        $primary = $primaryId ?? (in_array($current, $communes, true) ? $current : $communes[0]);

        DB::transaction(function () use ($commercial, $zoneRequest, $communes, $primary, $comment, $admin, $request): void {
            $this->apply($commercial, $communes, $primary, $admin, $request);
            $this->decide($zoneRequest, ZoneRequestStatus::Acceptee, $comment, $admin);
        });

        $names = Commune::query()->whereKey($communes)->orderBy('name')->pluck('name')->implode(' · ');
        $this->notifier->accountEvent($commercial, 'zone.accepted', 'Changement de zone accepté', "Vos zones sont désormais : {$names}.");
    }

    public function refuse(CommercialProfile $commercial, CommercialZoneRequest $zoneRequest, string $reason, Admin $admin, Request $request): void
    {
        $this->decide($this->pending($zoneRequest), ZoneRequestStatus::Refusee, $reason, $admin);

        AdminAudit::record($admin, 'commercials', 'commercial.zone_request_refused', $request, $commercial, null, ['reason' => $reason]);
        $this->notifier->accountEvent($commercial, 'zone.refused', 'Changement de zone refusé', $reason);
    }

    /**
     * Objectifs mensuels récents, avec ce qui peut encore être modifié.
     *
     * @return list<array<string, mixed>>
     */
    public function objectives(CommercialProfile $commercial): array
    {
        return array_map(fn (array $objective) => [...$objective, 'can_edit' => $objective['period']['end'] >= today()->toDateString()], $this->performance->objectiveHistory($commercial, 12));
    }

    /**
     * Fixe ou remplace l’objectif d’un mois en cours ou à venir (mois AAAA-MM).
     */
    public function saveObjective(CommercialProfile $commercial, string $month, int $target, ?string $comment, Admin $admin, Request $request): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        if ($start->endOfMonth()->lt(today())) {
            throw ValidationException::withMessages(['month' => 'Ce mois est terminé : son objectif ne peut plus être modifié.']);
        }

        $objective = CommercialObjective::query()->firstOrNew([
            'commercial_id' => $commercial->getKey(),
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'indicator' => CommercialObjectiveIndicator::OuvriersValides,
        ]);
        $before = $objective->exists ? ['target' => $objective->target_value] : null;
        $objective->fill(['target_value' => $target, 'comment' => $comment, 'set_by_admin_id' => $admin->getKey()])->save();

        AdminAudit::record($admin, 'commercials', 'commercial.objective_set', $request, $commercial, $before, ['month' => $month, 'target' => $target]);
        $label = $start->locale('fr')->translatedFormat('F Y');
        $this->notifier->accountEvent($commercial, 'objective.set', 'Objectif mis à jour', "Objectif de {$label} : {$target} ouvrier".($target > 1 ? 's validés.' : ' validé.'));
    }

    /**
     * @param  list<string>  $communeIds
     */
    private function apply(CommercialProfile $commercial, array $communeIds, string $primaryId, Admin $admin, Request $request): void
    {
        $before = $this->state($commercial);

        DB::transaction(function () use ($commercial, $communeIds, $primaryId, $admin): void {
            $zones = $this->current($commercial);
            $removed = $zones->whereNotIn('commune_id', $communeIds)->pluck('id');

            CommercialZone::query()->whereKey($removed)->whereDate('effective_from', '>=', today())->delete();
            CommercialZone::query()->whereKey($removed)->update(['effective_to' => today()->subDay()]);

            foreach (array_diff($communeIds, $zones->pluck('commune_id')->all()) as $communeId) {
                $commercial->commercialZones()->create(['commune_id' => $communeId, 'is_primary' => false, 'assigned_by_admin_id' => $admin->getKey()]);
            }

            $this->currentQuery($commercial)->update(['is_primary' => false]);
            $this->currentQuery($commercial)->where('commune_id', $primaryId)->update(['is_primary' => true]);
        });

        AdminAudit::record($admin, 'commercials', 'commercial.zones_updated', $request, $commercial, $before, $this->state($commercial));
    }

    private function pending(CommercialZoneRequest $request): CommercialZoneRequest
    {
        if ($request->status !== ZoneRequestStatus::EnAttente) {
            throw ValidationException::withMessages(['commercial' => 'Cette demande a déjà été traitée.']);
        }

        return $request;
    }

    private function decide(CommercialZoneRequest $request, ZoneRequestStatus $status, ?string $comment, Admin $admin): void
    {
        $request->update(['status' => $status, 'decision_comment' => $comment, 'decided_by_admin_id' => $admin->getKey(), 'decided_at' => now()]);
    }

    /**
     * @return Collection<int, CommercialZone>
     */
    private function current(CommercialProfile $commercial): Collection
    {
        return $this->currentQuery($commercial)->get();
    }

    /**
     * @return Builder<CommercialZone>
     */
    private function currentQuery(CommercialProfile $commercial): Builder
    {
        return CommercialZone::query()->where('commercial_id', $commercial->getKey())
            ->whereDate('effective_from', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()));
    }

    /**
     * @return array{zones: list<string>, primary: string|null}
     */
    private function state(CommercialProfile $commercial): array
    {
        $zones = $this->current($commercial);

        return ['zones' => $zones->pluck('commune_id')->all(), 'primary' => $zones->firstWhere('is_primary', true)?->commune_id];
    }
}
