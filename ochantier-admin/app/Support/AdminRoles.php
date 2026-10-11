<?php

namespace App\Support;

/**
 * Rôles des comptes administratifs et matrice des droits par module et par action.
 */
class AdminRoles
{
    /**
     * Rôle → description.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'Super administrateur' => 'Tous les droits, y compris la gestion des administrateurs',
        'Administrateur' => 'Tous les modules sauf les opérations financières',
        'Responsable opérations' => 'Chantiers, besoins, Matching, affectations et pointages',
        'Responsable financier' => 'Paiements, finance et reporting',
        'Comptable' => 'Consultation et export des données financières',
        'Responsable conformité' => 'Contrôle des profils, des avis et des paramètres',
        'Responsable litiges' => 'Avis, réclamations et litiges',
        'Support' => 'Assistance aux promoteurs et aux ouvriers',
        'Lecture seule' => 'Consultation sans modification',
    ];

    /** Rôles dont les comptes sont limités en nombre (paramètre usr.maxAdmins). */
    public const ADMIN_ROLES = ['Super administrateur', 'Administrateur'];

    public const MODULES = ['Tableau de bord', 'Promoteurs', 'Ouvriers', 'Commerciaux', 'Chantiers', 'Besoins & Offres', 'Matching', 'Affectations', 'Pointages', 'Paiements', 'Finance & Reporting', 'Avis & Litiges', 'Paramètres'];

    public const ACTIONS = ['Lecture', 'Création', 'Modification', 'Validation', 'Suppression logique', 'Exportation', 'Actions sensibles', 'Données confidentielles'];

    private const OPERATIONS = ['Chantiers', 'Besoins & Offres', 'Matching', 'Affectations', 'Pointages'];

    private const FINANCE = ['Paiements', 'Finance & Reporting'];

    /**
     * Le rôle dispose-t-il de l’action sur le module ?
     */
    public static function allows(string $role, string $module, string $action): bool
    {
        $a = array_search($action, self::ACTIONS, true);

        return match ($role) {
            'Super administrateur' => true,
            'Administrateur' => $module !== 'Finance & Reporting' || $a === 0,
            'Responsable opérations' => (in_array($module, self::OPERATIONS, true) && $a <= 5) || ($a === 0 && $module !== 'Paramètres'),
            'Responsable financier' => (in_array($module, self::FINANCE, true) && $a <= 7) || $a === 0,
            'Comptable' => in_array($module, self::FINANCE, true) && in_array($a, [0, 5], true),
            'Responsable conformité' => (in_array($module, ['Promoteurs', 'Ouvriers', 'Avis & Litiges', 'Paramètres'], true) && $a <= 6) || $a === 0,
            'Responsable litiges' => ($module === 'Avis & Litiges' && $a <= 7) || ($a === 0 && $module !== 'Paramètres'),
            'Support' => in_array($module, ['Promoteurs', 'Ouvriers', 'Tableau de bord'], true) && $a <= 2,
            default => $a === 0 && $module !== 'Paramètres',
        };
    }

    /**
     * Nombre de droits accordés au rôle.
     */
    public static function count(string $role): int
    {
        return collect(self::MODULES)->sum(fn (string $m): int => collect(self::ACTIONS)->filter(fn (string $a): bool => self::allows($role, $m, $a))->count());
    }

    /**
     * Actions autorisées du rôle sur un module, en abrégé.
     */
    public static function summary(string $role, string $module): string
    {
        $allowed = array_values(array_filter(self::ACTIONS, fn (string $a): bool => self::allows($role, $module, $a)));

        return match (count($allowed)) {
            0 => '—',
            count(self::ACTIONS) => 'Tous les droits',
            1 => $allowed[0],
            default => $allowed[0].' + '.(count($allowed) - 1),
        };
    }
}
