<?php

namespace App\Http\Requests\V1\Admin\Payout;

use App\Enums\WorkerPayoutStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste et de l’export des reversements.
 */
class PayoutIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(WorkerPayoutStatus::class)],
            'batch_id' => ['nullable', 'uuid'],
            'worker_id' => ['nullable', 'uuid'],
            'site_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
