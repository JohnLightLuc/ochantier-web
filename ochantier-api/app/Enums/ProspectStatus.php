<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un prospect.
 */
enum ProspectStatus: string
{
    case Nouveau = 'nouveau';
    case AContacter = 'a_contacter';
    case RendezVous = 'rendez_vous';
    case ACompleter = 'a_completer';
    case Converti = 'converti';
    case Abandonne = 'abandonne';

    public function label(): string
    {
        return match ($this) {
            self::Nouveau => 'Nouveau',
            self::AContacter => 'À contacter',
            self::RendezVous => 'Rendez-vous',
            self::ACompleter => 'À compléter',
            self::Converti => 'Converti',
            self::Abandonne => 'Abandonné',
        };
    }
}
