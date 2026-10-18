<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un lot de reversements.
 */
enum WorkerPayoutBatchStatus: string
{
    case Brouillon = 'brouillon';
    case AValider = 'a_valider';
    case EnExecution = 'en_execution';
    case Termine = 'termine';
    case Partiel = 'partiel';
    case Echoue = 'echoue';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::AValider => 'À valider',
            self::EnExecution => 'En exécution',
            self::Termine => 'Terminé',
            self::Partiel => 'Partiel',
            self::Echoue => 'Échoué',
            self::Annule => 'Annulé',
        };
    }
}
