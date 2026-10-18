<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une suggestion de matching.
 */
enum SuggestionStatus: string
{
    case Suggere = 'suggere';
    case Contacte = 'contacte';
    case Accepte = 'accepte';
    case Refuse = 'refuse';
    case Indisponible = 'indisponible';
    case Expire = 'expire';
    case ProposePromoteur = 'propose_promoteur';
    case Selectionne = 'selectionne';
    case Ecarte = 'ecarte';

    public function label(): string
    {
        return match ($this) {
            self::Suggere => 'Suggéré',
            self::Contacte => 'Contacté',
            self::Accepte => 'Accepté',
            self::Refuse => 'Refusé',
            self::Indisponible => 'Indisponible',
            self::Expire => 'Expiré',
            self::ProposePromoteur => 'Proposé au promoteur',
            self::Selectionne => 'Sélectionné',
            self::Ecarte => 'Écarté',
        };
    }
}
