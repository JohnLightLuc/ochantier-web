<?php

namespace App\Http\Requests\V1\Contractor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Brouillon du devis (« Lignes de travaux », « Totaux », « Durée, conditions et validité ») : seuls les champs
 * envoyés sont enregistrés ; les lignes envoyées remplacent les précédentes.
 */
class QuoteVersionRequest extends FormRequest
{
    public const UNITS = ['u', 'm', 'm²', 'ml', 'm³', 'h', 'jour', 'forfait'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.designation' => ['present', 'nullable', 'string', 'max:255'],
            'items.*.unit' => ['nullable', Rule::in(self::UNITS)],
            'items.*.quantity' => ['present', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'items.*.unit_price' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'vat_applied' => ['sometimes', 'boolean'],
            'works_duration' => ['sometimes', 'nullable', 'string', 'max:60'],
            'conditions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'validity_days' => ['sometimes', 'integer', Rule::in([15, 30, 60, 90])],
            'observations' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.unit.in' => 'Unité non proposée.',
            'items.*.quantity.numeric' => 'Quantité invalide.',
            'items.*.unit_price.integer' => 'Prix unitaire invalide.',
            'validity_days.in' => 'Validité : 15, 30, 60 ou 90 jours.',
        ];
    }

    /**
     * Champs validés ; les cellules vides des lignes deviennent 0 (le contrôle complet a lieu à l’envoi).
     *
     * @return array<string, mixed>
     */
    public function versionData(): array
    {
        $data = $this->validated();

        if (isset($data['items'])) {
            $data['items'] = array_map(fn (array $item) => [
                'designation' => (string) ($item['designation'] ?? ''),
                'unit' => $item['unit'] ?? 'u',
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit_price' => (int) ($item['unit_price'] ?? 0),
            ], $data['items']);
        }

        return $data;
    }
}
