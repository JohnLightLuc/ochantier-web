<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\DocumentAudience;
use App\Http\Resources\V1\DocumentResource;
use App\Models\CommercialProfile;
use App\Models\Document;
use App\Models\DocumentType;
use App\Support\Admin\CommercialActivation;
use App\Support\Admin\CommercialStatistics;
use App\Support\Documents\DocumentStore;
use Illuminate\Http\Request;

/**
 * Fiche d’un commercial : compte, contrat, indicateurs, évolution sur six mois et répartition par métier.
 *
 * @mixin CommercialProfile
 */
class CommercialDetailResource extends CommercialListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->account_status;
        $user = $this->user;
        $statistics = app(CommercialStatistics::class);

        return [
            ...parent::toArray($request),
            'secondary_phone' => $this->secondary_phone,
            'address' => $this->address,
            'is_supervisor' => $this->is_supervisor,
            'verification_status' => $this->status($this->verification_status),
            'ended_at' => $this->date($this->ended_at),
            'created_by' => $this->createdByAdmin ? trim($this->createdByAdmin->first_name.' '.$this->createdByAdmin->last_name) : null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'suspension' => $status === 'suspendu' ? [
                'reason' => $user->suspended_reason,
                'at' => $user->suspended_at?->toIso8601String(),
                'by' => $user->suspendedByAdmin ? trim($user->suspendedByAdmin->first_name.' '.$user->suspendedByAdmin->last_name) : null,
            ] : null,
            'termination_reason' => $status === 'termine' ? $statistics->terminationReason($this->resource) : null,
            'access_link' => app(CommercialActivation::class)->state($user),
            'password_set' => $user->password_hash !== null,
            'documents' => $this->slots(),
            'ranking' => $statistics->ranking($this->resource, $status),
            'months' => $statistics->months($this->resource),
            'trades' => $statistics->trades($this->resource),
            'can' => [
                'suspend' => $status === 'actif',
                'reactivate' => in_array($status, ['suspendu', 'termine'], true),
                'terminate' => $status !== 'termine',
                'edit' => $status !== 'termine',
                'send_link' => in_array($status, ['en_attente_activation', 'actif'], true),
            ],
        ];
    }

    /**
     * Une ligne par type de pièce, puis les éventuelles pièces d’un type retiré.
     *
     * @return array{slots: list<array<string, mixed>>, others: list<array<string, mixed>>}
     */
    private function slots(): array
    {
        $documents = app(DocumentStore::class)->active($this->resource);
        $types = DocumentType::query()->where('audience', DocumentAudience::Commercial)->where('is_active', true)->orderBy('created_at')->get();
        $view = fn (Document $document) => [...DocumentResource::make($document)->resolve(), 'rejection_reason' => $document->rejection_reason];

        return [
            'slots' => $types->map(function (DocumentType $type) use ($documents, $view) {
                $document = $documents->firstWhere('document_type_id', $type->id);

                return [
                    'key' => $type->code,
                    'label' => $type->label,
                    'required' => (bool) $type->is_required,
                    'state' => $document ? $this->status($document->verification_status) : ($type->is_required ? ['value' => 'manquant', 'label' => 'Manquant'] : ['value' => 'non_fourni', 'label' => 'Non fourni']),
                    'document' => $document ? $view($document) : null,
                ];
            })->values()->all(),
            'others' => $documents->reject(fn (Document $document) => $types->contains('id', $document->document_type_id))->map($view)->values()->all(),
        ];
    }
}
