<?php

namespace App\Http\Resources\V1\Commercial;

use App\Enums\DocumentAudience;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Prospect;
use App\Support\Commercial\ProspectDossier;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Fiche « Détails du prospect » : contact, dossier (identité, activité, contact d’urgence), pièces attendues
 * et éléments manquants avant la soumission.
 *
 * @mixin Prospect
 */
class ProspectDetailResource extends ProspectResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dossier = app(ProspectDossier::class);
        $completion = $dossier->completion($this->resource);
        $worker = $this->draftWorker ?? $this->convertedWorker;
        $documents = $worker ? $dossier->activeDocuments($worker) : collect();
        $trades = $worker?->workerTrades()->with('trade')->get() ?? collect();
        $secondary = $trades->firstWhere('is_primary', false);

        return [
            ...parent::toArray($request),
            'identity' => [
                'gender' => $this->status($worker?->gender),
                'birth_date' => $this->date($worker?->birth_date),
                'birth_place' => $worker?->birth_place,
            ],
            'activity' => [
                'daily_rate' => $worker?->estimated_daily_rate,
                'level' => $this->status($trades->firstWhere('is_primary', true)?->level),
                'specialties' => $worker?->specialties->map->only(['id', 'label'])->values()->all() ?? [],
                'skills' => $worker?->skills->map->only(['id', 'label'])->values()->all() ?? [],
                'secondary_trade' => $secondary ? [
                    'trade' => $secondary->trade->only(['id', 'code', 'label']),
                    'experience_years' => $secondary->years_experience,
                ] : null,
                'experiences' => $worker?->references->pluck('project_name')->values()->all() ?? [],
            ],
            'emergency_contact' => [
                'name' => $worker?->emergency_contact_name,
                'phone' => $worker?->emergency_contact_phone ? Phone::format($worker->emergency_contact_phone) : null,
            ],
            'documents' => $this->documentSlots($documents, $dossier),
            'checklist' => $completion['items'],
            'missing' => array_map(fn (array $item) => ['key' => $item['key'], 'label' => $item['label']], $completion['blocking']),
            'can_submit' => $completion['ready'],
        ];
    }

    /**
     * Pièces attendues (comme la maquette) : une pièce d’identité au choix, la photo, puis les pièces facultatives.
     * État : fourni, manquant (obligatoire) ou non fourni (facultatif).
     *
     * @param  Collection<int, Document>  $documents
     * @return list<array<string, mixed>>
     */
    private function documentSlots(Collection $documents, ProspectDossier $dossier): array
    {
        $types = DocumentType::query()->where('audience', DocumentAudience::Worker)->where('is_active', true)->get();
        $identityTypes = $types->where('is_identity', true);
        $identity = $documents->first(fn (Document $document) => $document->documentType->is_identity);

        $slots = [[
            'key' => 'identity',
            'label' => 'Pièce d’identité',
            'required' => true,
            'accepted_types' => $identityTypes->map(fn (DocumentType $type) => ['code' => $type->code, 'label' => $type->label, 'has_back_side' => $type->has_back_side])->values()->all(),
            'complete' => $identity && $dossier->isCompleteIdentity($identity),
            'document' => $identity,
        ]];

        foreach (ProspectDossier::DOCUMENT_SLOTS as $code) {
            $type = $types->firstWhere('code', $code);
            $document = $documents->first(fn (Document $document) => $document->documentType->code === $code);

            if ($type) {
                $slots[] = [
                    'key' => $code,
                    'label' => $type->label,
                    'required' => $type->is_required,
                    'accepted_types' => [['code' => $type->code, 'label' => $type->label, 'has_back_side' => $type->has_back_side]],
                    'complete' => $document && $document->files->isNotEmpty(),
                    'document' => $document,
                ];
            }
        }

        return array_map(fn (array $slot) => [
            ...array_diff_key($slot, ['complete' => true, 'document' => true]),
            'state' => match (true) {
                $slot['complete'] => ['value' => 'fourni', 'label' => 'Fourni'],
                $slot['required'] => ['value' => 'manquant', 'label' => 'Manquant'],
                default => ['value' => 'non_fourni', 'label' => 'Non fourni'],
            },
            'document' => $slot['document'] ? DocumentResource::make($slot['document'])->resolve() : null,
        ], $slots);
    }
}
