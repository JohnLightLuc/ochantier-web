<?php

namespace App\Http\Resources\V1\Commercial;

use App\Enums\ProspectStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Prospect;
use App\Support\Commercial\ProspectDossier;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Prospect dans la liste « Prospects » : contact, étape (badge), avancement du dossier et conseil d’action.
 *
 * @mixin Prospect
 */
class ProspectResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $completion = app(ProspectDossier::class)->completion($this->resource);
        $worker = $this->status === ProspectStatus::Converti ? $this->convertedWorker : $this->draftWorker;

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'initials' => mb_strtoupper(mb_substr((string) $this->first_name, 0, 1).mb_substr((string) $this->last_name, 0, 1)),
            ...PhoneNumber::of($this->resource)->toArray(),
            'commune' => $this->commune?->only(['id', 'name']),
            'neighborhood' => $this->neighborhood?->only(['id', 'name']),
            'trade' => $this->presumedTrade?->only(['id', 'code', 'label']),
            'experience_years' => $this->estimated_experience_years,
            'status' => $this->status($this->status),
            'stage' => $this->stage($completion['ready']),
            'completion' => array_intersect_key($completion, array_flip(['percent', 'filled', 'total', 'ready'])),
            'hint' => $this->hint($completion),
            'worker' => $worker ? ['id' => $worker->id, 'reference' => $worker->reference, 'status' => $this->status($worker->verification_status)] : null,
            'client_uuid' => $this->client_uuid,
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Badge de la maquette : statut de suivi, ou « Prêt à soumettre » quand le dossier est complet.
     *
     * @return array{value: string, label: string}
     */
    private function stage(bool $ready): array
    {
        return $ready && ! in_array($this->status, [ProspectStatus::Converti, ProspectStatus::Abandonne], true)
            ? ['value' => 'pret', 'label' => 'Prêt à soumettre']
            : $this->status($this->status);
    }

    /**
     * @param  array{ready: bool, blocking: list<array{label: string}>}  $completion
     */
    private function hint(array $completion): string
    {
        return match (true) {
            $this->status === ProspectStatus::Converti => 'Dossier transmis au Back office',
            $this->status === ProspectStatus::Abandonne => 'Prospect archivé',
            $completion['ready'] => 'Prêt à soumettre',
            ! $this->draftWorker => 'Nouveau contact · dossier à commencer',
            default => 'À compléter : '.implode(', ', array_map(
                fn (array $item) => mb_strtolower(mb_substr($item['label'], 0, 1)).mb_substr($item['label'], 1),
                array_slice($completion['blocking'], 0, 2),
            )).(count($completion['blocking']) > 2 ? '…' : ''),
        };
    }
}
