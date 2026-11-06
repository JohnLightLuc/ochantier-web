<?php

namespace App\Support\Admin;

use App\Enums\ClaimStatus;
use App\Enums\UserType;
use App\Models\Claim;
use App\Models\CommercialProfile;
use App\Models\PromoterProfile;
use App\Models\User;
use App\Models\Worker;
use App\Support\ClaimFiling;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;

/**
 * Réclamations du Back Office : une ligne par réclamation, les plus urgentes en tête.
 */
class ClaimDirectory
{
    public const CLAIMANT_TYPES = ['promoter_profile' => 'Promoteur', 'worker' => 'Ouvrier', 'commercial_profile' => 'Commercial'];

    /**
     * @param  array<string, mixed>  $filters  search, status, category, priority, claimant_type, overdue
     * @return Builder<Claim>
     */
    public function claims(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;
        $open = array_map(fn (ClaimStatus $status) => $status->value, ClaimFiling::OPEN);

        return Claim::query()
            ->with(['site', 'ownerAdmin', 'claimant' => fn (MorphTo $morph) => $morph->morphWith([PromoterProfile::class => ['user'], CommercialProfile::class => ['user']])])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['claimant_type'] ?? null, fn (Builder $query, string $type) => $query->where('claimant_type', $type))
            ->when($filters['overdue'] ?? false, fn (Builder $query) => $query->whereIn('status', $open)->where('due_at', '<', now()))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', $search)
                ->orWhere('subject', 'ilike', $search)
                ->orWhereHas('site', fn (Builder $query) => $query->where('label', 'ilike', $search))))
            ->orderByRaw("case when status in ('".implode("','", $open)."') then 0 else 1 end")
            ->orderByRaw("case priority when 'critique' then 0 when 'haute' then 1 when 'normale' then 2 else 3 end")
            ->orderBy('due_at')
            ->orderBy('id');
    }

    /**
     * @return array{statuses: array<string, int>, overdue: int}
     */
    public function counts(): array
    {
        $statuses = Claim::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'statuses' => collect(ClaimStatus::cases())->mapWithKeys(fn (ClaimStatus $status) => [$status->value => (int) $statuses->get($status->value, 0)])->all(),
            'overdue' => Claim::query()->whereIn('status', ClaimFiling::OPEN)->where('due_at', '<', now())->count(),
        ];
    }

    /**
     * Demandeur retrouvé par son numéro.
     */
    public function findParty(string $type, PhoneNumber $phone): Model
    {
        $party = match ($type) {
            'worker' => Worker::query()->where('dial_code', $phone->dialCode)->where('phone_number', $phone->number)->first(),
            'promoter_profile' => $this->user(UserType::Promoter, $phone)?->promoterProfile,
            default => $this->user(UserType::Commercial, $phone)?->commercialProfile,
        };

        return $party ?? throw ValidationException::withMessages(['phone_number' => 'Aucun '.mb_strtolower(self::CLAIMANT_TYPES[$type]).' avec ce numéro.']);
    }

    private function user(UserType $type, PhoneNumber $phone): ?User
    {
        return User::query()->where('user_type', $type)->where('dial_code', $phone->dialCode)->where('phone_number', $phone->number)->first();
    }

    public function partyName(?Model $party): ?string
    {
        return match (true) {
            $party instanceof PromoterProfile, $party instanceof CommercialProfile => trim($party->user?->first_name.' '.$party->user?->last_name) ?: $party->user?->phone_number,
            $party instanceof Worker => trim($party->first_name.' '.$party->last_name),
            default => null,
        };
    }
}
