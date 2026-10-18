<?php

namespace App\Http\Requests\V1;

use App\Enums\DocumentAudience;
use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Pièce envoyée depuis l’appareil photo ou les fichiers (multipart/form-data).
 *
 * Pièce recto-verso : fichiers « recto » et « verso ». Autre pièce : fichier « file ». Formats et taille maximale
 * selon le type de pièce.
 */
abstract class DocumentUploadRequest extends FormRequest
{
    private ?DocumentType $type = null;

    /**
     * Destinataires des types de pièces acceptés.
     *
     * @return list<DocumentAudience>
     */
    abstract protected function audiences(): array;

    /**
     * Le numéro est-il exigé pour une pièce d’identité ?
     */
    protected function identityNumberRequired(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->documentType();
        $file = ['file', 'max:'.($type?->max_size_kb ?? 10240), 'mimetypes:'.implode(',', $type?->allowed_mime ?? ['application/pdf', 'image/jpeg', 'image/png'])];

        return [
            'document_type' => ['required', 'string', Rule::exists('document_types', 'code')->whereIn('audience', array_map(fn (DocumentAudience $audience) => $audience->value, $this->audiences()))->where('is_active', true)],
            'number' => [$type?->is_identity && $this->identityNumberRequired() ? 'required' : 'nullable', 'string', 'min:5', 'max:40'],
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
            'recto.prohibited' => 'Ce document n’a qu’une face : envoyez-le dans « file ».',
            'verso.prohibited' => 'Ce document n’a qu’une face : envoyez-le dans « file ».',
            'file.prohibited' => 'Envoyez le recto et le verso de ce document.',
            'mimetypes' => 'Format non accepté pour ce document.',
            'max' => 'Fichier trop volumineux.',
            'expires_at.after' => 'Ce document est expiré.',
        ];
    }

    public function documentType(): ?DocumentType
    {
        return $this->type ??= DocumentType::query()
            ->where('code', (string) $this->input('document_type'))
            ->whereIn('audience', $this->audiences())
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
