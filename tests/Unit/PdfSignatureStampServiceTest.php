<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PdfSignatureStampService;
use Tests\TestCase;

class PdfSignatureStampServiceTest extends TestCase
{
    public function test_it_normalizes_signature_png_before_stamping_when_input_is_16_bit(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required for this test.');
        }

        $service = app(PdfSignatureStampService::class);
        $pdfPath = $this->makePdfPath();
        $signaturePath = $this->make16BitPngPath();

        try {
            $this->assertSame(16, $this->pngBitDepth($signaturePath));

            $service->stampFileWithPageLayouts($pdfPath, $signaturePath, [
                1 => [
                    'anchor' => 'bottom_right',
                    'offset_x' => -10.0,
                    'offset_y' => -10.0,
                    'width' => 40.0,
                    'height' => 16.0,
                ],
            ]);

            $this->assertSame(8, $this->pngBitDepth($signaturePath));
            $this->assertFileExists($pdfPath);
            $this->assertGreaterThan(0, filesize($pdfPath));
        } finally {
            @unlink($pdfPath);
            @unlink($signaturePath);
        }
    }

    private function makePdfPath(): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'stamp-pdf-');
        $pdfPath = $temporaryPath.'.pdf';
        @rename($temporaryPath, $pdfPath);

        $pdf = new \FPDF;
        $pdf->AddPage();
        $pdf->Output('F', $pdfPath);

        return $pdfPath;
    }

    private function make16BitPngPath(): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'stamp-sig16-');
        $pngPath = $temporaryPath.'.png';
        @rename($temporaryPath, $pngPath);

        $image = new \Imagick;
        $image->newImage(220, 90, new \ImagickPixel('transparent'), 'png');
        $image->setImageDepth(16);
        $draw = new \ImagickDraw;
        $draw->setFillColor('black');
        $draw->line(15, 65, 205, 30);
        $image->drawImage($draw);
        $image->setImageFormat('png');
        $image->writeImage($pngPath);
        $image->clear();
        $image->destroy();

        return $pngPath;
    }

    private function pngBitDepth(string $pngPath): ?int
    {
        $header = @file_get_contents($pngPath, false, null, 0, 33);
        if (! is_string($header) || strlen($header) < 33) {
            return null;
        }

        if (substr($header, 0, 8) !== "\x89PNG\x0D\x0A\x1A\x0A") {
            return null;
        }

        return ord($header[24]);
    }
}

