<?php

namespace App\Http\Requests\V1\Contractor;

use App\Support\Contractor\ContractorTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Remplacer l’ouvrier » : motif, date d’effet et nouvel ouvrier (tarif journalier facultatif : celui déclaré par
 * l’ouvrier par défaut).
 */
class ReplaceWorkerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(ContractorTeam::REPLACEMENT_REASONS)],
            'other_reason' => ['required_if:reason,Autre', 'nullable', 'string', 'max:500'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'worker_id' => ['required', 'uuid'],
            'daily_rate' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Le motif du remplacement est obligatoire.',
            'reason.in' => 'Le motif du remplacement est obligatoire.',
            'other_reason.required_if' => 'Précisez le motif du remplacement.',
            'effective_date.required' => 'Choisissez la date d’effet.',
            'worker_id.required' => 'Choisissez le nouvel ouvrier.',
        ];
    }

    public function reasonText(): string
    {
        return $this->input('reason') === 'Autre' ? trim((string) $this->input('other_reason')) : (string) $this->input('reason');
    }
}
