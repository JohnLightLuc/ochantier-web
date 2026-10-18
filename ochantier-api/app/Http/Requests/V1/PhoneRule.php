<?php

namespace App\Http\Requests\V1;

use App\Enums\UserType;
use App\Models\User;
use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numéro ivoirien à 10 chiffres (avec ou sans +225), éventuellement non encore utilisé par un compte du type donné.
 */
class PhoneRule implements ValidationRule
{
    public function __construct(public ?UserType $uniqueFor = null, public ?string $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = Phone::digits(is_string($value) ? $value : '');

        if (strlen($digits) !== 10) {
            $fail('Saisissez un numéro à 10 chiffres.');

            return;
        }

        $existing = $this->uniqueFor ? User::findByContact($this->uniqueFor, $digits) : null;

        if ($existing && $existing->getKey() !== $this->ignoreId) {
            $fail('Ce numéro est déjà utilisé par un autre compte.');
        }
    }
}
