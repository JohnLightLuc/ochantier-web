<?php

namespace App\Http\Requests\V1\Admin\Claim;

use App\Enums\ClaimCategory;
use App\Enums\Priority;
use App\Http\Requests\V1\PhoneFields;
use App\Support\Admin\ClaimDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Réclamation saisie au Back Office : le demandeur est retrouvé par son numéro, le chantier par sa référence.
 */
class StoreClaimRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'claimant_type' => ['required', Rule::in(array_keys(ClaimDirectory::CLAIMANT_TYPES))],
            ...$this->phoneRules(),
            'site_reference' => ['nullable', 'string', 'max:25'],
            'category' => ['required', Rule::enum(ClaimCategory::class)],
            'priority' => ['required', Rule::enum(Priority::class)],
            'subject' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            'subject.required' => 'Indiquez l’objet.',
            'subject.min' => 'L’objet est trop court.',
            'description.required' => 'Décrivez la demande.',
            'description.min' => 'La description est trop courte.',
        ];
    }
}
