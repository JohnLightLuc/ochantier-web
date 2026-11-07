<?php

namespace App\Http\Requests\V1\Admin\Access;

use App\Models\Admin;
use App\Models\AdminRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invitation d’un administrateur.
 */
class InviteAdminRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190', Rule::unique(Admin::class, 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'department' => ['nullable', 'string', 'max:40'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::exists(AdminRole::class, 'code')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Indiquez le prénom.',
            'last_name.required' => 'Indiquez le nom.',
            'email.required' => 'Indiquez l’adresse e-mail.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'email.unique' => 'Un compte existe déjà avec cette adresse.',
            'roles.required' => 'Choisissez au moins un rôle.',
            'roles.min' => 'Choisissez au moins un rôle.',
            'roles.*.exists' => 'Rôle inconnu.',
        ];
    }
}
