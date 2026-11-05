<?php

namespace App\Http\Requests\V1\Admin\Offer;

use App\Enums\OfferType;
use App\Enums\Priority;
use App\Support\Admin\OfferDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste et de l’export des besoins du Back Office.
 */
class OfferIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(OfferDirectory::STATUSES)],
            'trade_id' => ['nullable', 'uuid'],
            'commune_id' => ['nullable', 'uuid'],
            'site_id' => ['nullable', 'uuid'],
            'offer_type' => ['nullable', Rule::enum(OfferType::class)],
            'urgency' => ['nullable', Rule::enum(Priority::class)],
            'todo' => ['nullable', Rule::in(array_keys(OfferDirectory::TODO))],
            'sort' => ['nullable', Rule::in(array_keys(OfferDirectory::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
