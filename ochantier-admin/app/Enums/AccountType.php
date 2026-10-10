<?php

namespace App\Enums;

enum AccountType: string
{
    case Company = 'company';
    case Institution = 'institution';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Entreprise',
            self::Institution => 'Institution',
            self::Individual => 'Particulier',
        };
    }
}
