<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\DocumentAudience;
use App\Http\Resources\V1\DocumentResource;
use App\Http\Resources\V1\Resource;
use App\Models\Admin;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\VerificationCorrection;
use App\Models\VerificationReview;
use App\Models\Worker;
use App\Models\WorkerTrade;
use App\Support\Admin\WorkerAdministration;
use App\Support\Admin\WorkerVerification;
use App\Support\Commercial\ProspectDossier;
use App\Support\Documents\DocumentStore;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Fiche ouvrier du Back Office : identité, coordonnées, métiers et compétences, conditions de travail, commercial
 * recruteur, statistiques, pièces par emplacement, contrôle en cours et historique, suspension ou exclusion.
 *
 * @mixin Worker
 */
class WorkerDetailResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $verification = app(WorkerVerification::class);
        $documents = app(DocumentStore::class)->active($this->resource);
        $reviews = $this->verificationReviews()->with(['assignedAdmin', 'decidedByAdmin', 'verificationCorrections.document.documentType'])->orderByDesc('submission_number')->get();
        $sanction = $verification->currentSanction($this->resource);
        $refusal = $verification->approvalRefusal($this->resource);
        $commercial = fn ($profile) => $profile?->user ? ['id' => $profile->id, 'full_name' => trim($profile->user->first_name.' '.$profile->user->last_name), 'phone' => PhoneNumber::of($profile->user)?->formatted()] : null;

        return [
            ...WorkerListResource::make($this->resource)->resolve($request),
            'identity' => [
                'gender' => $this->status($this->gender),
                'birth_date' => $this->date($this->birth_date),
                'birth_place' => $this->birth_place,
                'nationality' => $this->nationalityCountry?->name,
            ],
            'contact' => [
                ...(PhoneNumber::of($this->resource)?->toArray() ?? ['dial_code' => null, 'phone_number' => null, 'phone' => null]),
                'secondary_phone' => $this->secondary_phone,
                'emergency_contact' => [
                    'name' => $this->emergency_contact_name,
                    'relation' => $this->emergency_contact_relation,
                    ...(PhoneNumber::of($this->resource, 'emergency_contact_')?->toArray() ?? ['dial_code' => null, 'phone_number' => null, 'phone' => null]),
                ],
            ],
            'residence' => [
                'commune' => $this->residenceCommune?->only(['id', 'name']),
                'neighborhood' => $this->residenceNeighborhood?->only(['id', 'name']),
                'address' => $this->residence_address,
                'landmark' => $this->residence_landmark,
            ],
            'trades' => $this->workerTrades->sortByDesc('is_primary')->map(fn (WorkerTrade $trade) => [
                'trade' => $trade->trade?->only(['id', 'code', 'label']),
                'is_primary' => $trade->is_primary,
                'experience_years' => $trade->years_experience,
                'level' => $this->status($trade->level),
            ])->values()->all(),
            'specialties' => $this->specialties->map->only(['id', 'label'])->values()->all(),
            'skills' => $this->skills->map->only(['id', 'label'])->values()->all(),
            'references' => $this->references->map(fn ($reference) => [
                'project' => $reference->project_name,
                'role' => $reference->role,
                'employer' => $reference->employer_name,
                'start_date' => $this->date($reference->start_date),
                'end_date' => $this->date($reference->end_date),
            ])->values()->all(),
            'work' => [
                'mobility_radius_km' => $this->mobility_radius_km,
                'accepts_long_travel' => (bool) $this->accepts_long_travel,
                'needs_accommodation' => (bool) $this->needs_accommodation,
                'transport_mode' => $this->transport_mode,
                'works_solo' => (bool) $this->works_solo,
                'works_in_team' => (bool) $this->works_in_team,
                'accepted_offer_types' => $this->accepted_offer_types ?? [],
                'accepted_payment_frequencies' => $this->accepted_payment_frequencies ?? [],
                'available_from' => $this->date($this->available_from),
            ],
            'recruitment' => [
                'origin_commercial' => $commercial($this->originCommercial),
                'current_commercial' => $commercial($this->currentCommercial),
                'acquisition_channel' => $this->acquisition_channel,
            ],
            'stats' => [
                'missions_completed' => (int) $this->missions_completed,
                'attendance_rate' => $this->attendance_rate !== null ? (float) $this->attendance_rate : null,
                'acceptance_rate' => $this->acceptance_rate !== null ? (float) $this->acceptance_rate : null,
                'replacement_count' => (int) $this->replacement_count,
                'last_assignment_at' => $this->last_assignment_at?->toIso8601String(),
            ],
            'documents' => $this->slots($documents),
            'review' => [
                'can_decide' => in_array($this->verification_status, WorkerVerification::REVIEWABLE, true),
                'can_approve' => $refusal === null,
                'approval_refusal' => $refusal,
                'required_documents' => array_map(fn (array $item, string $key) => [
                    'key' => $key,
                    'label' => $item[0],
                    'document_id' => $item[1]?->id,
                    'status' => $item[1] ? $this->status($item[1]->verification_status) : null,
                ], $required = $verification->requiredDocuments($this->resource), array_keys($required)),
                'current' => ($current = $reviews->first()) ? $this->review($current) : null,
                'history' => $reviews->skip(1)->map(fn (VerificationReview $review) => $this->review($review))->values()->all(),
            ],
            'sanction' => $sanction ? [
                'type' => $this->status($sanction->sanction_type),
                'reason' => $sanction->comment,
                'starts_at' => $sanction->starts_at?->toIso8601String(),
                'ends_at' => $sanction->ends_at?->toIso8601String(),
                'decided_by' => $sanction->decidedByAdmin ? $this->admin($sanction->decidedByAdmin) : null,
            ] : null,
            'validated_by' => $this->validatedByAdmin ? $this->admin($this->validatedByAdmin) : null,
            'missing' => $this->verification_status->value === 'brouillon' ? app(WorkerAdministration::class)->missing($this->resource) : [],
            'can' => [
                'suspend' => $this->verification_status->value === 'valide',
                'reactivate' => $this->verification_status->value === 'suspendu',
                'exclude' => ! in_array($this->verification_status->value, ['exclu', 'brouillon'], true),
                'edit' => $this->verification_status->value !== 'exclu',
            ],
        ];
    }

    /**
     * Pièces par emplacement : pièce d’identité, photo, puis pièces facultatives ; les autres pièces à la suite.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<string, mixed>
     */
    private function slots(Collection $documents): array
    {
        $types = DocumentType::query()->where('audience', DocumentAudience::Worker)->where('is_active', true)->get();
        $identity = $documents->first(fn (Document $document) => $document->documentType->is_identity);
        $slots = [['identity', 'Pièce d’identité', true, $identity]];

        foreach (ProspectDossier::DOCUMENT_SLOTS as $code) {
            if ($type = $types->firstWhere('code', $code)) {
                $slots[] = [$code, $type->label, (bool) $type->is_required, $documents->first(fn (Document $document) => $document->documentType->code === $code)];
            }
        }

        $used = collect($slots)->pluck(3)->filter()->pluck('id');

        return [
            'slots' => array_map(fn (array $slot) => [
                'key' => $slot[0],
                'label' => $slot[1],
                'required' => $slot[2],
                'state' => match (true) {
                    $slot[3] === null => $slot[2] ? ['value' => 'manquant', 'label' => 'Manquant'] : ['value' => 'non_fourni', 'label' => 'Non fourni'],
                    default => $this->status($slot[3]->verification_status),
                },
                'document' => $slot[3] ? [...DocumentResource::make($slot[3])->resolve(), 'rejection_reason' => $slot[3]->rejection_reason] : null,
            ], $slots),
            'others' => $documents->reject(fn (Document $document) => $used->contains($document->id))
                ->map(fn (Document $document) => [...DocumentResource::make($document)->resolve(), 'rejection_reason' => $document->rejection_reason])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function review(VerificationReview $review): array
    {
        return [
            'id' => $review->id,
            'submission_number' => $review->submission_number,
            'status' => $this->status($review->status),
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'assigned_admin' => $review->assignedAdmin ? $this->admin($review->assignedAdmin) : null,
            'decided_by' => $review->decidedByAdmin ? $this->admin($review->decidedByAdmin) : null,
            'decided_at' => $review->decided_at?->toIso8601String(),
            'decision_reason' => $review->decision_reason,
            'corrections' => $review->verificationCorrections->map(fn (VerificationCorrection $correction) => [
                'id' => $correction->id,
                'document' => $correction->document ? ['id' => $correction->document->id, 'type' => $correction->document->documentType->only(['code', 'label'])] : null,
                'field' => $correction->target_field,
                'reason' => $correction->reason,
                'resolved_at' => $correction->resolved_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{id: string, full_name: string}
     */
    private function admin(Admin $admin): array
    {
        return ['id' => $admin->id, 'full_name' => $admin->fullName()];
    }
}
