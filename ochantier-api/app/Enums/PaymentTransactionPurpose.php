<?php

namespace App\Enums;

/**
 * Valeurs de `purpose` pour une transaction de paiement.
 */
enum PaymentTransactionPurpose: string
{
    case Acompte = 'acompte';
    case Echeance = 'echeance';
    case ReversementOuvrier = 'reversement_ouvrier';
    case GainCommercial = 'gain_commercial';
    case Remboursement = 'remboursement';
    case Abonnement = 'abonnement';

    public function label(): string
    {
        return match ($this) {
            self::Acompte => 'Acompte',
            self::Echeance => 'Échéance',
            self::ReversementOuvrier => 'Reversement ouvrier',
            self::GainCommercial => 'Gain commercial',
            self::Remboursement => 'Remboursement',
            self::Abonnement => 'Abonnement',
        };
    }
}
