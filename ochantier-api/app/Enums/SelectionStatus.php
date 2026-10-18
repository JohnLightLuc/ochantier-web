<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une sélection du promoteur.
 */
enum SelectionStatus: string
{
    case Selectionne = 'selectionne';
    case Reserve = 'reserve';
    case Confirme = 'confirme';
    case Desiste = 'desiste';
    case Remplace = 'remplace';
    case Expire = 'expire';

    public function label(): string
    {
        return match ($this) {
            self::Selectionne => 'Sélectionné',
            self::Reserve => 'Réservé',
            self::Confirme => 'Confirmé',
            self::Desiste => 'Désisté',
            self::Remplace => 'Remplacé',
            self::Expire => 'Expiré',
        };
    }
}
