<?php

namespace App\Http\Requests\V1\Admin\Subscription;

use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d’une formule d’abonnement.
 */
class SubscriptionPlanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique(SubscriptionPlan::class, 'name')->ignore($this->route('planId'))],
            'price_amount' => ['required', 'integer', 'min:1000'],
            'max_users' => ['required', 'integer', 'min:2'],
            'features' => ['required', 'array', 'min:1'],
            'features.*' => ['required', 'string', 'max:160'],
            'is_featured' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Indiquez le nom de la formule.',
            'name.unique' => 'Une formule porte déjà ce nom.',
            'price_amount.*' => 'Prix minimum : 1 000 FCFA.',
            'max_users.*' => 'Indiquez un nombre entier, 2 minimum.',
            'features.required' => 'Ajoutez au moins un avantage.',
            'features.min' => 'Ajoutez au moins un avantage.',
        ];
    }

    /**
     * @return array{name: string, price_amount: int, max_users: int, features: list<string>, is_featured: bool}
     */
    public function plan(): array
    {
        $data = $this->validated();

        return [
            'name' => trim($data['name']),
            'price_amount' => (int) $data['price_amount'],
            'max_users' => (int) $data['max_users'],
            'features' => array_values(array_map('trim', $data['features'])),
            'is_featured' => (bool) $data['is_featured'],
        ];
    }
}
