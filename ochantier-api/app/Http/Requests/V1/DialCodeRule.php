<?php

namespace App\Http\Requests\V1;

use App\Models\Country;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Indicatif d’un pays ouvert sur O’CHANTIER (« 225 », « +225 » ou « 00225 »).
 */
class DialCodeRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $dialCode = ltrim(PhoneNumber::digits($value), '0');

        if (! in_array($dialCode, Country::dialCodes(), true)) {
            $fail('Cet indicatif n’est pas pris en charge.');
        }
    }
}
