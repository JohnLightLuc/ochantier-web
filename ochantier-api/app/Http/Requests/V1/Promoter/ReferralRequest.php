<?php

namespace App\Http\Requests\V1\Promoter;

use App\Http\Requests\V1\PhoneFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * « Inviter par SMS » (parrainage) : numéro du proche et, facultatif, son nom.
 */
class ReferralRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->phoneRules(), 'name' => ['nullable', 'string', 'max:160']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->phoneMessages(), 'name.max' => 'Le nom ne doit pas dépasser 160 caractères.'];
    }
}
