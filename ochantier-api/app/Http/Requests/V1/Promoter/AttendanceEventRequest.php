<?php

namespace App\Http\Requests\V1\Promoter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Arrivée ou départ d’un ouvrier (multipart/form-data) : photo, signature et position sont obligatoires.
 * client_uuid, généré par l’application, rend l’envoi rejouable hors connexion.
 */
class AttendanceEventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignment_id' => ['required', 'uuid'],
            'type' => ['required', Rule::in(['arrivee', 'depart'])],
            'client_uuid' => ['required', 'uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
            'photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png', 'max:8192'],
            'signature' => ['required', 'file', 'mimetypes:image/png,image/jpeg', 'max:2048'],
            'device_recorded_at' => ['nullable', 'date', 'before_or_equal:+5 minutes', 'after_or_equal:-48 hours'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignment_id.required' => 'Choisissez un ouvrier.',
            'type.required' => 'Précisez s’il s’agit d’une arrivée ou d’un départ.',
            'type.in' => 'Précisez s’il s’agit d’une arrivée ou d’un départ.',
            'latitude.required' => 'La localisation est obligatoire.',
            'longitude.required' => 'La localisation est obligatoire.',
            'accuracy.required' => 'La précision de la localisation est obligatoire.',
            'latitude.between' => 'Position GPS invalide.',
            'longitude.between' => 'Position GPS invalide.',
            'photo.required' => 'La photo de l’ouvrier est obligatoire.',
            'photo.mimetypes' => 'La photo doit être au format JPEG ou PNG.',
            'photo.max' => 'La photo ne doit pas dépasser 8 Mo.',
            'signature.required' => 'La signature de l’ouvrier est obligatoire.',
            'signature.mimetypes' => 'La signature doit être une image PNG ou JPEG.',
            'signature.max' => 'La signature ne doit pas dépasser 2 Mo.',
            'device_recorded_at.before_or_equal' => 'L’heure du pointage est dans le futur : vérifiez l’heure du téléphone.',
            'device_recorded_at.after_or_equal' => 'Ce pointage date de plus de 48 heures : il ne peut plus être envoyé.',
        ];
    }
}
