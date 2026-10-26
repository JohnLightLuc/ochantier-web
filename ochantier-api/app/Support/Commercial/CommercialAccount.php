<?php

namespace App\Support\Commercial;

use App\Enums\ZoneRequestStatus;
use App\Models\CommercialProfile;
use App\Models\CommercialZone;
use App\Models\CommercialZoneRequest;
use App\Models\Commune;
use App\Models\User;
use App\Support\Notifications\NotificationPreferences;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * « Mon profil » du commercial : informations personnelles, zone d’activité attribuée (et demande de changement),
 * activité (objectif, classement) et préférences.
 */
class CommercialAccount
{
    public function __construct(private CommercialPerformance $performance, private NotificationPreferences $preferences) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(CommercialProfile $commercial): array
    {
        $user = $commercial->user;
        $objective = $this->performance->objective($commercial);
        $ranking = $this->performance->ranking($commercial);
        $preferences = $this->preferences->forUser($user);
        $toggles = collect($preferences['alerts'])->pluck('enabled')->push(collect($preferences['channels'])->firstWhere('key', 'sms')['enabled'] ?? false);
        $pending = $this->pendingRequest($commercial);

        return [
            'name' => trim($user->first_name.' '.$user->last_name),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'initials' => mb_strtoupper(mb_substr((string) $user->first_name, 0, 1).mb_substr((string) $user->last_name, 0, 1)),
            'role' => 'Commercial terrain',
            'employee_number' => $commercial->employee_number,
            'status' => ['value' => $commercial->status->value, 'label' => 'Compte '.mb_strtolower($commercial->status->label())],
            ...PhoneNumber::of($user)?->toArray() ?? ['dial_code' => null, 'phone_number' => null, 'phone' => null],
            'email' => $user->email,
            'zones' => $this->zones($commercial)->map(fn (CommercialZone $zone) => [
                'commune' => $zone->commune->only(['id', 'name']),
                'is_primary' => $zone->is_primary,
            ])->values()->all(),
            'zone_request' => $pending ? $this->request($pending) : null,
            'activity' => [
                'objective_label' => $objective ? $objective['label'].' ouvriers validés ce mois' : 'Aucun objectif fixé ce mois',
                'ranking_label' => $ranking['label'],
            ],
            'notifications_label' => $toggles->filter()->count() === $toggles->count() ? 'Gérer mes préférences de notification' : $toggles->filter()->count().' sur '.$toggles->count().' activées',
        ];
    }

    /**
     * « Modifier mes informations » : prénom, nom et adresse e-mail.
     *
     * @param  array{first_name?: string, last_name?: string, email?: string|null}  $data
     */
    public function update(User $user, array $data): User
    {
        $user->fill(array_intersect_key($data, array_flip(['first_name', 'last_name', 'email'])));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user;
    }

    /**
     * « Demander un changement » de zone : une seule demande en attente, différente des communes actuelles.
     *
     * @param  list<string>  $communeIds
     */
    public function requestZoneChange(CommercialProfile $commercial, array $communeIds): CommercialZoneRequest
    {
        $communeIds = array_values(array_unique($communeIds));

        if ($this->pendingRequest($commercial)) {
            throw ValidationException::withMessages(['commune_ids' => 'Une demande de changement est déjà en attente de validation.']);
        }

        $current = $this->zones($commercial)->pluck('commune_id')->sort()->values()->all();

        if ($current === collect($communeIds)->sort()->values()->all()) {
            throw ValidationException::withMessages(['commune_ids' => 'Modifiez au moins une commune.']);
        }

        return $commercial->zoneRequests()->create(['commune_ids' => $communeIds, 'status' => ZoneRequestStatus::EnAttente]);
    }

    public function pendingRequest(CommercialProfile $commercial): ?CommercialZoneRequest
    {
        return $commercial->zoneRequests()->where('status', ZoneRequestStatus::EnAttente)->latest()->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function request(CommercialZoneRequest $request): array
    {
        $communes = Commune::query()->whereKey($request->commune_ids)->orderBy('name')->get(['id', 'name']);

        return [
            'id' => $request->id,
            'communes' => $communes->map->only(['id', 'name'])->values()->all(),
            'status' => ['value' => $request->status->value, 'label' => $request->status->label()],
            'label' => 'Changement demandé : '.$communes->pluck('name')->implode(' · ').'. En attente de validation.',
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /**
     * Communes attribuées en vigueur, la principale d’abord.
     *
     * @return Collection<int, CommercialZone>
     */
    private function zones(CommercialProfile $commercial): Collection
    {
        return $commercial->commercialZones()
            ->whereDate('effective_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->with('commune')
            ->orderByDesc('is_primary')
            ->get();
    }
}
