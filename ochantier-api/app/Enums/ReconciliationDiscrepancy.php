<?php

namespace App\Enums;

/**
 * Valeurs de `discrepancy_type` pour un écart de rapprochement.
 */
enum ReconciliationDiscrepancy: string
{
    case EcartMontant = 'ecart_montant';
    case AbsentPlateforme = 'absent_plateforme';
    case AbsentPrestataire = 'absent_prestataire';
    case Doublon = 'doublon';
    case StatutDifferent = 'statut_different';

    public function label(): string
    {
        return match ($this) {
            self::EcartMontant => 'Écart de montant',
            self::AbsentPlateforme => 'Absent de la plateforme',
            self::AbsentPrestataire => 'Absent chez le prestataire',
            self::Doublon => 'Doublon',
            self::StatutDifferent => 'Statut différent',
        };
    }
}
