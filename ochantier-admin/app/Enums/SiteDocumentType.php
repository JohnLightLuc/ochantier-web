<?php

namespace App\Enums;

/**
 * Pièces du dossier d’un chantier.
 */
enum SiteDocumentType: string
{
    case BuildingPermit = 'building_permit';
    case ExecutionPlan = 'execution_plan';
    case SettingOutReport = 'setting_out_report';
    case Insurance = 'insurance';
    case SafetyPlan = 'safety_plan';
    case AcceptanceReport = 'acceptance_report';

    public function label(): string
    {
        return match ($this) {
            self::BuildingPermit => 'Permis de construire',
            self::ExecutionPlan => 'Plan d’exécution',
            self::SettingOutReport => 'PV d’implantation',
            self::Insurance => 'Attestation d’assurance chantier',
            self::SafetyPlan => 'Plan de prévention HSE',
            self::AcceptanceReport => 'PV de réception',
        };
    }

    public function kind(): string
    {
        return match ($this) {
            self::BuildingPermit, self::ExecutionPlan => 'Autorisation',
            self::SettingOutReport, self::Insurance => 'Administratif',
            self::SafetyPlan, self::AcceptanceReport => 'Sécurité',
        };
    }
}
