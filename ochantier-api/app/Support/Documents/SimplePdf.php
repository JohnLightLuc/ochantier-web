<?php

namespace App\Support\Documents;

/**
 * PDF A4 texte seul (Helvetica), pour les reçus de paiement et les rapports : pas de dépendance externe. Une
 * nouvelle page commence quand la précédente est pleine.
 *
 * Le texte est encodé en Windows-1252 (accents français, apostrophe typographique, espace insécable).
 */
class SimplePdf
{
    private const TOP = 790;

    private const BOTTOM = 56;

    /**
     * @var list<list<array{0: float, 1: float, 2: float, 3: bool, 4: string}>> par page : x, y, taille, gras, texte
     */
    private array $pages = [[]];

    private float $y = self::TOP;

    public function title(string $text): static
    {
        return $this->write($text, 18, true, 26);
    }

    public function heading(string $text): static
    {
        $this->y -= 10;

        return $this->write($text, 12, true, 18);
    }

    public function text(string $text, float $size = 10, bool $bold = false): static
    {
        return $this->write($text, $size, $bold, $size + 6);
    }

    /**
     * Ligne libellé / montant, le montant aligné à droite.
     */
    public function row(string $label, string $value, bool $bold = false): static
    {
        $this->ensureRoom(16);
        $this->pages[array_key_last($this->pages)][] = [56, $this->y, 10, $bold, $label];
        $this->pages[array_key_last($this->pages)][] = [539 - $this->width($value, 10, $bold), $this->y, 10, $bold, $value];
        $this->y -= 16;

        return $this;
    }

    public function space(float $height = 10): static
    {
        $this->y -= $height;

        return $this;
    }

    public function render(): string
    {
        $count = count($this->pages);
        $fonts = 3 + 2 * $count;
        $kids = implode(' ', array_map(fn (int $i) => (3 + 2 * $i).' 0 R', array_keys($this->pages)));
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.$kids.'] /Count '.$count.' >>',
        ];

        foreach ($this->pages as $i => $lines) {
            $content = '';

            foreach ($lines as [$x, $y, $size, $bold, $text]) {
                $content .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, $this->escape($text));
            }

            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents '.(4 + 2 * $i).' 0 R /Resources << /Font << /F1 '.$fonts.' 0 R /F2 '.($fonts + 1).' 0 R >> >> >>';
            $objects[] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';
        }

        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function write(string $text, float $size, bool $bold, float $height): static
    {
        $this->ensureRoom($height);
        $this->pages[array_key_last($this->pages)][] = [56, $this->y, $size, $bold, $text];
        $this->y -= $height;

        return $this;
    }

    private function ensureRoom(float $height): void
    {
        if ($this->y - $height < self::BOTTOM) {
            $this->pages[] = [];
            $this->y = self::TOP;
        }
    }

    private function escape(string $text): string
    {
        $encoded = mb_convert_encoding(str_replace(['→', '≥'], ['->', '>='], $text), 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    /**
     * Largeur approximative du texte (Helvetica : environ 0,5 em par caractère, 0,55 en gras).
     */
    private function width(string $text, float $size, bool $bold): float
    {
        return mb_strlen($text) * $size * ($bold ? 0.55 : 0.5);
    }
}
