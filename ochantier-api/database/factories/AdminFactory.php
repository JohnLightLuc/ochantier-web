<?php

namespace Database\Factories;

use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Models\AdminRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    /**
     * Mot de passe des administrateurs de test.
     */
    public const PASSWORD = 'MotDePasse#2026';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'department' => 'operations',
            'password_hash' => self::PASSWORD,
            'status' => AdminStatus::Actif,
            'mfa_enabled' => true,
            'must_change_password' => false,
        ];
    }

    /**
     * Administrateur rattaché à un rôle système (super_admin, operations, finance…), créé s’il n’existe pas.
     */
    public function role(string $code): static
    {
        return $this->afterCreating(function (Admin $admin) use ($code): void {
            $role = AdminRole::query()->firstOrCreate(['code' => $code], ['label' => $code, 'is_system' => true]);
            $admin->roles()->attach($role->getKey(), ['granted_at' => now()]);
        });
    }
}
