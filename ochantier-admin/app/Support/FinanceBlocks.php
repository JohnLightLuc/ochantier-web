<?php

namespace App\Support;

/**
 * Blocs des pages Finance & Reporting : blocs communs et actions soumises au rôle financier (export, refus).
 */
class FinanceBlocks extends PageBlocks
{
    /**
     * @return array<string, string>
     */
    public static function export(string $report): array
    {
        return FinanceInsights::can('export') ? ['type' => 'export', 'report' => $report] : self::deny('export');
    }

    /**
     * @return array<string, string>
     */
    public static function deny(string $what = ''): array
    {
        return self::error(FinanceInsights::denied($what));
    }
}
