<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\DocumentAudience;
use App\Enums\DocumentVerificationStatus;
use App\Http\Resources\V1\DocumentResource;
use App\Http\Resources\V1\Resource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Organization;
use App\Models\PromoterProfile;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use App\Support\Promoter\PromoterAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Écrans « Profil » et « Modifier le profil » : identité, entreprise, vérification, pièces et chiffres du compte.
 *
 * @mixin PromoterProfile
 */
class PromoterProfileResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = app(PromoterAccount::class);
        $user = $this->user;
        $company = $account->isCompany($this->resource);
        $organization = $company ? $account->organization($this->resource) : null;
        $completion = $account->completion($this->resource);
        $documents = $account->activeDocuments($this->resource);

        return [
            'id' => $this->id,
            'kind' => $this->status($this->profile_kind),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => trim($user->first_name.' '.$user->last_name) ?: null,
            'initials' => mb_strtoupper(mb_substr((string) $user->first_name, 0, 1).mb_substr((string) $user->last_name, 0, 1)) ?: null,
            ...PhoneNumber::of($user)?->toArray() ?? ['dial_code' => null, 'phone_number' => null, 'phone' => null],
            'phone_masked' => PhoneNumber::of($user)?->masked(),
            'email' => $user->email,
            'photo_url' => $this->photoMedia ? app(MediaStore::class)->temporaryUrl($this->photoMedia) : null,
            'job_title' => $this->job_title,
            'address' => [
                'address' => $this->address,
                'commune' => $this->commune?->only(['id', 'name']),
                'neighborhood' => $this->neighborhood?->only(['id', 'name']),
            ],
            'company' => $organization ? $this->company($organization) : null,
            'verification' => [
                'title' => $company ? 'Identité et entreprise' : 'Identité',
                'status' => $this->status($this->verification_status),
                'state' => $account->verificationState($this->resource, $completion['complete']),
                'summary' => $this->summary($account, $completion, $organization),
                'validated_at' => $this->validated_at?->toIso8601String(),
                'can_change_kind' => $account->canChangeKind($this->resource),
            ],
            'completion' => $completion,
            'documents' => $this->documents($account, $documents, $company),
            'stats' => $account->stats($this->resource),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function company(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'legal_name' => $organization->legal_name,
            'trade_name' => $organization->trade_name,
            'legal_form' => $organization->legal_form,
            'rccm' => $organization->rccm,
            'ncc' => $organization->tax_identifier,
            'headquarters' => [
                'address' => $organization->address,
                'commune' => $organization->commune?->only(['id', 'name']),
                'neighborhood' => $organization->neighborhood?->only(['id', 'name']),
                'location' => $organization->location,
            ],
            'verification_status' => $this->status($organization->verification_status),
        ];
    }

    /**
     * @param  array{complete: bool, missing: list<array{label: string}>}  $completion
     */
    private function summary(PromoterAccount $account, array $completion, ?Organization $organization): string
    {
        $count = count($completion['missing']);

        return match ($account->verificationState($this->resource, $completion['complete'])['value']) {
            'a_completer' => 'À compléter · '.$count.' élément'.($count > 1 ? 's' : '').' manquant'.($count > 1 ? 's' : ''),
            'en_verification' => 'Documents en cours de vérification',
            'verifie' => $organization?->rccm ? 'Vérifiées · RCCM '.$organization->rccm : 'Vérifiée',
            'a_corriger' => 'Des corrections sont demandées sur vos documents',
            'refuse' => 'Vérification refusée · contactez le support',
            default => 'Compte suspendu · contactez le support',
        };
    }

    /**
     * Pièces attendues (pièce d’identité au choix, certificat de résidence, pièces de l’entreprise) et autres
     * documents ajoutés librement.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<string, mixed>
     */
    private function documents(PromoterAccount $account, Collection $documents, bool $company): array
    {
        $types = DocumentType::query()
            ->whereIn('audience', $company ? [DocumentAudience::Promoter, DocumentAudience::Organization] : [DocumentAudience::Promoter])
            ->where('is_active', true)
            ->get();
        $accepted = fn (Collection $list) => $list->map(fn (DocumentType $type) => ['code' => $type->code, 'label' => $type->label, 'has_back_side' => $type->has_back_side])->values()->all();
        $of = fn (string $code) => $documents->first(fn (Document $document) => $document->documentType->code === $code);

        $identity = $documents->first(fn (Document $document) => $document->documentType->is_identity);
        $slots = [[
            'key' => 'identity',
            'label' => $company ? 'Pièce d’identité du dirigeant' : 'Pièce d’identité',
            'hint' => PromoterAccount::HINTS['identity'],
            'required' => true,
            'accepted_types' => $accepted($types->whereIn('code', PromoterAccount::IDENTITY_DOCUMENTS)),
            'document' => $identity,
            'complete' => $identity && $account->isCompleteIdentity($identity),
        ]];

        foreach ([PromoterAccount::RESIDENCE_DOCUMENT, ...($company ? PromoterAccount::COMPANY_DOCUMENTS : [])] as $code) {
            $type = $types->firstWhere('code', $code);

            if (! $type) {
                continue;
            }

            $document = $of($code);
            $slots[] = [
                'key' => $code,
                'label' => $code === PromoterAccount::RESIDENCE_DOCUMENT && $company ? 'Certificat de résidence du dirigeant' : $type->label,
                'hint' => PromoterAccount::HINTS[$code] ?? null,
                'required' => $type->is_required,
                'accepted_types' => $accepted(collect([$type])),
                'document' => $document,
                'complete' => $document && $document->files->isNotEmpty(),
            ];
        }

        $slots = array_map(fn (array $slot) => [
            ...array_diff_key($slot, ['document' => true, 'complete' => true]),
            'state' => $this->state($slot['document'], $slot['complete'], $slot['required']),
            'document' => $slot['document'] ? $this->document($slot['document']) : null,
        ], $slots);
        $required = array_filter($slots, fn (array $slot) => $slot['required']);

        return [
            'title' => $company ? 'Documents de l’entreprise' : 'Documents d’identité',
            'required_count' => count($required),
            'provided_required_count' => count(array_filter($required, fn (array $slot) => ! in_array($slot['state']['value'], ['manquant', 'incomplet'], true))),
            'slots' => $slots,
            'others' => $documents->filter(fn (Document $document) => $document->documentType->code === PromoterAccount::OTHER_DOCUMENT)
                ->map(fn (Document $document) => $this->document($document))->values()->all(),
            'other_type' => PromoterAccount::OTHER_DOCUMENT,
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function state(?Document $document, bool $complete, bool $required): array
    {
        return match (true) {
            ! $document => $required ? ['value' => 'manquant', 'label' => 'Manquant'] : ['value' => 'non_fourni', 'label' => 'Non fourni'],
            ! $complete => ['value' => 'incomplet', 'label' => 'Incomplet'],
            $document->verification_status === DocumentVerificationStatus::Valide => ['value' => 'verifie', 'label' => 'Vérifié'],
            $document->verification_status === DocumentVerificationStatus::Rejete => ['value' => 'refuse', 'label' => 'Refusé'],
            $document->verification_status === DocumentVerificationStatus::Expire => ['value' => 'expire', 'label' => 'Expiré'],
            default => ['value' => 'en_verification', 'label' => 'En vérification'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Document $document): array
    {
        return [...DocumentResource::make($document)->resolve(), 'rejection_reason' => $document->rejection_reason];
    }
}
