<?php

namespace App\Http\Requests\V1\Admin\Payout;

use App\Enums\PayoutAdjustmentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Proposition de retenue ou de bonus sur un reversement.
 */
class AdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(PayoutAdjustmentKind::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'basis' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Indiquez le motif.', 'amount.required' => 'Indiquez le montant.'];
    }
}
