<?php

namespace App\Enums;

/**
 * Suivi d’un ouvrier trouvé pour un poste : contact puis validation par l’ouvrier.
 */
enum NeedAssignmentStatus: string
{
    case ToContact = 'to_contact';
    case Contacted = 'contacted';
    case Validated = 'validated';
    case Refused = 'refused';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::ToContact => 'À contacter',
            self::Contacted => 'Contacté',
            self::Validated => 'Validé',
            self::Refused => 'Refusé',
            self::Replaced => 'Remplacé',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ToContact => 'a',
            self::Contacted => 'b',
            self::Validated => 'g',
            self::Refused => 'r',
            self::Replaced => 'n',
        };
    }

    /**
     * L’ouvrier occupe un poste (il compte dans la couverture).
     */
    public function isSelected(): bool
    {
        return ! in_array($this, [self::Refused, self::Replaced], true);
    }
}
