<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SignatureImageService;
use Tests\TestCase;

class SignatureImageServiceTest extends TestCase
{
    public function test_process_to_transparent_png_with_imagick_outputs_8_bit_png(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required for this test.');
        }

        $service = app(SignatureImageService::class);
        $sourcePath = $this->make16BitPngPath();

        try {
            $processedPath = $service->processToTransparentPng($sourcePath);

            try {
                $this->assertSame(8, $this->pngBitDepth($processedPath));
                $this->assertSame('image/png', (string) mime_content_type($processedPath));
                $this->assertGreaterThan(0, filesize($processedPath));
            } finally {
                @unlink($processedPath);
            }
        } finally {
            @unlink($sourcePath);
        }
    }

    public function test_normalize_png_for_fpdf_converts_16_bit_png_to_8_bit(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required for this test.');
        }

        $service = app(SignatureImageService::class);
        $sourcePath = $this->make16BitPngPath();

        try {
            $this->assertSame(16, $this->pngBitDepth($sourcePath));

            $normalizedPath = $service->normalizePngForFpdf($sourcePath);

            $this->assertSame($sourcePath, $normalizedPath);
            $this->assertSame(8, $this->pngBitDepth($sourcePath));
        } finally {
            @unlink($sourcePath);
        }
    }

    public function test_normalize_png_for_fpdf_is_noop_for_existing_8_bit_png(): void
    {
        $service = app(SignatureImageService::class);
        $sourcePath = $this->make8BitPngPath();

        try {
            $before = md5_file($sourcePath);
            $this->assertSame(8, $this->pngBitDepth($sourcePath));

            $normalizedPath = $service->normalizePngForFpdf($sourcePath);

            $this->assertSame($sourcePath, $normalizedPath);
            $this->assertSame(8, $this->pngBitDepth($sourcePath));
            $this->assertSame($before, md5_file($sourcePath));
        } finally {
            @unlink($sourcePath);
        }
    }

    private function make16BitPngPath(): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'sig16-');
        $pngPath = $temporaryPath.'.png';
        @rename($temporaryPath, $pngPath);

        $image = new \Imagick;
        $image->newImage(240, 100, new \ImagickPixel('white'), 'png');
        $image->setImageDepth(16);
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);

        $draw = new \ImagickDraw;
        $draw->setFillColor('black');
        $draw->setStrokeColor('black');
        $draw->setStrokeWidth(2);
        $draw->line(20, 70, 220, 30);
        $image->drawImage($draw);
        $image->setImageFormat('png');
        $image->writeImage($pngPath);
        $image->clear();
        $image->destroy();

        return $pngPath;
    }

    private function make8BitPngPath(): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'sig8-');
        $pngPath = $temporaryPath.'.png';
        @rename($temporaryPath, $pngPath);

        $image = imagecreatetruecolor(80, 30);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, 80, 30, $transparent);
        $black = imagecolorallocatealpha($image, 0, 0, 0, 0);
        imageline($image, 5, 20, 75, 8, $black);
        imagepng($image, $pngPath);
        imagedestroy($image);

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

