<?php

namespace App\Services;

use RuntimeException;

class SignatureImageService
{
    public function processToTransparentPng(string $sourcePath): string
    {
        if (extension_loaded('imagick')) {
            return $this->processWithImagick($sourcePath);
        }

        if (function_exists('imagecreatefrompng')) {
            return $this->processWithGd($sourcePath);
        }

        throw new RuntimeException('No supported image extension found for signature processing.');
    }

    private function processWithImagick(string $sourcePath): string
    {
        $image = new \Imagick($sourcePath);
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
        $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_MERGE);
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $corner = $image->getImagePixelColor(max(0, $width - 1), 0);
        // Remove common white-paper backgrounds, then also remove corner-matched background.
        $image->transparentPaintImage(new \ImagickPixel('white'), 0.0, 0.22 * \Imagick::getQuantum(), false);
        $image->transparentPaintImage($corner, 0.0, 0.20 * \Imagick::getQuantum(), false);

        $image->setImageFormat('png');
        $image->trimImage(0.0);
        $image->setImagePage(0, 0, 0, 0);

        $targetPath = $this->tempPath('signature-processed-', '.png');
        if (! $image->writeImage($targetPath)) {
            throw new RuntimeException('Unable to write processed signature image.');
        }

        $image->clear();
        $image->destroy();

        return $targetPath;
    }

    private function processWithGd(string $sourcePath): string
    {
        $mime = (string) mime_content_type($sourcePath);
        $image = match ($mime) {
            'image/png' => imagecreatefrompng($sourcePath),
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if (! is_resource($image) && ! ($image instanceof \GdImage)) {
            throw new RuntimeException('Unsupported signature image format.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $processed = imagecreatetruecolor($width, $height);
        if (! is_resource($processed) && ! ($processed instanceof \GdImage)) {
            throw new RuntimeException('Unable to process signature image.');
        }

        imagealphablending($processed, false);
        imagesavealpha($processed, true);
        $transparent = imagecolorallocatealpha($processed, 0, 0, 0, 127);
        imagefilledrectangle($processed, 0, 0, $width, $height, $transparent);

        $bg = imagecolorat($image, max(0, $width - 1), 0);
        $bgR = ($bg >> 16) & 0xFF;
        $bgG = ($bg >> 8) & 0xFF;
        $bgB = $bg & 0xFF;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $a = ($rgb >> 24) & 0x7F;

                $distance = abs($r - $bgR) + abs($g - $bgG) + abs($b - $bgB);
                $isNearWhite = $r >= 235 && $g >= 235 && $b >= 235;
                $isBackgroundLike = $distance < 84 || $isNearWhite;

                if ($isBackgroundLike || $a >= 120) {
                    imagesetpixel($processed, $x, $y, $transparent);
                    continue;
                }

                $color = imagecolorallocatealpha($processed, $r, $g, $b, $a);
                imagesetpixel($processed, $x, $y, $color);
            }
        }

        $targetPath = $this->tempPath('signature-processed-', '.png');
        if (! imagepng($processed, $targetPath)) {
            throw new RuntimeException('Unable to write processed signature image.');
        }

        imagedestroy($image);
        imagedestroy($processed);

        return $targetPath;
    }

    private function tempPath(string $prefix, string $extension): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate temporary file path.');
        }

        @unlink($tmp);

        return $tmp.$extension;
    }
}
