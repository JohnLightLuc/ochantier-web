<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\Admin;
use Illuminate\Http\Request;

/**
 * Administrateur connecté : identité, état du compte, rôles et permissions (affichage des modules du Back Office).
 *
 * @mixin Admin
 */
class AdminResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'department' => $this->department,
            'status' => $this->status($this->status),
            'mfa_enabled' => $this->requiresMfa(),
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'access_expires_at' => $this->access_expires_at?->toIso8601String(),
            'roles' => $this->roles->map(fn ($role) => ['code' => $role->code, 'label' => $role->label])->values(),
            'permissions' => $this->permissionCodes(),
        ];
    }
}
