<?php

namespace App\Http\Requests\V1\Landing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Candidature « Devenir ouvrier » transmise par le site public (mêmes règles que le formulaire du site).
 */
class WorkerApplicationRequest extends FormRequest
{
    public const LEVELS = ['Apprenti', 'Confirmé', 'Expert', 'Chef d’équipe'];

    public const AVAILABILITIES = ['Immédiate', 'Sous 1 semaine', 'Sous 1 mois'];

    public const PAYOUT_METHODS = ['Orange Money', 'MTN MoMo', 'Moov Money', 'Wave', 'Virement'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:Homme,Femme'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'identity_document' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:8192'],
            'trade' => ['required', 'string', 'max:60'],
            'daily_rate' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'experience_years' => ['required', 'integer', 'min:0', 'max:30'],
            'level' => ['required', 'in:'.implode(',', self::LEVELS)],
            'skills' => ['nullable', 'array', 'max:20'],
            'skills.*' => ['string', 'max:60'],
            'certifications' => ['nullable', 'array', 'max:20'],
            'certifications.*' => ['string', 'max:60'],
            'bio' => ['nullable', 'string', 'max:3000'],
            'commune' => ['required', 'string', 'max:80'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'mobility' => ['required', 'string', 'max:40'],
            'availability' => ['required', 'in:'.implode(',', self::AVAILABILITIES)],
            'available_days' => ['nullable', 'array'],
            'available_days.*' => ['in:Lun,Mar,Mer,Jeu,Ven,Sam,Dim'],
            'payout_method' => ['required', 'in:'.implode(',', self::PAYOUT_METHODS)],
            'payout_number' => ['nullable', 'string', 'max:30'],
            'target_site' => ['nullable', 'string', 'max:120'],
            'target_site_commune' => ['nullable', 'string', 'max:80'],
            'consent' => ['accepted'],
        ];
    }
}
