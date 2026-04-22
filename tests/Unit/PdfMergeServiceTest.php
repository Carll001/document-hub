<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\PdfMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PdfMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_persist_partial_output_when_a_merge_fails()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $service = app(PdfMergeService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'One or more PDFs could not be merged. Unsupported, encrypted, or malformed PDF files are not supported by the current merge engine.',
        );

        try {
            $service->merge($user, [
                $this->makeUploadedPdf('valid.pdf'),
                $this->makeBrokenPdf('broken.pdf'),
            ]);
        } finally {
            $this->assertDatabaseCount('merged_pdfs', 0);
            $this->assertSame([], Storage::disk('s3')->allFiles('doc-merge/'.$user->id));
        }
    }

    private function makeUploadedPdf(string $fileName): UploadedFile
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-valid-');
        $pdfPath = $temporaryPath.'.pdf';
        rename($temporaryPath, $pdfPath);

        $pdf = new \FPDF;
        $pdf->AddPage();
        $pdf->Output('F', $pdfPath);

        return new UploadedFile(
            $pdfPath,
            $fileName,
            'application/pdf',
            null,
            true,
        );
    }

    private function makeBrokenPdf(string $fileName): UploadedFile
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'doc-merge-broken-');
        $pdfPath = $temporaryPath.'.pdf';
        rename($temporaryPath, $pdfPath);

        file_put_contents($pdfPath, 'this is not a valid pdf');

        return new UploadedFile(
            $pdfPath,
            $fileName,
            'application/pdf',
            null,
            true,
        );
    }
}
