<?php

namespace App\Http\Requests\V1\Admin\Promoter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation groupée : promoteurs sélectionnés dans la liste.
 */
class PromoterBulkApprovalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['ids.required' => 'Sélectionnez au moins un promoteur.'];
    }
}
