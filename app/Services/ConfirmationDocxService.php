<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class ConfirmationDocxService
{
    private const CONTENT_XML_ENTRY_PATTERN = '/^word\/(?:document|header\d+|footer\d+)\.xml$/i';

    private const PLACEHOLDER_PATTERN = '/\{([A-Za-z][A-Za-z0-9_.-]*)\}/';

    /**
     * Extract unique placeholders like {client_name} from a DOCX template.
     *
     * @return list<string>
     */
    public function extractPlaceholders(string $templatePath): array
    {
        $placeholders = [];

        foreach ($this->readTemplateXmlEntries($templatePath) as $xml) {
            if (! preg_match_all(self::PLACEHOLDER_PATTERN, $xml, $matches)) {
                continue;
            }

            foreach ($matches[1] as $placeholder) {
                $placeholders[$placeholder] = true;
            }
        }

        $names = array_keys($placeholders);

        natcasesort($names);

        return array_values($names);
    }

    /**
     * Render a DOCX template into a new DOCX file by replacing placeholders.
     *
     * @param  array<string, string|null>  $replacements
     */
    public function render(string $templatePath, string $outputPath, array $replacements): void
    {
        if (! is_file($templatePath)) {
            throw new RuntimeException('The DOCX template is no longer available.');
        }

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0777, true);
        }

        if (! @copy($templatePath, $outputPath)) {
            throw new RuntimeException('The templated DOCX could not be prepared.');
        }

        $archive = new ZipArchive;
        $result = $archive->open($outputPath);

        if ($result !== true) {
            @unlink($outputPath);

            throw new RuntimeException('The templated DOCX could not be opened for writing.');
        }

        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entryName = $archive->getNameIndex($index);

                if (! is_string($entryName) || ! preg_match(self::CONTENT_XML_ENTRY_PATTERN, $entryName)) {
                    continue;
                }

                $xml = $archive->getFromName($entryName);

                if (! is_string($xml)) {
                    continue;
                }

                $updatedXml = preg_replace_callback(
                    self::PLACEHOLDER_PATTERN,
                    function (array $matches) use ($replacements): string {
                        $placeholder = $matches[1];

                        if (! array_key_exists($placeholder, $replacements)) {
                            return $matches[0];
                        }

                        return $this->escapeReplacementText($replacements[$placeholder]);
                    },
                    $xml,
                );

                if ($updatedXml === null) {
                    throw new RuntimeException('The DOCX template placeholders could not be processed.');
                }

                if ($updatedXml === $xml) {
                    continue;
                }

                if (! $archive->addFromString($entryName, $updatedXml)) {
                    throw new RuntimeException('The templated DOCX could not be updated.');
                }
            }
        } catch (\Throwable $exception) {
            $archive->close();
            @unlink($outputPath);

            throw new RuntimeException(
                'The templated DOCX could not be created.',
                previous: $exception,
            );
        }

        $archive->close();
    }

    /**
     * Render a DOCX template directly into a PDF file.
     *
     * @param  array<string, string|null>  $replacements
     */
    public function renderPdf(string $templatePath, string $outputPath, array $replacements): void
    {
        $temporaryDocxPath = storage_path('app/tmp/doc-merge-template-'.Str::uuid().'.docx');

        try {
            $this->render($templatePath, $temporaryDocxPath, $replacements);
            $this->convertToPdf($temporaryDocxPath, $outputPath);
        } finally {
            if (is_file($temporaryDocxPath)) {
                @unlink($temporaryDocxPath);
            }
        }
    }

    public function convertToPdf(string $docxPath, string $outputPath): void
    {
        if (! is_file($docxPath)) {
            throw new RuntimeException('The templated DOCX is no longer available for PDF conversion.');
        }

        $outputDirectory = dirname($outputPath);

        if (! is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0777, true);
        }

        $expectedOutputPath = $outputDirectory.DIRECTORY_SEPARATOR.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';
        $profileDirectory = storage_path('app/tmp/libreoffice-profile-'.Str::uuid());

        if (is_file($expectedOutputPath)) {
            @unlink($expectedOutputPath);
        }

        if (! is_dir($profileDirectory)) {
            mkdir($profileDirectory, 0777, true);
        }

        $process = new Process([
            'soffice',
            '--headless',
            '--nologo',
            '--nodefault',
            '--nofirststartwizard',
            '--nolockcheck',
            '--convert-to',
            'pdf:writer_pdf_Export',
            '--outdir',
            $outputDirectory,
            '-env:UserInstallation='.$this->libreOfficeProfileUrl($profileDirectory),
            $docxPath,
        ]);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'LibreOffice is required to convert the receipt template to PDF.',
                previous: $exception,
            );
        } finally {
            File::deleteDirectory($profileDirectory);
        }

        if (! $process->isSuccessful()) {
            $details = trim($process->getErrorOutput());

            if ($details === '') {
                $details = trim($process->getOutput());
            }

            if ($details !== '') {
                throw new RuntimeException("The receipt PDF could not be created. {$details}");
            }

            throw new RuntimeException('The receipt PDF could not be created.');
        }

        if (! is_file($expectedOutputPath)) {
            throw new RuntimeException('The receipt PDF could not be created.');
        }

        if ($expectedOutputPath === $outputPath) {
            return;
        }

        if (is_file($outputPath) && ! @unlink($outputPath)) {
            throw new RuntimeException('The receipt PDF output could not be prepared.');
        }

        if (! @rename($expectedOutputPath, $outputPath)) {
            if (! @copy($expectedOutputPath, $outputPath)) {
                @unlink($expectedOutputPath);

                throw new RuntimeException('The receipt PDF output could not be prepared.');
            }

            @unlink($expectedOutputPath);
        }
    }

    /**
     * @return list<string>
     */
    private function readTemplateXmlEntries(string $templatePath): array
    {
        if (! is_file($templatePath)) {
            throw new RuntimeException('The DOCX template is no longer available.');
        }

        $archive = new ZipArchive;
        $result = $archive->open($templatePath);

        if ($result !== true) {
            throw new RuntimeException('The uploaded DOCX template could not be read.');
        }

        try {
            $entries = [];

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entryName = $archive->getNameIndex($index);

                if (! is_string($entryName) || ! preg_match(self::CONTENT_XML_ENTRY_PATTERN, $entryName)) {
                    continue;
                }

                $xml = $archive->getFromName($entryName);

                if (is_string($xml)) {
                    $entries[] = $xml;
                }
            }

            if ($entries === []) {
                throw new RuntimeException('The uploaded DOCX template does not contain Word document content.');
            }

            return $entries;
        } finally {
            $archive->close();
        }
    }

    private function escapeReplacementText(?string $value): string
    {
        $normalized = preg_replace('/\R/u', ' ', (string) $value) ?? '';

        return htmlspecialchars($normalized, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function libreOfficeProfileUrl(string $directory): string
    {
        $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $directory);

        if (! str_starts_with($normalized, '/')) {
            $normalized = '/'.$normalized;
        }

        return 'file://'.$normalized;
    }
}
