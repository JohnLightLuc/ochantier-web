<?php

namespace App\Http\Requests\V1\Admin\Payment;

use App\Support\Admin\PaymentDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste et de l’export des encaissements du Back Office.
 */
class TransactionIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(PaymentDirectory::TRANSACTION_STATUSES)],
            'purpose' => ['nullable', Rule::in(PaymentDirectory::PURPOSES)],
            'operator_id' => ['nullable', 'uuid'],
            'site_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(array_keys(PaymentDirectory::TRANSACTION_SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
