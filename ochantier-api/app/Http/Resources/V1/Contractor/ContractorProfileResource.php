<?php

namespace App\Http\Resources\V1\Contractor;

use App\Http\Resources\V1\DocumentResource;
use App\Http\Resources\V1\Resource;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\DocumentType;
use App\Support\Contractor\ContractorAccount;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Écran « Profil » et dossier de vérification d’un entrepreneur : identité ou entreprise, coordonnées, activité,
 * pièces, avancement, état de la vérification et limites tant que le profil n’est pas vérifié.
 *
 * @mixin Contractor
 */
class ContractorProfileResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = app(ContractorAccount::class);
        $user = $this->user;
        $company = $this->isCompany();
        $completion = $account->completion($this->resource);
        $state = $account->state($this->resource);
        $verified = $this->isVerified();
        $documents = $account->activeDocuments($this->resource);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->status($this->kind),
            'verification_type' => $company ? 'KYB' : 'KYC',
            'name' => $this->name ?: null,
            'initials' => $this->initials(),
            'photo_url' => $this->logoMedia ? app(MediaStore::class)->temporaryUrl($this->logoMedia) : null,
            'company' => $company ? [
                'legal_name' => $this->name ?: null,
                'legal_form' => $this->legal_form,
                'rccm' => $this->rccm,
                'ncc' => $this->tax_identifier,
                'representative_name' => $this->contact_name,
            ] : null,
            'identity' => $company ? null : [
                'last_name' => $user->last_name,
                'first_name' => $user->first_name,
                'birth_date' => $this->date($this->birth_date),
            ],
            'contact' => [
                ...PhoneNumber::of($this->resource)?->toArray() ?? ['dial_code' => null, 'phone_number' => null, 'phone' => null],
                'email' => $this->email,
                'city' => $this->city?->only(['id', 'name']),
                'address' => $this->address,
            ],
            'activity' => [
                'trades' => $this->trades->sortBy('sort_order')->map->only(['id', 'code', 'label'])->values(),
                'zones' => $this->zones->sortBy('name')->map->only(['id', 'name'])->values(),
                'experience_years' => $this->experience_years,
                'description' => $this->description,
            ],
            'verification' => [
                'status' => $this->status($this->verification_status),
                'state' => $state,
                'reason' => in_array($state['value'], ['a_corriger', 'rejete'], true) ? $this->verification_reason : ($state['value'] === 'suspendu' ? $this->suspended_reason : null),
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'validated_at' => $this->validated_at?->toIso8601String(),
                'can_edit' => $account->canEdit($this->resource),
                'can_change_kind' => $account->canChangeKind($this->resource),
                'can_submit' => $account->canSubmit($this->resource),
                'edit_warning' => $verified ? 'La modification de données sensibles est tracée et relance la vérification de votre profil.' : null,
            ],
            'limits' => [
                'can_respond' => $verified,
                'message' => $verified ? null : 'Limites : consultation seule des opportunités. Réponses, devis, offres et actions financières après vérification.',
            ],
            'completion' => $completion,
            'documents' => DocumentResource::collection($documents)->resolve($request),
            'document_types' => $this->documentTypes($account, $documents),
            'options' => ['legal_forms' => ContractorAccount::LEGAL_FORMS],
        ];
    }

    /**
     * Pièces proposées pour ce profil, avec la précision affichée (« Obligatoire », « Selon votre statut »…).
     *
     * @param  Collection<int, Document>  $documents
     * @return list<array<string, mixed>>
     */
    private function documentTypes(ContractorAccount $account, $documents): array
    {
        $codes = $account->documentCodes($this->resource);
        $hints = [
            'contractor_rccm' => 'Selon votre statut',
            'contractor_tax_certificate' => 'Selon votre statut',
            'contractor_site_references' => 'Facultatif',
            'contractor_trade_certificate' => 'Facultatif',
        ];

        return DocumentType::query()->whereIn('code', $codes)->where('is_active', true)->get()
            ->sortBy(fn (DocumentType $type) => array_search($type->code, $codes, true))
            ->map(fn (DocumentType $type) => [
                'code' => $type->code,
                'label' => $type->label,
                'group' => $type->is_identity ? 'identity' : $type->code,
                'hint' => $type->is_identity ? 'Obligatoire' : $hints[$type->code],
                'is_identity' => $type->is_identity,
                'has_back_side' => $type->has_back_side,
                'requires_number' => $type->is_identity && ! $this->isCompany(),
                'allowed_mime' => $type->allowed_mime,
                'max_size_kb' => $type->max_size_kb,
                'provided' => $documents->contains(fn (Document $document) => $document->document_type_id === $type->id),
            ])->values()->all();
    }

    private function initials(): ?string
    {
        $words = preg_split('/\s+/', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY);

        return $words ? mb_strtoupper(implode('', array_map(fn (string $word) => mb_substr($word, 0, 1), array_slice($words, 0, 2)))) : null;
    }
}
