<?php

namespace App\Http\Resources\V1;

use App\Models\Document;
use App\Models\MediaFile;
use App\Support\Media\MediaStore;
use Illuminate\Http\Request;

/**
 * Pièce d’un dossier ; chaque fichier est consultable par un lien signé valable 30 minutes.
 *
 * @mixin Document
 */
class DocumentResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = app(MediaStore::class);

        return [
            'id' => $this->id,
            'type' => $this->documentType->only(['code', 'label']),
            'number_last4' => $this->number_last4,
            'expires_at' => $this->date($this->expires_at),
            'version' => $this->version,
            'status' => $this->status($this->verification_status),
            'files' => $this->files->sortBy(fn (MediaFile $file) => array_search($file->pivot->side, ['recto', 'verso', 'page'], true))->map(fn (MediaFile $file) => [
                'id' => $file->id,
                'side' => $file->pivot->side,
                'name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'url' => $media->temporaryUrl($file),
            ])->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
