<?php

namespace App\Support\Promoter;

use App\Enums\ClaimCategory;
use App\Models\Assignment;
use App\Models\Claim;
use App\Models\PromoterProfile;
use App\Support\ClaimFiling;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Réclamations du compte promoteur : dépôt, liste et suivi. Le traitement se fait au Back Office.
 */
class PromoterClaims
{
    /**
     * @var list<ClaimCategory>
     */
    public const CATEGORIES = [ClaimCategory::Paiement, ClaimCategory::Pointage, ClaimCategory::Prestation, ClaimCategory::Comportement, ClaimCategory::Securite, ClaimCategory::Materiel, ClaimCategory::Remplacement, ClaimCategory::Autre];

    public function __construct(private ClaimFiling $filing) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(PromoterProfile $profile): array
    {
        return Claim::query()
            ->whereMorphedTo('claimant', $profile)
            ->with('site')
            ->latest()
            ->orderBy('id')
            ->get()
            ->map(fn (Claim $claim) => $this->row($claim))
            ->all();
    }

    /**
     * @return array{categories: list<array{value: string, label: string}>, sites: list<array{id: string, title: string}>}
     */
    public function options(PromoterProfile $profile): array
    {
        return [
            'categories' => array_map(fn (ClaimCategory $category) => ['value' => $category->value, 'label' => $category->label()], self::CATEGORIES),
            'sites' => $profile->sites()->orderBy('label')->get(['id', 'label'])->map(fn ($site) => ['id' => $site->id, 'title' => $site->label])->all(),
        ];
    }

    /**
     * @param  array{category: string, subject: string, description: string, site_id?: string|null, assignment_id?: string|null}  $data
     */
    public function file(PromoterProfile $profile, array $data): Claim
    {
        if (isset($data['site_id']) && ! $profile->sites()->whereKey($data['site_id'])->exists()) {
            throw ValidationException::withMessages(['site_id' => 'Chantier introuvable.']);
        }

        if (isset($data['assignment_id']) && ! Assignment::query()->whereKey($data['assignment_id'])->whereHas('mission.site', fn ($query) => $query->where('promoter_profile_id', $profile->getKey()))->exists()) {
            throw ValidationException::withMessages(['assignment_id' => 'Ouvrier introuvable sur vos chantiers.']);
        }

        return $this->filing->file($profile, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Claim $claim): array
    {
        return [
            'id' => $claim->id,
            'reference' => $claim->reference,
            'category' => ['value' => $claim->category, 'label' => ClaimCategory::tryFrom($claim->category)?->label() ?? $claim->category],
            'subject' => $claim->subject,
            'description' => $claim->description,
            'site' => $claim->site ? ['id' => $claim->site->id, 'title' => $claim->site->label] : null,
            'status' => ['value' => $claim->status->value, 'label' => $claim->status->label()],
            'answer' => $claim->resolution,
            'created_at' => $claim->created_at?->toIso8601String(),
            'date_label' => $claim->created_at ? $this->date($claim->created_at) : null,
        ];
    }

    private function date(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }
}
