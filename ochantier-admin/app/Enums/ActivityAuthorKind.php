<?php

namespace App\Enums;

enum ActivityAuthorKind: string
{
    case Admin = 'admin';
    case Promoter = 'promoter';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Promoter => 'Promoteur',
            self::System => 'Système',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Admin => 'p',
            self::Promoter => 'b',
            self::System => 'n',
        };
    }
}
