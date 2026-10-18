<?php

namespace App\Support;

/**
 * Sous-ensemble des règles d’affectation du Back-office (App\Support\AssignmentInsights côté admin) utilisé par les
 * modèles partagés : jours ouvrés et acompte. Les écrans d’affectation restent dans le Back-office.
 */
class AssignmentInsights
{
    public static function depositRate(): int
    {
        return (int) config('admin.need_rules.deposit_rate');
    }

    /**
     * Jours ouvrés (6 jours sur 7) d’une période.
     */
    public static function workingDays(int $duration): int
    {
        return max(1, (int) round($duration * 6 / 7));
    }

    /**
     * Acompte arrondi à 500 FCFA.
     */
    public static function depositOf(int $total): int
    {
        return (int) round($total * self::depositRate() / 100 / 500) * 500;
    }
}
