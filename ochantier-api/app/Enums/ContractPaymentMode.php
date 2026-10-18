<?php

namespace App\Enums;

/**
 * Paiement du solde d’une offre contractuelle (« Mode de paiement »).
 */
enum ContractPaymentMode: string
{
    case Hebdomadaire = 'hebdomadaire';
    case ParEtape = 'par_etape';
    case FinDeChantier = 'fin_de_chantier';

    public function label(): string
    {
        return match ($this) {
            self::Hebdomadaire => 'Hebdomadaire',
            self::ParEtape => 'Par étape',
            self::FinDeChantier => 'Fin de chantier',
        };
    }
}
