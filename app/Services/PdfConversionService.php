<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class PdfConversionService
{
    public function convertDocxToPdf(string $docxPath): string
    {
        $sourcePath = realpath($docxPath) ?: $docxPath;

        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException(sprintf(
                'PDF conversion failed: source DOCX file is missing or unreadable. Path: %s',
                $docxPath
            ));
        }

        $directory = dirname($sourcePath);
        $workingDirectory = $this->createWorkingDirectory();
        $workingDocxPath = $workingDirectory.'/source.docx';
        $workingPdfPath = $workingDirectory.'/source.pdf';

        if (! @copy($sourcePath, $workingDocxPath)) {
            throw new RuntimeException(sprintf(
                'PDF conversion failed: unable to copy DOCX to working directory. Source: %s',
                $sourcePath
            ));
        }

        $configuredBinary = (string) config('services.document_generator.libreoffice_binary', 'libreoffice');
        $binaries = array_values(array_unique(array_filter([
            trim($configuredBinary),
            'libreoffice',
            'soffice',
        ], static fn (string $binary): bool => $binary !== '')));

        $errors = [];

        // Isolated profile per invocation prevents lock contention between
        // concurrent or back-to-back jobs when a prior LibreOffice run left
        // orphaned child processes holding the default profile lock.
        $userProfileDir = sys_get_temp_dir().'/libreoffice-profile-'.uniqid('', true);
        $cacheDir = $userProfileDir.'/cache';
        @mkdir($cacheDir, 0777, true);

        try {
            foreach ($binaries as $binary) {
                $process = Process::timeout(120)
                    ->env([
                        'HOME' => $userProfileDir,
                        'XDG_CACHE_HOME' => $cacheDir,
                        'FONTCONFIG_PATH' => '/etc/fonts',
                        'FONTCONFIG_FILE' => '/etc/fonts/fonts.conf',
                    ])
                    ->run([
                    $binary,
                    '--headless',
                    '--norestore',
                    '--nofirststartwizard',
                    "-env:UserInstallation=file://{$userProfileDir}",
                    '--convert-to',
                    'pdf:writer_pdf_Export',
                    '--outdir',
                    $workingDirectory,
                    $workingDocxPath,
                ]);

                if ($process->successful()) {
                    $pdfPath = $this->resolveGeneratedPdfPath($workingDocxPath, $workingDirectory);
                    if ($pdfPath !== null) {
                        $outputPath = preg_replace('/\.docx$/i', '.pdf', $sourcePath);
                        if (! is_string($outputPath) || $outputPath === '') {
                            throw new RuntimeException('PDF conversion failed: could not resolve output path.');
                        }

                        if (! @copy($pdfPath, $outputPath)) {
                            throw new RuntimeException(sprintf(
                                'PDF conversion failed: unable to save generated PDF to output path. Output: %s',
                                $outputPath
                            ));
                        }

                        return $outputPath;
                    }

                    throw new RuntimeException(sprintf(
                        'PDF conversion failed: output file was not generated. Binary: %s. Command output: %s',
                        $binary,
                        trim($process->output() ?: $process->errorOutput())
                    ));
                }

                $errors[] = sprintf(
                    '[%s] %s',
                    $binary,
                    trim($process->errorOutput() ?: $process->output())
                );
            }
        } finally {
            // Kill any orphaned soffice processes tied to this profile to prevent
            // them from accumulating and exhausting server memory across jobs.
            Process::run(['pkill', '-f', $userProfileDir]);

            if (is_dir($workingDirectory)) {
                app(\Illuminate\Filesystem\Filesystem::class)->deleteDirectory($workingDirectory);
            }

            if (is_dir($userProfileDir)) {
                app(\Illuminate\Filesystem\Filesystem::class)->deleteDirectory($userProfileDir);
            }
        }

        throw new RuntimeException(
            'PDF conversion failed. Install LibreOffice and ensure the binary is available, '.
            'or set LIBREOFFICE_BINARY in .env. Attempts: '.implode(' | ', $errors)
        );
    }

    private function resolveGeneratedPdfPath(string $docxPath, string $directory): ?string
    {
        $expected = preg_replace('/\.docx$/i', '.pdf', $docxPath);
        if (is_string($expected) && is_file($expected)) {
            return $expected;
        }

        // Some environments write the file with a short delay after process exit.
        $waitUntil = microtime(true) + 2.0;
        while (microtime(true) < $waitUntil) {
            usleep(200_000);
            if (is_string($expected) && is_file($expected)) {
                return $expected;
            }
        }

        $docxBase = pathinfo($docxPath, PATHINFO_FILENAME);
        if ($docxBase === '') {
            return null;
        }

        $matches = glob($directory.'/'.$docxBase.'.[Pp][Dd][Ff]') ?: [];
        foreach ($matches as $match) {
            if (is_file($match)) {
                return $match;
            }
        }

        return null;
    }

    private function createWorkingDirectory(): string
    {
        $path = sys_get_temp_dir().'/libreoffice-work-'.uniqid('', true);
        if (! @mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new RuntimeException('PDF conversion failed: unable to create working directory.');
        }

        return $path;
    }
}
