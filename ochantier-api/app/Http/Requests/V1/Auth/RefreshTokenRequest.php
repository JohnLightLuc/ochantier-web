<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Jeton de rafraîchissement à échanger contre une nouvelle paire de jetons.
 */
class RefreshTokenRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['refresh_token' => ['required', 'string', 'size:64']];
    }
}
