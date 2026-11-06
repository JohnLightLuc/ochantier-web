<?php

namespace App\Http\Requests\V1\Admin\Payout;

use App\Support\Admin\PayoutDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste « À reverser ».
 */
class PayableIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', Rule::in(PayoutDirectory::PAYABLE_STATES)],
            'site_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
