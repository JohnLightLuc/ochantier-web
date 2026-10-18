<?php

namespace App\Http\Requests\V1;

use App\Enums\UserType;
use App\Support\PhoneNumber;

/**
 * Numéro de téléphone envoyé en deux champs : <prefix>dial_code (« 225 ») et <prefix>phone_number (« 0708091011 »).
 */
trait PhoneFields
{
    /**
     * Règles des deux champs ; $presence s’applique au numéro, l’indicatif devenant obligatoire avec lui.
     *
     * @param  list<string>  $presence
     * @return array<string, array<int, mixed>>
     */
    protected function phoneRules(array $presence = ['required'], string $prefix = '', ?UserType $uniqueFor = null, ?string $ignoreId = null): array
    {
        return [
            "{$prefix}dial_code" => ["required_with:{$prefix}phone_number", 'nullable', 'string', 'max:6', new DialCodeRule],
            "{$prefix}phone_number" => [...$presence, "required_with:{$prefix}dial_code", 'nullable', 'string', 'max:20', new PhoneRule($uniqueFor, $ignoreId, $prefix)],
        ];
    }

    /**
     * Numéro saisi, ou null s’il n’a pas été envoyé (ou a été vidé).
     */
    public function phoneNumber(string $prefix = ''): ?PhoneNumber
    {
        return PhoneNumber::from($this->input("{$prefix}dial_code"), $this->input("{$prefix}phone_number"));
    }

    /**
     * @return array<string, string>
     */
    protected function phoneMessages(string $prefix = ''): array
    {
        return [
            "{$prefix}dial_code.required_with" => 'Sélectionnez l’indicatif du pays.',
            "{$prefix}phone_number.required" => 'Saisissez un numéro.',
            "{$prefix}phone_number.required_with" => 'Saisissez un numéro.',
            "{$prefix}phone_number.max" => 'Saisissez un numéro valide.',
            "{$prefix}dial_code.max" => 'Cet indicatif n’est pas pris en charge.',
        ];
    }
}
