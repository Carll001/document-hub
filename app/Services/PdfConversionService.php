<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class PdfConversionService
{
    public function convertDocxToPdf(string $docxPath): string
    {
        $directory = dirname($docxPath);
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

        try {
            foreach ($binaries as $binary) {
                $process = Process::timeout(120)->run([
                    $binary,
                    '--headless',
                    '--norestore',
                    '--nofirststartwizard',
                    "-env:UserInstallation=file://{$userProfileDir}",
                    '--convert-to',
                    'pdf:writer_pdf_Export',
                    '--outdir',
                    $directory,
                    $docxPath,
                ]);

                if ($process->successful()) {
                    $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath);
                    if ($pdfPath !== null && file_exists($pdfPath)) {
                        return $pdfPath;
                    }

                    throw new RuntimeException('PDF conversion failed: output file was not generated.');
                }

                $errors[] = sprintf(
                    '[%s] %s',
                    $binary,
                    trim($process->errorOutput() ?: $process->output())
                );
            }
        } finally {
            if (is_dir($userProfileDir)) {
                app(\Illuminate\Filesystem\Filesystem::class)->deleteDirectory($userProfileDir);
            }
        }

        throw new RuntimeException(
            'PDF conversion failed. Install LibreOffice and ensure the binary is available, '.
            'or set LIBREOFFICE_BINARY in .env. Attempts: '.implode(' | ', $errors)
        );
    }
}
