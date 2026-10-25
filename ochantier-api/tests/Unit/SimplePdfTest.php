<?php

namespace Tests\Unit;

use App\Support\Documents\SimplePdf;
use PHPUnit\Framework\TestCase;

class SimplePdfTest extends TestCase
{
    public function test_a_short_document_fits_on_one_page(): void
    {
        $pdf = (new SimplePdf)->title('Reçu de paiement')->row('Total payé', '10 000 FCFA', true)->render();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/Kids [3 0 R] /Count 1', $pdf);
        $this->assertStringContainsString('/F1 5 0 R /F2 6 0 R', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
    }

    public function test_a_new_page_starts_when_the_previous_one_is_full(): void
    {
        $pdf = new SimplePdf;

        for ($i = 1; $i <= 100; $i++) {
            $pdf->row('Ligne '.$i, $i.' FCFA');
        }

        $content = $pdf->render();

        $this->assertStringContainsString('/Kids [3 0 R 5 0 R 7 0 R] /Count 3', $content);
        $this->assertSame(3, substr_count($content, '/Type /Page /Parent'));
        $this->assertStringContainsString('/F1 9 0 R /F2 10 0 R', $content);
        $this->assertStringContainsString('(Ligne 100)', $content);
    }
}
