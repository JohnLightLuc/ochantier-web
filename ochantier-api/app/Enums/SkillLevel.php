<?php

namespace App\Enums;

/**
 * Niveau de maîtrise d’un métier ou d’une compétence.
 */
enum SkillLevel: string
{
    case Debutant = 'debutant';
    case Intermediaire = 'intermediaire';
    case Confirme = 'confirme';
    case Expert = 'expert';

    public function label(): string
    {
        return match ($this) {
            self::Debutant => 'Débutant',
            self::Intermediaire => 'Intermédiaire',
            self::Confirme => 'Confirmé',
            self::Expert => 'Expert',
        };
    }
}
