<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BulkZipMergeService;
use Tests\TestCase;
use ZipArchive;

class BulkZipMergeServiceTest extends TestCase
{
    public function test_it_inspects_zip_page_folders_and_sorts_them_by_trailing_number()
    {
        $service = app(BulkZipMergeService::class);
        $zipPath = $this->makeZipPath([
            'wrapper/PAGE 10/report.pdf' => $this->makePdfContents(),
            'wrapper/PAGE 2/report.pdf' => $this->makePdfContents(),
            'wrapper/PAGE 1/report.pdf' => $this->makePdfContents(),
        ]);

        try {
            $inspection = $service->inspectArchive($zipPath, 'pages.zip');

            $this->assertSame(
                ['PAGE 1', 'PAGE 2', 'PAGE 10'],
                array_map(
                    static fn (array $pageFolder): string => $pageFolder['name'],
                    $inspection['pageFolders'],
                ),
            );
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_it_builds_case_insensitive_document_groups_using_the_earliest_filename()
    {
        $service = app(BulkZipMergeService::class);
        $zipPath = $this->makeZipPath([
            'PAGE 2/invoice.pdf' => $this->makePdfContents(),
            'PAGE 1/Invoice.pdf' => $this->makePdfContents(),
        ]);

        try {
            $inspection = $service->inspectArchive($zipPath, 'pages.zip');
            $documentGroups = $service->planDocumentGroups(
                $inspection['pageFolders'],
                'Client-',
            );

            $this->assertCount(1, $documentGroups);
            $this->assertSame('Invoice.pdf', $documentGroups[0]['groupLabel']);
            $this->assertSame(
                'Client-Invoice.pdf',
                $documentGroups[0]['outputFileName'],
            );
            $this->assertSame(
                ['Invoice.pdf', 'invoice.pdf'],
                array_map(
                    static fn (array $source): string => $source['displayName'],
                    $documentGroups[0]['sources'],
                ),
            );
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_it_groups_numbered_page_filenames_by_their_base_name()
    {
        $service = app(BulkZipMergeService::class);
        $zipPath = $this->makeZipPath([
            'PAGE 2/invoice 2.pdf' => $this->makePdfContents(),
            'PAGE 1/Invoice 1.pdf' => $this->makePdfContents(),
        ]);

        try {
            $inspection = $service->inspectArchive($zipPath, 'pages.zip');
            $documentGroups = $service->planDocumentGroups(
                $inspection['pageFolders'],
                'Client-',
            );

            $this->assertCount(1, $documentGroups);
            $this->assertSame('Invoice.pdf', $documentGroups[0]['groupLabel']);
            $this->assertSame(
                'Client-Invoice.pdf',
                $documentGroups[0]['outputFileName'],
            );
            $this->assertSame(
                ['Invoice 1.pdf', 'invoice 2.pdf'],
                array_map(
                    static fn (array $source): string => $source['displayName'],
                    $documentGroups[0]['sources'],
                ),
            );
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_it_marks_missing_page_folders_in_the_document_plan()
    {
        $service = app(BulkZipMergeService::class);
        $zipPath = $this->makeZipPath([
            'PAGE 1/invoice.pdf' => $this->makePdfContents(),
            'PAGE 1/report.pdf' => $this->makePdfContents(),
            'PAGE 2/report.pdf' => $this->makePdfContents(),
        ]);

        try {
            $inspection = $service->inspectArchive($zipPath, 'pages.zip');
            $documentGroups = $service->planDocumentGroups(
                $inspection['pageFolders'],
            );
            $invoiceGroup = collect($documentGroups)
                ->firstWhere('groupLabel', 'invoice.pdf');

            $this->assertNotNull($invoiceGroup);
            $this->assertSame(
                ['PAGE 2'],
                $invoiceGroup['missingFolderNames'],
            );
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function makeZipPath(array $entries): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-zip-');
        $zipPath = $temporaryPath.'.zip';
        rename($temporaryPath, $zipPath);

        $zip = new ZipArchive;
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            $this->fail('The ZIP archive could not be created for the test.');
        }

        foreach ($entries as $path => $contents) {
            $zip->addFromString($path, $contents);
        }

        $zip->close();

        return $zipPath;
    }

    private function makePdfContents(): string
    {
        $pdf = new \FPDF;
        $pdf->AddPage();

        return $pdf->Output('S');
    }
}
