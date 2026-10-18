<?php

namespace App\Enums;

/**
 * Format d’un export ou d’un rapport.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel',
            self::Pdf => 'PDF',
        };
    }
}
