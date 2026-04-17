<?php

namespace App\Services;

use RuntimeException;
use setasign\Fpdi\Fpdi;

class PdfSignatureStampService
{
    /**
     * @param  array<int, array{anchor: string, offset_x: float, offset_y: float, width: float, height: float}>  $pageLayouts
     */
    public function stampFileWithPageLayouts(
        string $pdfPath,
        string $signatureImagePath,
        array $pageLayouts,
    ): void {
        if (! file_exists($pdfPath)) {
            throw new RuntimeException('PDF file does not exist.');
        }

        if (! file_exists($signatureImagePath)) {
            throw new RuntimeException('Signature image does not exist.');
        }

        $pdf = new Fpdi;
        $pageCount = $pdf->setSourceFile($pdfPath);
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            $layout = $pageLayouts[$pageNo] ?? null;
            if (! is_array($layout)) {
                continue;
            }

            [$x, $y] = $this->resolveCoordinates(
                $size['width'],
                $size['height'],
                (string) ($layout['anchor'] ?? 'bottom_right'),
                (float) ($layout['offset_x'] ?? 0.0),
                (float) ($layout['offset_y'] ?? 0.0),
                (float) ($layout['width'] ?? 40.0),
                (float) ($layout['height'] ?? 16.0),
            );

            $pdf->Image(
                $signatureImagePath,
                $x,
                $y,
                (float) ($layout['width'] ?? 40.0),
                (float) ($layout['height'] ?? 16.0),
                'PNG',
            );
        }

        $tempOutput = tempnam(sys_get_temp_dir(), 'stamped-pdf-');
        if ($tempOutput === false) {
            throw new RuntimeException('Unable to create temporary stamped PDF path.');
        }

        $pdf->Output('F', $tempOutput);
        if (! @rename($tempOutput, $pdfPath)) {
            @unlink($tempOutput);
            throw new RuntimeException('Unable to save stamped PDF output.');
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function resolveCoordinates(
        float $pageWidth,
        float $pageHeight,
        string $anchor,
        float $offsetX,
        float $offsetY,
        float $signatureWidth,
        float $signatureHeight,
    ): array {
        $x = 0.0;
        $y = 0.0;

        switch ($anchor) {
            case 'top_right':
                $x = $pageWidth - $signatureWidth;
                break;
            case 'bottom_left':
                $y = $pageHeight - $signatureHeight;
                break;
            case 'bottom_right':
                $x = $pageWidth - $signatureWidth;
                $y = $pageHeight - $signatureHeight;
                break;
            case 'center':
                $x = ($pageWidth - $signatureWidth) / 2;
                $y = ($pageHeight - $signatureHeight) / 2;
                break;
            case 'top_left':
            default:
                break;
        }

        $x += $offsetX;
        $y += $offsetY;

        return [
            max(0.0, min($x, max(0.0, $pageWidth - $signatureWidth))),
            max(0.0, min($y, max(0.0, $pageHeight - $signatureHeight))),
        ];
    }
}
