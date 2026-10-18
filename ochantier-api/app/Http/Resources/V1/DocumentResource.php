<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * Pièce justificative (ouvrier, promoteur ou chantier) : le fichier lui-même n’est jamais exposé.
 *
 * @mixin \App\Models\WorkerDocument|\App\Models\PromoterDocument|\App\Models\SiteDocument
 */
class DocumentResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->status($this->type),
            'status' => $this->status($this->status),
            'reference' => $this->reference,
            'file_name' => $this->file_name ?? ($this->file_path ? basename($this->file_path) : null),
            'added_at' => $this->date($this->added_at),
            'expires_at' => $this->date($this->expires_at),
            'rejection_reason' => $this->rejection_reason,
        ];
    }
}
