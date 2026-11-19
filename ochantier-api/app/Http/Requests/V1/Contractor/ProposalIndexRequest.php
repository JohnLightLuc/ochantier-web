<?php

namespace App\Http\Requests\V1\Contractor;

use App\Support\Contractor\ContractorQuoteProposals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Onglet et recherche de « Mes offres » (propositions de devis).
 */
class ProposalIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(array_keys(ContractorQuoteProposals::TABS))],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
