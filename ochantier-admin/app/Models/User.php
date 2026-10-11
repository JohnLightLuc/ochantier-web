<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'role', 'status', 'modules', 'two_factor', 'last_login_at', 'invitation_expires_on'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor' => 'boolean',
            'last_login_at' => 'datetime',
            'invitation_expires_on' => 'date',
        ];
    }

    /**
     * Seuls les comptes actifs peuvent se connecter (pas les comptes suspendus ni les invitations en attente).
     */
    public function isActive(): bool
    {
        return $this->status === 'Actif';
    }
}
