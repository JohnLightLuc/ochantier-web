<?php

namespace App\Enums;

/**
 * Valeurs de `anomaly_type` pour une anomalie de pointage.
 */
enum AttendanceAnomalyType: string
{
    case Absence = 'absence';
    case Retard = 'retard';
    case DepartAnticipe = 'depart_anticipe';
    case Doublon = 'doublon';
    case HorsZone = 'hors_zone';
    case PreuveManquante = 'preuve_manquante';
    case IncoherenceHoraire = 'incoherence_horaire';
    case PhotoReutilisee = 'photo_reutilisee';

    public function label(): string
    {
        return match ($this) {
            self::Absence => 'Absence',
            self::Retard => 'Retard',
            self::DepartAnticipe => 'Départ anticipé',
            self::Doublon => 'Doublon',
            self::HorsZone => 'Hors zone',
            self::PreuveManquante => 'Preuve manquante',
            self::IncoherenceHoraire => 'Incohérence horaire',
            self::PhotoReutilisee => 'Photo réutilisée',
        };
    }
}
