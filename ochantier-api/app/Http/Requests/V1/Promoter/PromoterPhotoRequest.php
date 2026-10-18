<?php

namespace App\Http\Requests\V1\Promoter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Photo de profil (multipart/form-data, champ « photo »).
 */
class PromoterPhotoRequest extends FormRequest
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
            'photo.required' => 'Choisissez une photo.',
            'photo.mimetypes' => 'Choisissez une photo au format JPEG ou PNG.',
            'photo.max' => 'La photo ne doit pas dépasser 5 Mo.',
        ];
    }
}
