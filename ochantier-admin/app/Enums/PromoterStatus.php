<?php

namespace App\Enums;

enum PromoterStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Pending => 'À valider',
            self::Suspended => 'Suspendu',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'g',
            self::Pending => 'a',
            self::Suspended => 'r',
        };
    }
}
