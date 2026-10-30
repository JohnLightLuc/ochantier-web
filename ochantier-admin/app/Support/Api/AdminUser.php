<?php

namespace App\Support\Api;

use Illuminate\Auth\GenericUser;

/**
 * Administrateur connecté, tel que décrit par l’API (GET /me) : identité, rôles et permissions.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $role
 */
class AdminUser extends GenericUser
{
    /**
     * @param  array<string, mixed>  $admin  Réponse AdminResource de l’API.
     */
    public static function fromApi(array $admin): self
    {
        return new self([
            ...$admin,
            'name' => $admin['full_name'],
            'role' => $admin['roles'][0]['label'] ?? 'Administrateur',
        ]);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->attributes['permissions'] ?? [];
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissions(), true);
    }

    /**
     * Mot de passe provisoire à remplacer avant d’accéder aux modules.
     */
    public function mustChangePassword(): bool
    {
        return (bool) ($this->attributes['must_change_password'] ?? false);
    }
}
