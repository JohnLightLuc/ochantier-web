<?php

namespace App\Http\Requests\V1\Admin\Worker;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation groupée de dossiers ouvriers.
 */
class WorkerBulkApprovalRequest extends FormRequest
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
        return ['ids.required' => 'Sélectionnez au moins un ouvrier.'];
    }
}
