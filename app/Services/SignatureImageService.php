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

    public function overlayOnTop(string $basePngPath, string $overlayPngPath): string
    {
        if (extension_loaded('imagick')) {
            return $this->overlayWithImagick($basePngPath, $overlayPngPath);
        }

        if (function_exists('imagecreatefrompng')) {
            return $this->overlayWithGd($basePngPath, $overlayPngPath);
        }

        throw new RuntimeException('No supported image extension found for signature overlay.');
    }

    private function processWithImagick(string $sourcePath): string
    {
        $image = new \Imagick($sourcePath);
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
        $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $corner = $image->getImagePixelColor(max(0, $width - 1), 0);
        $image->transparentPaintImage($corner, 0.0, 0.18 * \Imagick::getQuantum(), false);

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

                $distance = abs($r - $bgR) + abs($g - $bgG) + abs($b - $bgB);
                if ($distance < 72) {
                    imagesetpixel($processed, $x, $y, $transparent);
                    continue;
                }

                $color = imagecolorallocatealpha($processed, $r, $g, $b, 0);
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

    private function overlayWithImagick(string $basePngPath, string $overlayPngPath): string
    {
        $base = new \Imagick($basePngPath);
        $overlay = new \Imagick($overlayPngPath);

        $base->setImageFormat('png');
        $overlay->setImageFormat('png');
        $base->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
        $overlay->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);

        $baseW = max(1, $base->getImageWidth());
        $baseH = max(1, $base->getImageHeight());
        $overlayW = max(1, $overlay->getImageWidth());
        $overlayH = max(1, $overlay->getImageHeight());
        $ratio = min($baseW / $overlayW, $baseH / $overlayH);
        $targetW = max(1, (int) round($overlayW * $ratio));
        $targetH = max(1, (int) round($overlayH * $ratio));
        $overlay->resizeImage($targetW, $targetH, \Imagick::FILTER_LANCZOS, 1.0);

        $x = (int) floor(($baseW - $targetW) / 2);
        $y = (int) floor(($baseH - $targetH) / 2);
        $base->compositeImage($overlay, \Imagick::COMPOSITE_OVER, $x, $y);

        $targetPath = $this->tempPath('signature-overlay-', '.png');
        if (! $base->writeImage($targetPath)) {
            throw new RuntimeException('Unable to write composited signature image.');
        }

        $base->clear();
        $base->destroy();
        $overlay->clear();
        $overlay->destroy();

        return $targetPath;
    }

    private function overlayWithGd(string $basePngPath, string $overlayPngPath): string
    {
        $base = imagecreatefrompng($basePngPath);
        $overlay = imagecreatefrompng($overlayPngPath);
        if (! ($base instanceof \GdImage) || ! ($overlay instanceof \GdImage)) {
            throw new RuntimeException('Unable to open PNG images for signature overlay.');
        }

        imagealphablending($base, true);
        imagesavealpha($base, true);
        imagealphablending($overlay, true);
        imagesavealpha($overlay, true);

        $baseW = imagesx($base);
        $baseH = imagesy($base);
        $overlayW = imagesx($overlay);
        $overlayH = imagesy($overlay);
        $ratio = min($baseW / max(1, $overlayW), $baseH / max(1, $overlayH));
        $targetW = max(1, (int) round($overlayW * $ratio));
        $targetH = max(1, (int) round($overlayH * $ratio));
        $resizedOverlay = imagescale($overlay, $targetW, $targetH, IMG_BICUBIC_FIXED);
        if (! ($resizedOverlay instanceof \GdImage)) {
            imagedestroy($base);
            imagedestroy($overlay);
            throw new RuntimeException('Unable to resize overlay signature image.');
        }

        $x = (int) floor(($baseW - $targetW) / 2);
        $y = (int) floor(($baseH - $targetH) / 2);
        imagecopy($base, $resizedOverlay, $x, $y, 0, 0, $targetW, $targetH);

        $targetPath = $this->tempPath('signature-overlay-', '.png');
        if (! imagepng($base, $targetPath)) {
            imagedestroy($base);
            imagedestroy($overlay);
            imagedestroy($resizedOverlay);
            throw new RuntimeException('Unable to write composited signature image.');
        }

        imagedestroy($base);
        imagedestroy($overlay);
        imagedestroy($resizedOverlay);

        return $targetPath;
    }
}
