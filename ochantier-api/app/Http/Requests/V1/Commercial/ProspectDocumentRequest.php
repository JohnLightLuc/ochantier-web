<?php

namespace App\Http\Requests\V1\Commercial;

use App\Enums\DocumentAudience;
use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Pièce du dossier envoyée depuis l’appareil photo ou les fichiers (multipart/form-data).
 *
 * Pièce recto-verso : fichiers « recto » et « verso ». Autre pièce : fichier « file ». Formats et taille maximale
 * selon le type de pièce.
 */
class ProspectDocumentRequest extends FormRequest
{
    private ?DocumentType $type = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->documentType();
        $file = ['file', 'max:'.($type?->max_size_kb ?? 10240), 'mimetypes:'.implode(',', $type?->allowed_mime ?? ['application/pdf', 'image/jpeg', 'image/png'])];

        return [
            'document_type' => ['required', 'string', Rule::exists('document_types', 'code')->where('audience', DocumentAudience::Worker->value)->where('is_active', true)],
            'number' => [$type?->is_identity ? 'required' : 'nullable', 'string', 'min:5', 'max:40'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'recto' => [$type?->has_back_side ? 'required' : 'prohibited', ...$file],
            'verso' => [$type?->has_back_side ? 'required' : 'prohibited', ...$file],
            'file' => [$type && ! $type->has_back_side ? 'required' : 'prohibited', ...$file],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_type.required' => 'Choisissez un document.',
            'document_type.exists' => 'Ce type de document n’est pas accepté.',
            'number.required' => 'Indiquez le numéro du document.',
            'number.min' => 'Indiquez le numéro du document.',
            'recto.required' => 'Ajoutez le recto du document.',
            'verso.required' => 'Ajoutez le verso du document.',
            'file.required' => 'Ajoutez la photo du document.',
            'expires_at.after' => 'Ce document est expiré.',
        ];
    }

    public function documentType(): ?DocumentType
    {
        return $this->type ??= DocumentType::query()
            ->where('code', (string) $this->input('document_type'))
            ->where('audience', DocumentAudience::Worker)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array<string, UploadedFile>
     */
    public function sides(): array
    {
        return $this->documentType()->has_back_side
            ? ['recto' => $this->file('recto'), 'verso' => $this->file('verso')]
            : ['page' => $this->file('file')];
    }
}
