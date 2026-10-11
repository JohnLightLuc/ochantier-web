<?php

namespace App\Enums;

/**
 * Catégorie d’un événement du journal d’un commercial.
 */
enum SalesAgentActivityCategory: string
{
    case Creation = 'creation';
    case Invitation = 'invitation';
    case Activation = 'activation';
    case Login = 'connexion';
    case Update = 'modification';
    case Permission = 'permission';
    case Zone = 'zone';
    case Availability = 'disponibilite';
    case Worker = 'ouvrier';
    case Validation = 'validation';
    case Rejection = 'rejet';
    case Objective = 'objectif';
    case Score = 'score';
    case Ranking = 'classement';
    case Bonus = 'bonus';
    case Deduction = 'retenue';
    case Payment = 'paiement';
    case Suspension = 'suspension';
    case Reactivation = 'reactivation';
    case Status = 'statut';
    case Security = 'securite';

    public function label(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Invitation => 'Invitation',
            self::Activation => 'Activation',
            self::Login => 'Connexion',
            self::Update => 'Modification',
            self::Permission => 'Permissions',
            self::Zone => 'Zone',
            self::Availability => 'Disponibilité',
            self::Worker => 'Ouvrier',
            self::Validation => 'Validation',
            self::Rejection => 'Rejet',
            self::Objective => 'Objectif',
            self::Score => 'Score',
            self::Ranking => 'Classement',
            self::Bonus => 'Bonus',
            self::Deduction => 'Retenue',
            self::Payment => 'Paiement',
            self::Suspension => 'Suspension',
            self::Reactivation => 'Réactivation',
            self::Status => 'Statut',
            self::Security => 'Sécurité',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Creation, self::Activation, self::Worker, self::Validation, self::Bonus, self::Payment, self::Reactivation => 'success',
            self::Invitation, self::Zone, self::Objective => 'info',
            self::Login, self::Status => 'neutral',
            self::Update, self::Permission => 'purple',
            self::Availability, self::Score => 'warning',
            self::Ranking => 'orange',
            self::Rejection, self::Deduction, self::Suspension, self::Security => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Creation => 'person_add',
            self::Invitation => 'forward_to_inbox',
            self::Activation => 'how_to_reg',
            self::Login => 'login',
            self::Update => 'edit',
            self::Permission => 'admin_panel_settings',
            self::Zone => 'map',
            self::Availability => 'event_available',
            self::Worker => 'engineering',
            self::Validation => 'verified',
            self::Rejection => 'cancel',
            self::Objective => 'flag',
            self::Score => 'analytics',
            self::Ranking => 'leaderboard',
            self::Bonus => 'workspace_premium',
            self::Deduction => 'money_off',
            self::Payment => 'payments',
            self::Suspension => 'block',
            self::Reactivation => 'settings_backup_restore',
            self::Status => 'swap_vert',
            self::Security => 'shield_lock',
        };
    }
}
