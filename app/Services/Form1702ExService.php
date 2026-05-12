<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Form1702ExBatchRow;
use App\Support\DocumentStorage;
use App\Support\Form1702ExFieldValueFormatter;
use App\Support\FormPdfFpdi;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class Form1702ExService
{
    public const FORM_KEY = '1702-ex';
    private const DEFAULT_FOOTER_SOURCE_PATH = 'file:///C:/Users/Driane/AppData/Local/Temp/%7B6700D4D7-8594-4158-B093-357E...';
    private const PDF_FONT_FAMILY = 'ArialNarrow';
    private const PDF_FONT_STYLE = '';
    private const PDF_FONT_DEFINITION = 'ARIALN.php';
    private const PDF_MARKER_FILL_RED = 110;
    private const PDF_MARKER_FILL_GREEN = 110;
    private const PDF_MARKER_FILL_BLUE = 110;

    public function __construct(
        private readonly Form1702ExFieldValueFormatter $fieldValueFormatter,
        private readonly SignatureImageService $signatureImageService,
    ) {
    }

    /**
     * @return array{page: array{width: float, height: float}, fields: list<array<string, mixed>>}
     */
    public function fieldSchema(): array
    {
        return $this->fieldSchemaFromPath(
            resource_path('forms/templates/1702-ex/page1.schema.json'),
            'The 1702-EX page schema is invalid.',
        );
    }

    /**
     * @return array{page: array{width: float, height: float}, fields: list<array<string, mixed>>}
     */
    public function page2FieldSchema(): array
    {
        return $this->fieldSchemaFromPath(
            resource_path('forms/templates/1702-ex/page2.schema.json'),
            'The 1702-EX page 2 schema is invalid.',
        );
    }

    /**
     * @return array{page: array{width: float, height: float}, fields: list<array<string, mixed>>}
     */
    public function page3FieldSchema(): array
    {
        return $this->fieldSchemaFromPath(
            resource_path('forms/templates/1702-ex/page3.schema.json'),
            'The 1702-EX page 3 schema is invalid.',
        );
    }

    /**
     * @return array{page: array{width: float, height: float}, fields: list<array<string, mixed>>}
     */
    public function receiptFieldSchema(): array
    {
        return $this->fieldSchemaFromPath(
            resource_path('forms/templates/1702-ex/receipt.schema.json'),
            'The 1702-EX receipt schema is invalid.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function mockPayload(): array
    {
        return $this->payloadFromPath(
            resource_path('forms/templates/1702-ex/mock-payload.json'),
            'The 1702-EX mock payload is invalid.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function page2MockPayload(): array
    {
        return $this->payloadFromPath(
            resource_path('forms/templates/1702-ex/page2-mock-payload.json'),
            'The 1702-EX page 2 mock payload is invalid.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function page3MockPayload(): array
    {
        return $this->payloadFromPath(
            resource_path('forms/templates/1702-ex/page3-mock-payload.json'),
            'The 1702-EX page 3 mock payload is invalid.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function receiptMockPayload(): array
    {
        return $this->payloadFromPath(
            resource_path('forms/templates/1702-ex/receipt-mock-payload.json'),
            'The 1702-EX receipt mock payload is invalid.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function batchPayloadDefaults(?Carbon $date = null): array
    {
        $payload = $this->blankPayloadFromTemplates([
            $this->mockPayload(),
            $this->page2MockPayload(),
            $this->page3MockPayload(),
        ]);

        $payload['footer_source_path'] = $this->defaultFooterSourcePath();
        $payload['footer_printed_date'] = $this->defaultFooterPrintedDate($date);
        $payload['file_name_prefix'] = '';

        return $payload;
    }

    public function defaultFooterSourcePath(): string
    {
        return self::DEFAULT_FOOTER_SOURCE_PATH;
    }

    public function defaultFooterPrintedDate(?Carbon $date = null): string
    {
        return ($date ?? now())->format('d/m/Y');
    }

    public function resolveFooterSourcePath(?string $sourcePath): string
    {
        $trimmed = trim((string) ($sourcePath ?? ''));

        return $trimmed !== ''
            ? preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed
            : $this->defaultFooterSourcePath();
    }

    public function resolveFooterPrintedDate(?string $printedDate, ?Carbon $date = null): string
    {
        $trimmed = trim((string) ($printedDate ?? ''));

        return $trimmed !== ''
            ? preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed
            : $this->defaultFooterPrintedDate($date);
    }

    public function resolveFileNamePrefix(?string $prefix): string
    {
        $trimmed = trim((string) ($prefix ?? ''));

        return $trimmed !== ''
            ? preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed
            : '';
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function previewFields(array $fields, array $payload): array
    {
        return array_values(array_map(function (array $field) use ($payload): array {
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'checkbox') {
                $field['previewChecked'] = $this->fieldValueFormatter->isChecked($field, $payload);

                return $field;
            }

            if ($type === 'split-box') {
                $field['previewCharacters'] = $this->fieldValueFormatter->splitCharacters($field, $payload);

                return $field;
            }

            if ($type === 'image') {
                $field['previewText'] = '[SIGNATURE]';

                return $field;
            }

            $field['previewText'] = $this->fieldValueFormatter->formatText($field, $payload);

            return $field;
        }, $fields));
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function receiptInputFields(): array
    {
        $fields = [];
        $seen = [];

        foreach ($this->receiptFieldSchema()['fields'] as $field) {
            $key = (string) ($field['key'] ?? '');

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $label = trim((string) ($field['label'] ?? ''));

            $fields[] = [
                'key' => $key,
                'label' => $label !== ''
                    ? $label
                    : $this->displayLabelFromKey($key),
            ];
        }

        return $fields;
    }

    public function clearGeneratedPdf(Form1702ExBatchRow $row): Form1702ExBatchRow
    {
        if (filled($row->generated_pdf_storage_path)) {
            \App\Support\DocumentStorage::disk()->delete($row->generated_pdf_storage_path);
        }

        $row->forceFill([
            'pdf_error' => null,
            'generated_pdf_file_name' => null,
            'generated_pdf_storage_path' => null,
            'generated_pdf_file_size' => null,
            'generated_at' => null,
        ])->save();

        return $row->fresh() ?? $row;
    }

    public function clearReceipt(Form1702ExBatchRow $row): Form1702ExBatchRow
    {
        $disk = \App\Support\DocumentStorage::disk();
        $baseStoragePath = $row->receiptBaseStoragePath();

        if (filled($row->receipt_storage_path)) {
            $disk->delete($row->receipt_storage_path);
        }

        if ($baseStoragePath !== '' && $disk->exists($baseStoragePath)) {
            $disk->delete($baseStoragePath);
        }

        $row->forceFill([
            'receipt_file_name' => null,
            'receipt_storage_path' => null,
            'receipt_file_size' => null,
            'receipt_is_temporary' => false,
            'receipt_job_status' => null,
            'receipt_job_error' => null,
        ])->save();

        return $row->fresh() ?? $row;
    }

    public function generateBatchRowPdf(Form1702ExBatchRow $row): Form1702ExBatchRow
    {
        $disk = \App\Support\DocumentStorage::disk();
        $temporaryOutputPath = storage_path('app/tmp/form-1702-ex-'.Str::uuid().'.pdf');

        if (! is_dir(dirname($temporaryOutputPath))) {
            mkdir(dirname($temporaryOutputPath), 0777, true);
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = is_array($row->payload) ? $row->payload : [];

            $this->renderPdf($temporaryOutputPath, $payload);

            $storagePath = $this->storagePath($row);
            $fileName = $this->downloadFileName($row);

            $this->storePdfFromPath(
                $disk,
                $storagePath,
                $temporaryOutputPath,
                'The 1702-EX PDF could not be stored.',
            );

            $row->forceFill([
                'pdf_status' => Form1702ExBatchRow::PDF_STATUS_GENERATED,
                'pdf_error' => null,
                'generated_pdf_file_name' => $fileName,
                'generated_pdf_storage_path' => $storagePath,
                'generated_pdf_file_size' => $disk->size($storagePath),
                'generated_at' => now(),
            ])->save();

            return $row->fresh() ?? $row;
        } finally {
            if (is_file($temporaryOutputPath)) {
                @unlink($temporaryOutputPath);
            }
        }
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }
     */
    public function generateReceiptTemplatePdf(int $userId): array
    {
        $schema = $this->receiptFieldSchema();
        $payload = $this->receiptMockPayload();
        $disk = \App\Support\DocumentStorage::disk();
        $temporaryOutputPath = storage_path('app/tmp/form-1702-ex-receipt-'.Str::uuid().'.pdf');

        if (! is_dir(dirname($temporaryOutputPath))) {
            mkdir(dirname($temporaryOutputPath), 0777, true);
        }

        try {
            $this->renderPdfUsingTemplate(
                $temporaryOutputPath,
                $schema,
                $payload,
                $this->receiptTemplatePath(),
            );

            $storagePath = $this->receiptTemplateStoragePath($userId);
            $fileName = $this->receiptTemplateFileName();

            $this->storePdfFromPath(
                $disk,
                $storagePath,
                $temporaryOutputPath,
                'The 1702-EX receipt PDF could not be stored.',
            );

            return $this->storedPdfDetails($storagePath, $fileName)
                ?? throw new RuntimeException('The 1702-EX receipt PDF could not be read after storing.');
        } finally {
            if (is_file($temporaryOutputPath)) {
                @unlink($temporaryOutputPath);
            }
        }
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }|null
     */
    public function latestReceiptTemplatePdf(int $userId): ?array
    {
        return $this->storedPdfDetails(
            $this->receiptTemplateStoragePath($userId),
            $this->receiptTemplateFileName(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function renderReceiptPdf(string $outputPath, array $payload): void
    {
        $this->renderPdfUsingTemplate(
            $outputPath,
            $this->receiptFieldSchema(),
            $payload,
            $this->receiptTemplatePath(),
        );
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }
     */
    public function generatePage2TemplatePdf(int $userId): array
    {
        $schema = $this->page2FieldSchema();
        $payload = $this->page2MockPayload();
        $disk = \App\Support\DocumentStorage::disk();
        $temporaryOutputPath = storage_path('app/tmp/form-1702-ex-page2-'.Str::uuid().'.pdf');

        if (! is_dir(dirname($temporaryOutputPath))) {
            mkdir(dirname($temporaryOutputPath), 0777, true);
        }

        try {
            $this->renderPdfUsingTemplate(
                $temporaryOutputPath,
                $schema,
                $payload,
                $this->page2TemplatePath(),
            );

            $storagePath = $this->page2TemplateStoragePath($userId);
            $fileName = $this->page2TemplateFileName();

            $this->storePdfFromPath(
                $disk,
                $storagePath,
                $temporaryOutputPath,
                'The 1702-EX page 2 PDF could not be stored.',
            );

            return $this->storedPdfDetails($storagePath, $fileName)
                ?? throw new RuntimeException('The 1702-EX page 2 PDF could not be read after storing.');
        } finally {
            if (is_file($temporaryOutputPath)) {
                @unlink($temporaryOutputPath);
            }
        }
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }|null
     */
    public function latestPage2TemplatePdf(int $userId): ?array
    {
        return $this->storedPdfDetails(
            $this->page2TemplateStoragePath($userId),
            $this->page2TemplateFileName(),
        );
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }
     */
    public function generatePage3TemplatePdf(int $userId): array
    {
        $schema = $this->page3FieldSchema();
        $payload = $this->page3MockPayload();
        $disk = \App\Support\DocumentStorage::disk();
        $temporaryOutputPath = storage_path('app/tmp/form-1702-ex-page3-'.Str::uuid().'.pdf');

        if (! is_dir(dirname($temporaryOutputPath))) {
            mkdir(dirname($temporaryOutputPath), 0777, true);
        }

        try {
            $this->renderPdfUsingTemplate(
                $temporaryOutputPath,
                $schema,
                $payload,
                $this->page3TemplatePath(),
            );

            $storagePath = $this->page3TemplateStoragePath($userId);
            $fileName = $this->page3TemplateFileName();

            $this->storePdfFromPath(
                $disk,
                $storagePath,
                $temporaryOutputPath,
                'The 1702-EX page 3 PDF could not be stored.',
            );

            return $this->storedPdfDetails($storagePath, $fileName)
                ?? throw new RuntimeException('The 1702-EX page 3 PDF could not be read after storing.');
        } finally {
            if (is_file($temporaryOutputPath)) {
                @unlink($temporaryOutputPath);
            }
        }
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }|null
     */
    public function latestPage3TemplatePdf(int $userId): ?array
    {
        return $this->storedPdfDetails(
            $this->page3TemplateStoragePath($userId),
            $this->page3TemplateFileName(),
        );
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }
     */
    public function generatePage1TemplatePdf(int $userId): array
    {
        $schema = $this->fieldSchema();
        $payload = $this->mockPayload();
        $disk = \App\Support\DocumentStorage::disk();
        $temporaryOutputPath = storage_path('app/tmp/form-1702-ex-page1-'.Str::uuid().'.pdf');

        if (! is_dir(dirname($temporaryOutputPath))) {
            mkdir(dirname($temporaryOutputPath), 0777, true);
        }

        try {
            $this->renderPdfUsingTemplate(
                $temporaryOutputPath,
                $schema,
                $payload,
                $this->page1TemplatePath(),
            );

            $storagePath = $this->page1TemplateStoragePath($userId);
            $fileName = $this->page1TemplateFileName();

            $this->storePdfFromPath(
                $disk,
                $storagePath,
                $temporaryOutputPath,
                'The 1702-EX page 1 PDF could not be stored.',
            );

            return $this->storedPdfDetails($storagePath, $fileName)
                ?? throw new RuntimeException('The 1702-EX page 1 PDF could not be read after storing.');
        } finally {
            if (is_file($temporaryOutputPath)) {
                @unlink($temporaryOutputPath);
            }
        }
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }|null
     */
    public function latestPage1TemplatePdf(int $userId): ?array
    {
        return $this->storedPdfDetails(
            $this->page1TemplateStoragePath($userId),
            $this->page1TemplateFileName(),
        );
    }

    public function previewVersion(Form1702ExBatchRow $row): string
    {
        return sha1(json_encode([
            'generated_at' => $row->generated_at?->toIso8601String(),
            'file_size' => $row->generated_pdf_file_size,
            'file_name' => $row->generated_pdf_file_name,
            'status' => $row->pdf_status,
            'receipt_file_name' => $row->receipt_file_name,
            'receipt_file_size' => $row->receipt_file_size,
            'receipt_is_temporary' => $row->receipt_is_temporary,
            'receipt_job_status' => $row->receipt_job_status,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPdf(string $outputPath, array $payload): void
    {
        $pdf = $this->newPdfDocument();
        [$fontFamily, $fontStyle] = $this->configurePdfFont($pdf);

        foreach ($this->batchTemplatePages() as $page) {
            $this->appendTemplatePage(
                $pdf,
                $page['schema'],
                $payload,
                $page['templatePath'],
                $fontFamily,
                $fontStyle,
            );
        }

        $this->writePdfToPath($pdf, $outputPath);
    }

    /**
     * @param  array{page: array{width: float, height: float}, fields: list<array<string, mixed>>}  $schema
     * @param  array<string, mixed>  $payload
     */
    private function renderPdfUsingTemplate(
        string $outputPath,
        array $schema,
        array $payload,
        string $templatePath,
    ): void {
        $pdf = $this->newPdfDocument();
        [$fontFamily, $fontStyle] = $this->configurePdfFont($pdf);

        $this->appendTemplatePage(
            $pdf,
            $schema,
            $payload,
            $templatePath,
            $fontFamily,
            $fontStyle,
        );

        $this->writePdfToPath($pdf, $outputPath);
    }

    private function page1TemplatePath(): string
    {
        $templatePath = public_path('form-assets/1702-ex/template-page1-fpdi.pdf');

        if (! is_file($templatePath)) {
            $templatePath = public_path('form-assets/1702-ex/template-page1.pdf');
        }

        return $templatePath;
    }

    private function page2TemplatePath(): string
    {
        $templatePath = public_path('form-assets/1702-ex/template-page2-fpdi.pdf');

        if (! is_file($templatePath)) {
            $templatePath = public_path('form-assets/1702-ex/template-page2.pdf');
        }

        return $templatePath;
    }

    private function page3TemplatePath(): string
    {
        $templatePath = public_path('form-assets/1702-ex/template-page3-fpdi.pdf');

        if (! is_file($templatePath)) {
            $templatePath = public_path('form-assets/1702-ex/template-page3.pdf');
        }

        return $templatePath;
    }

    private function receiptTemplatePath(): string
    {
        $templatePath = public_path('form-assets/1702-ex/template-receipt-fpdi.pdf');

        if (! is_file($templatePath)) {
            $templatePath = public_path('form-assets/1702-ex/template-receipt.pdf');
        }

        return $templatePath;
    }

    private function page2TemplateStoragePath(int $userId): string
    {
        return sprintf(
            'forms/%d/%s/page-2-template/latest.pdf',
            $userId,
            self::FORM_KEY,
        );
    }

    private function page2TemplateFileName(): string
    {
        return '1702-ex-page-2-template.pdf';
    }

    private function page3TemplateStoragePath(int $userId): string
    {
        return sprintf(
            'forms/%d/%s/page-3-template/latest.pdf',
            $userId,
            self::FORM_KEY,
        );
    }

    private function page3TemplateFileName(): string
    {
        return '1702-ex-page-3-template.pdf';
    }

    private function page1TemplateStoragePath(int $userId): string
    {
        return sprintf(
            'forms/%d/%s/page-1-template/latest.pdf',
            $userId,
            self::FORM_KEY,
        );
    }

    private function page1TemplateFileName(): string
    {
        return '1702-ex-page-1-template.pdf';
    }

    private function receiptTemplateStoragePath(int $userId): string
    {
        return sprintf(
            'forms/%d/%s/receipt-template/latest.pdf',
            $userId,
            self::FORM_KEY,
        );
    }

    private function receiptTemplateFileName(): string
    {
        return '1702-ex-receipt-template.pdf';
    }

    /**
     * @return list<array{
     *     schema: array{page: array{width: float, height: float}, fields: list<array<string, mixed>>},
     *     templatePath: string
     * }>
     */
    private function batchTemplatePages(): array
    {
        return [
            [
                'schema' => $this->fieldSchema(),
                'templatePath' => $this->page1TemplatePath(),
            ],
            [
                'schema' => $this->page2FieldSchema(),
                'templatePath' => $this->page2TemplatePath(),
            ],
            [
                'schema' => $this->page3FieldSchema(),
                'templatePath' => $this->page3TemplatePath(),
            ],
        ];
    }

    /**
     * @return array{
     *     page: array{width: float, height: float},
     *     fields: list<array<string, mixed>>
     * }
     */
    private function fieldSchemaFromPath(string $path, string $invalidMessage): array
    {
        $schema = $this->readJsonFile($path);

        if (
            ! isset($schema['page'], $schema['fields'])
            || ! is_array($schema['page'])
            || ! is_array($schema['fields'])
        ) {
            throw new RuntimeException($invalidMessage);
        }

        return [
            'page' => [
                'width' => (float) ($schema['page']['width'] ?? 0),
                'height' => (float) ($schema['page']['height'] ?? 0),
            ],
            'fields' => array_values(array_map(
                static fn (mixed $field): array => is_array($field) ? $field : [],
                $schema['fields'],
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromPath(string $path, string $invalidMessage): array
    {
        $payload = $this->readJsonFile($path);

        if (! is_array($payload)) {
            throw new RuntimeException($invalidMessage);
        }

        return $payload;
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string
     * }|null
     */
    private function storedPdfDetails(string $storagePath, string $fileName): ?array
    {
        $disk = \App\Support\DocumentStorage::disk();

        if (! $disk->exists($storagePath)) {
            return null;
        }

        $fileSize = $disk->size($storagePath);
        $generatedAt = Carbon::createFromTimestamp($disk->lastModified($storagePath))->toIso8601String();

        return [
            'storagePath' => $storagePath,
            'fileName' => $fileName,
            'fileSize' => $fileSize,
            'generatedAt' => $generatedAt,
            'version' => sha1(json_encode([
                'storage_path' => $storagePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'generated_at' => $generatedAt,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    private function newPdfDocument(): FormPdfFpdi
    {
        $pdf = new FormPdfFpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);

        return $pdf;
    }

    /**
     * @param  array{page: array{width: float, height: float}, fields: list<array<string, mixed>>}  $schema
     * @param  array<string, mixed>  $payload
     */
    private function appendTemplatePage(
        FormPdfFpdi $pdf,
        array $schema,
        array $payload,
        string $templatePath,
        string $fontFamily,
        string $fontStyle,
    ): void {
        if (! is_file($templatePath)) {
            throw new RuntimeException('The committed 1702-EX template PDF is missing.');
        }

        $pageCount = $pdf->setSourceFile($templatePath);

        if ($pageCount < 1) {
            throw new RuntimeException('The committed 1702-EX template PDF has no pages.');
        }

        $templateId = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($templateId);
        $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
        $pageWidth = (float) $size['width'];
        $pageHeight = (float) $size['height'];

        $pdf->AddPage($orientation, [$pageWidth, $pageHeight]);
        $pdf->useTemplate($templateId, 0, 0, $pageWidth, $pageHeight);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFillColor(
            self::PDF_MARKER_FILL_RED,
            self::PDF_MARKER_FILL_GREEN,
            self::PDF_MARKER_FILL_BLUE,
        );

        $preparedSignature = $this->prepareSignatureImage($payload);

        try {
            foreach ($schema['fields'] as $field) {
                $this->renderField(
                    $pdf,
                    $field,
                    $payload,
                    $pageWidth,
                    $pageHeight,
                    $fontFamily,
                    $fontStyle,
                    $preparedSignature['path'],
                );
            }
        } finally {
            foreach ($preparedSignature['cleanupPaths'] as $cleanupPath) {
                if (is_string($cleanupPath) && $cleanupPath !== '' && is_file($cleanupPath)) {
                    @unlink($cleanupPath);
                }
            }
        }
    }

    private function writePdfToPath(FormPdfFpdi $pdf, string $outputPath): void
    {
        $pdf->Output('F', $outputPath);

        if (! is_file($outputPath)) {
            throw new RuntimeException('The 1702-EX PDF could not be created.');
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $payload
     */
    private function renderField(
        FormPdfFpdi $pdf,
        array $field,
        array $payload,
        float $pageWidth,
        float $pageHeight,
        string $fontFamily,
        string $fontStyle,
        ?string $signatureImagePath = null,
    ): void {
        $x = (float) ($field['x'] ?? 0) * $pageWidth;
        $y = (float) ($field['y'] ?? 0) * $pageHeight;
        $width = (float) ($field['width'] ?? 0) * $pageWidth;
        $height = (float) ($field['height'] ?? 0) * $pageHeight;
        $fontSize = (float) ($field['fontSize'] ?? 10);
        $type = (string) ($field['type'] ?? 'text');
        $align = match ((string) ($field['align'] ?? 'left')) {
            'center' => 'C',
            'right' => 'R',
            default => 'L',
        };
        [$resolvedFontFamily, $resolvedFontStyle] = $this->resolvePdfFieldFont(
            $field,
            $fontFamily,
            $fontStyle,
        );

        $this->fillFieldBackground($pdf, $field, $x, $y, $width, $height);

        if ($type === 'checkbox') {
            if (! $this->fieldValueFormatter->isChecked($field, $payload)) {
                return;
            }

            $markerDiameter = max(1.0, (float) ($field['markerSize'] ?? $fontSize));

            $pdf->drawFilledCircle(
                $x + ($width / 2),
                $y + ($height / 2),
                $markerDiameter / 2,
            );

            return;
        }

        if ($type === 'split-box') {
            $characters = $this->fieldValueFormatter->splitCharacters($field, $payload);
            $boxCount = max(1, count($characters));
            $boxGap = (float) ($field['boxGap'] ?? 0);
            $boxWidth = max(
                0.0,
                ($width - (($boxCount - 1) * $boxGap)) / $boxCount,
            );

            $pdf->SetFont($resolvedFontFamily, $resolvedFontStyle, max(1.0, $fontSize));

            for ($index = 0; $index < $boxCount; $index++) {
                $character = $characters[$index] ?? '';
                $boxX = $x + ($index * ($boxWidth + $boxGap));
                $pdf->SetXY($boxX, $y);
                $pdf->Cell(
                    $boxWidth,
                    $height,
                    $this->encodedPdfText($character),
                    0,
                    0,
                    'C',
                );
            }

            return;
        }

        if ($type === 'image') {
            if (! is_string($signatureImagePath) || $signatureImagePath === '' || ! is_file($signatureImagePath)) {
                return;
            }

            $pdf->Image(
                $signatureImagePath,
                $x,
                $y,
                $width,
                $height,
                'PNG',
            );

            return;
        }

        $pdf->SetFont($resolvedFontFamily, $resolvedFontStyle, max(1.0, $fontSize));
        $pdf->SetXY($x, $y);
        $pdf->Cell(
            $width,
            $height,
            $this->encodedPdfText($this->fieldValueFormatter->formatText($field, $payload)),
            0,
            0,
            $align,
        );
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function fillFieldBackground(
        FormPdfFpdi $pdf,
        array $field,
        float $x,
        float $y,
        float $width,
        float $height,
    ): void {
        $backgroundFill = $field['backgroundFill'] ?? null;

        if (! is_string($backgroundFill) || ! preg_match('/^#?([a-f0-9]{6})$/i', trim($backgroundFill), $matches)) {
            return;
        }

        $hex = $matches[1];
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        $pdf->SetFillColor($red, $green, $blue);
        $pdf->Rect($x, $y, $width, $height, 'F');
        $pdf->SetFillColor(
            self::PDF_MARKER_FILL_RED,
            self::PDF_MARKER_FILL_GREEN,
            self::PDF_MARKER_FILL_BLUE,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{path: string|null, cleanupPaths: list<string>}
     */
    private function prepareSignatureImage(array $payload): array
    {
        $signatureValue = $payload['signature'] ?? null;

        if (! is_scalar($signatureValue) && $signatureValue !== null) {
            return ['path' => null, 'cleanupPaths' => []];
        }

        $rawPath = trim((string) ($signatureValue ?? ''));

        if ($rawPath === '') {
            return ['path' => null, 'cleanupPaths' => []];
        }

        $normalizedToken = strtoupper($rawPath);
        if (in_array($normalizedToken, ['#VALUE', '#VALUE!', '#N/A', '#NAME?', '#DIV/0!', '#REF!', '#NULL!', '#NUM!'], true)) {
            return ['path' => null, 'cleanupPaths' => []];
        }

        $cleanupPaths = [];
        $sourcePath = null;

        if (preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/i', $rawPath) === 1) {
            $decoded = base64_decode((string) preg_replace('/^data:image\/(?:png|jpeg|jpg|webp);base64,/i', '', $rawPath), true);

            if (is_string($decoded) && $decoded !== '') {
                $extension = str_contains(strtolower($rawPath), 'image/png') ? '.png'
                    : (str_contains(strtolower($rawPath), 'image/webp') ? '.webp' : '.jpg');
                $tmpPath = $this->temporaryPath('form-1702-signature-inline-', $extension);

                if (@file_put_contents($tmpPath, $decoded) !== false) {
                    $sourcePath = $tmpPath;
                    $cleanupPaths[] = $tmpPath;
                } else {
                    @unlink($tmpPath);
                }
            }
        } elseif (is_file($rawPath)) {
            $sourcePath = $rawPath;
        } elseif (str_starts_with($rawPath, 'file://')) {
            $fileUriPath = urldecode((string) parse_url($rawPath, PHP_URL_PATH));

            if ($fileUriPath !== '' && is_file($fileUriPath)) {
                $sourcePath = $fileUriPath;
            }
        } elseif (DocumentStorage::isValidPath($rawPath) && DocumentStorage::disk()->exists($rawPath)) {
            $extension = strtolower((string) pathinfo($rawPath, PATHINFO_EXTENSION));
            $tmpPath = $this->temporaryPath('form-1702-signature-source-', $extension !== '' ? ".{$extension}" : '.bin');
            $stream = DocumentStorage::disk()->readStream($rawPath);

            if (is_resource($stream)) {
                $target = @fopen($tmpPath, 'wb');

                if (is_resource($target)) {
                    stream_copy_to_stream($stream, $target);
                    fclose($target);
                    fclose($stream);
                    $sourcePath = $tmpPath;
                    $cleanupPaths[] = $tmpPath;
                } else {
                    fclose($stream);
                    @unlink($tmpPath);
                }
            } else {
                @unlink($tmpPath);
            }
        }

        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            return ['path' => null, 'cleanupPaths' => $cleanupPaths];
        }

        try {
            $processedPath = $this->signatureImageService->processToTransparentPng($sourcePath);
            $cleanupPaths[] = $processedPath;
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'path' => null,
                'cleanupPaths' => $cleanupPaths,
            ];
        }

        return [
            'path' => $processedPath,
            'cleanupPaths' => $cleanupPaths,
        ];
    }

    private function temporaryPath(string $prefix, string $extension): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);

        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate temporary file path.');
        }

        @unlink($tmp);

        return $tmp.$extension;
    }

    private function encodedPdfText(string $value): string
    {
        $encoded = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);

        if ($encoded === false) {
            return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        }

        return $encoded;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function configurePdfFont(FormPdfFpdi $pdf): array
    {
        $fontDir = storage_path('app/form-fonts');
        $definitionPath = $fontDir.DIRECTORY_SEPARATOR.self::PDF_FONT_DEFINITION;

        if (is_file($definitionPath)) {
            $pdf->AddFont(
                self::PDF_FONT_FAMILY,
                self::PDF_FONT_STYLE,
                self::PDF_FONT_DEFINITION,
                $fontDir,
            );

            return [self::PDF_FONT_FAMILY, self::PDF_FONT_STYLE];
        }

        return ['Helvetica', ''];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{0: string, 1: string}
     */
    private function resolvePdfFieldFont(
        array $field,
        string $defaultFamily,
        string $defaultStyle,
    ): array {
        $family = (string) ($field['fontFamily'] ?? '');
        $style = '';

        $fontStyle = Str::lower((string) ($field['fontStyle'] ?? ''));
        if (str_contains($fontStyle, 'italic')) {
            $style .= 'I';
        }

        $fontWeight = $field['fontWeight'] ?? null;
        if (
            $fontWeight === 'bold'
            || $fontWeight === '700'
            || $fontWeight === 700
            || (is_numeric($fontWeight) && (int) $fontWeight >= 600)
        ) {
            $style = str_contains($style, 'B') ? $style : 'B'.$style;
        }

        $normalizedFamily = Str::lower($family);

        if (str_contains($normalizedFamily, 'times')) {
            return ['Times', $style];
        }

        if (
            str_contains($normalizedFamily, 'arial')
            || str_contains($normalizedFamily, 'helvetica')
        ) {
            return ['Arial', $style];
        }

        return [$defaultFamily, $defaultStyle];
    }

    /**
     * @param  list<array<string, mixed>>  $templates
     * @return array<string, mixed>
     */
    private function blankPayloadFromTemplates(array $templates): array
    {
        $payload = [];

        foreach ($templates as $template) {
            foreach ($template as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }

                $payload[$key] = is_bool($value) ? false : '';
            }
        }

        return $payload;
    }

    private function displayLabelFromKey(string $key): string
    {
        $label = trim(preg_replace('/[._-]+/', ' ', $key) ?? $key);

        if ($label === '') {
            return $key;
        }

        return (string) Str::of($label)
            ->lower()
            ->title();
    }

    private function storagePath(Form1702ExBatchRow $row): string
    {
        $row->loadMissing('batch');

        return sprintf(
            'forms/%d/%s/batches/%d/%s.pdf',
            $row->batch->user_id,
            self::FORM_KEY,
            $row->form_1702_ex_batch_id,
            $row->uuid,
        );
    }

    private function downloadFileName(Form1702ExBatchRow $row): string
    {
        $row->loadMissing('batch');

        /** @var array<string, mixed> $payload */
        $payload = is_array($row->payload) ? $row->payload : [];
        $prefix = $this->normalizeFileNameToken(
            $this->resolveFileNamePrefix(
                $row->batch->file_name_prefix
                ?? (is_scalar($payload['file_name_prefix'] ?? null)
                    ? (string) $payload['file_name_prefix']
                    : null),
            ),
            false,
        );
        $taxpayerName = $this->normalizeFileNameToken(
            (string) ($payload['taxpayer_name'] ?? $payload['registered_name'] ?? ''),
            true,
        );

        if ($taxpayerName === '') {
            $taxpayerName = $this->normalizeFileNameToken('row_'.$row->source_row_number, true);
        }

        $tokens = array_values(array_filter([$prefix, $taxpayerName], static fn (string $token): bool => $token !== ''));
        $fileName = $tokens !== [] ? implode('_', $tokens) : 'row';

        return "{$fileName}.pdf";
    }

    private function normalizeFileNameToken(string $value, bool $uppercase): string
    {
        $normalized = Str::of($value)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_');

        return $uppercase
            ? (string) $normalized->upper()
            : (string) $normalized;
    }

    private function storePdfFromPath(
        FilesystemAdapter $disk,
        string $storagePath,
        string $pdfPath,
        string $errorMessage,
    ): void {
        $stream = @fopen($pdfPath, 'rb');

        if (! is_resource($stream)) {
            throw new RuntimeException($errorMessage);
        }

        try {
            if (! $disk->put($storagePath, $stream)) {
                throw new RuntimeException($errorMessage);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Missing required form asset: {$path}");
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException("The form asset could not be read: {$path}");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("The form asset is invalid: {$path}");
        }

        return $decoded;
    }
}
