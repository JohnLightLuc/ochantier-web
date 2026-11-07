<?php

namespace App\Support\Admin;

use App\Enums\ExportFormat;
use App\Support\Documents\SimplePdf;
use RuntimeException;
use ZipArchive;

/**
 * Fichiers d’un rapport : CSV, Excel (.xlsx écrit directement, sans dépendance) et PDF.
 */
class ReportFiles
{
    private const PDF_LINE = 95;

    /**
     * @param  array<string, mixed>  $report  ReportCatalog::build
     * @return array{content: string, mime: string, extension: string}
     */
    public function render(array $report, ExportFormat $format): array
    {
        return match ($format) {
            ExportFormat::Csv => ['content' => $this->csv($report), 'mime' => 'text/csv', 'extension' => 'csv'],
            ExportFormat::Xlsx => ['content' => $this->xlsx($report), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'extension' => 'xlsx'],
            ExportFormat::Pdf => ['content' => $this->pdf($report), 'mime' => 'application/pdf', 'extension' => 'pdf'],
        };
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function csv(array $report): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_column($report['columns'], 'label'), ';');

        foreach ($report['rows'] as $row) {
            fputcsv($out, array_map(fn (array $column) => $row[$column['key']] ?? '', $report['columns']), ';');
        }

        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return $content;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function xlsx(array $report): string
    {
        $rows = [array_column($report['columns'], 'label')];

        foreach ($report['rows'] as $row) {
            $rows[] = array_map(fn (array $column) => $row[$column['key']] ?? null, $report['columns']);
        }

        $sheet = '';

        foreach ($rows as $index => $cells) {
            $sheet .= '<row r="'.($index + 1).'">';

            foreach ($cells as $column => $value) {
                $reference = $this->column($column).($index + 1);
                $sheet .= match (true) {
                    $value === null => '',
                    is_int($value), is_float($value) => '<c r="'.$reference.'"><v>'.$value.'</v></c>',
                    default => '<c r="'.$reference.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>',
                };
            }

            $sheet .= '</row>';
        }

        $path = tempnam(sys_get_temp_dir(), 'report');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer le fichier Excel.');
        }

        $header = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
        $zip->addFromString('[Content_Types].xml', $header.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', $header.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $header.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Rapport" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $header.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $header.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="'.count($rows[0]).'" width="24" customWidth="1"/></cols><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->close();

        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function column(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = (int) (($index - 1) / 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    /**
     * Une fiche par ligne : le premier champ en gras, les autres repliés sur la largeur de la page.
     *
     * @param  array<string, mixed>  $report
     */
    private function pdf(array $report): string
    {
        $pdf = (new SimplePdf)->title($report['title'])->text('Du '.$report['period']['from'].' au '.$report['period']['to'])->space();

        foreach ($report['indicators'] as $indicator) {
            $pdf->row($indicator['label'], $this->value($indicator['value'], $indicator['money'] ?? false), true);
        }

        $pdf->heading('Détail');
        [$first, $others] = [$report['columns'][0], array_slice($report['columns'], 1)];

        foreach ($report['rows'] as $row) {
            $pdf->text((string) ($row[$first['key']] ?? '—'), 10, true);
            $details = implode(' · ', array_map(fn (array $column) => $column['label'].' : '.$this->value($row[$column['key']] ?? null, $column['money'] ?? false), $others));

            foreach (explode("\n", wordwrap($details, self::PDF_LINE, "\n", true)) as $line) {
                $pdf->text($line, 8);
            }

            $pdf->space(4);
        }

        if ($report['rows'] === []) {
            $pdf->text('Aucune donnée sur cette période.');
        }

        return $pdf->render();
    }

    private function value(mixed $value, bool $money): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            $money => number_format((int) $value, 0, ',', "\u{00A0}")."\u{00A0}FCFA",
            default => (string) $value,
        };
    }
}
