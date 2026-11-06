<?php

namespace App\Http\Requests\V1\Admin\Payout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lignes de « À reverser » retenues dans un nouveau lot.
 */
class BatchStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.schedule_id' => ['required', 'uuid', 'exists:payment_schedules,id'],
            'items.*.assignment_id' => ['required', 'uuid', 'exists:assignments,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['items.required' => 'Choisissez au moins une ligne.'];
    }
}
