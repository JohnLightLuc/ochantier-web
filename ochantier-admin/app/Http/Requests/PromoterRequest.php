<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d’un promoteur. En mode « company », seules l’entreprise et le représentant sont modifiés.
 */
class PromoterRequest extends FormRequest
{
    public const LEGAL_FORMS = ['SA', 'SARL', 'SAS', 'Société d’État', 'Entreprise individuelle'];

    public const SECTORS = ['Promotion immobilière', 'BTP · Gros œuvre', 'BTP · Second œuvre', 'Aménagement'];

    public const LOCATIONS = ['Abidjan, Cocody', 'Abidjan, Plateau', 'Abidjan, Marcory', 'Abidjan, Yopougon', 'Bingerville', 'Yamoussoukro', 'Bouaké', 'Grand-Bassam', 'San-Pédro'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function isCompanyOnly(): bool
    {
        return $this->input('mode') === 'company';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->isCompanyOnly() ? [] : [
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'location' => ['required', Rule::in(self::LOCATIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];

        return [
            'mode' => ['nullable', Rule::in(['company'])],
            'name' => ['required', 'string', 'max:160'],
            'legal_form' => ['required', Rule::in(self::LEGAL_FORMS)],
            'rccm' => ['required', 'regex:/^CI-[A-Z]{3}-\d{4}-[A-Z]-\d{3,6}$/i'],
            'ncc' => ['nullable', 'string', 'max:40'],
            'sector' => ['nullable', Rule::in(self::SECTORS)],
            'head_office' => ['required', 'string', 'max:255'],
            'representative' => ['required', 'string', 'max:160'],
            'representative_role' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^\+225( \d{2}){5}$/'],
            'email' => ['required', 'email'],
            ...$account,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.required' => 'Ce champ est obligatoire.',
            'email.email' => 'Adresse e-mail invalide.',
            'phone.regex' => 'Le numéro doit contenir 10 chiffres.',
            'rccm.regex' => 'Format attendu : CI-ABJ-2026-B-00000.',
        ];
    }

    /**
     * Données à enregistrer, sans le mode du formulaire.
     *
     * @return array<string, mixed>
     */
    public function promoterData(): array
    {
        return collect($this->validated())->except('mode')->all();
    }
}
