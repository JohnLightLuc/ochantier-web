<?php

namespace App\Http\Requests\V1\Admin\Dispute;

use App\Enums\ClaimCategory;
use App\Enums\Priority;
use App\Http\Requests\V1\PhoneFields;
use App\Support\Admin\ClaimDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Litige ouvert directement au Back Office : le demandeur est retrouvé par son numéro, la mission par sa référence.
 */
class StoreDisputeRequest extends FormRequest
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
            'mission_reference' => ['nullable', 'string', 'max:25'],
            'category' => ['required', Rule::enum(ClaimCategory::class)],
            'urgency' => ['required', Rule::enum(Priority::class)],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->phoneMessages(), 'description.required' => 'Décrivez le litige.', 'description.min' => 'La description est trop courte.'];
    }
}
