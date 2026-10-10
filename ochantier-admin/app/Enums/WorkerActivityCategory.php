<?php

namespace App\Enums;

/**
 * Catégorie d’un événement du journal d’un ouvrier.
 */
enum WorkerActivityCategory: string
{
    case Creation = 'creation';
    case Update = 'modification';
    case Validation = 'validation';
    case Rejection = 'rejet';
    case Status = 'statut';
    case Availability = 'disponibilite';
    case Assignment = 'affectation';
    case Timesheet = 'pointage';
    case Payment = 'paiement';
    case Review = 'avis';
    case Dispute = 'litige';
    case Unavailability = 'indisponibilite';
    case Replacement = 'remplacement';
    case Suspension = 'suspension';
    case Reactivation = 'reactivation';

    public function label(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Update => 'Modification',
            self::Validation => 'Validation',
            self::Rejection => 'Rejet',
            self::Status => 'Changement de statut',
            self::Availability => 'Disponibilité',
            self::Assignment => 'Affectation',
            self::Timesheet => 'Pointage',
            self::Payment => 'Paiement',
            self::Review => 'Avis',
            self::Dispute => 'Litige',
            self::Unavailability => 'Indisponibilité',
            self::Replacement => 'Remplacement',
            self::Suspension => 'Suspension',
            self::Reactivation => 'Réactivation',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Creation, self::Validation, self::Payment, self::Reactivation => 'success',
            self::Update => 'purple',
            self::Rejection, self::Dispute, self::Suspension => 'danger',
            self::Status, self::Assignment => 'info',
            self::Availability, self::Review => 'warning',
            self::Timesheet => 'neutral',
            self::Unavailability, self::Replacement => 'orange',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Creation => 'person_add',
            self::Update => 'edit',
            self::Validation => 'verified',
            self::Rejection => 'cancel',
            self::Status => 'swap_vert',
            self::Availability => 'event_available',
            self::Assignment => 'assignment_ind',
            self::Timesheet => 'schedule',
            self::Payment => 'payments',
            self::Review => 'star',
            self::Dispute => 'gavel',
            self::Unavailability => 'event_busy',
            self::Replacement => 'swap_horiz',
            self::Suspension => 'block',
            self::Reactivation => 'settings_backup_restore',
        };
    }
}
