<?php

namespace App\Http\Requests\V1\Contractor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Photo de profil de l’indépendant ou logo de l’entreprise (image JPEG ou PNG, 5 Mo au plus).
 */
class ContractorPhotoRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png', 'max:5120']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'Ajoutez une image.',
            'photo.mimetypes' => 'Format non accepté : JPEG ou PNG.',
            'photo.max' => 'Image trop volumineuse (5 Mo au plus).',
        ];
    }
}
