<?php

namespace App\Http\Requests\V1;

use App\Enums\AppProfile;
use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numéro ivoirien à 10 chiffres (avec ou sans +225), éventuellement non encore utilisé par un compte du profil.
 */
class PhoneRule implements ValidationRule
{
    public function __construct(public ?AppProfile $uniqueFor = null, public ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = Phone::digits(is_string($value) ? $value : '');

        if (strlen($digits) !== 10) {
            $fail('Numéro à 10 chiffres attendu.');

            return;
        }

        $existing = $this->uniqueFor?->findByPhone($digits);

        if ($existing && $existing->getKey() !== $this->ignoreId) {
            $fail('Ce numéro est déjà utilisé par un autre compte.');
        }
    }
}
