<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Form1702ExService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Form1702ExPage3TemplateController extends Controller
{
    public function __construct(
        private readonly Form1702ExService $form1702ExService,
    ) {
    }

    public function show(Request $request): View
    {
        $schema = $this->form1702ExService->page3FieldSchema();
        $payload = $this->form1702ExService->page3MockPayload();
        $fields = $this->form1702ExService->previewFields($schema['fields'], $payload);
        $latestExport = $this->latestExport($request);

        return view('forms.1702-ex-page3-template', [
            'flash' => $this->flash($request),
            'fields' => $fields,
            'latestExport' => $latestExport,
            'mockExportUrl' => route('forms.form1702ex.page-3-template.generate'),
            'payload' => $payload,
            'schema' => $schema,
            'templatePdfUrl' => asset('form-assets/1702-ex/template-page3.pdf'),
            'backgroundUrl' => asset('form-assets/1702-ex/page3-template.png'),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        try {
            $this->form1702ExService->generatePage3TemplatePdf((int) $request->user()->id);

            return to_route('forms.form1702ex.page-3-template.show')
                ->with('success', 'The 1702-EX page 3 PDF was generated.');
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('forms.form1702ex.page-3-template.show')
                ->with('error', 'The 1702-EX page 3 PDF could not be generated right now.');
        }
    }

    public function preview(Request $request): StreamedResponse
    {
        $latestExport = $this->latestExport($request);
        abort_unless(is_array($latestExport), 404);

        return Storage::disk('s3')->response(
            $latestExport['storagePath'],
            $latestExport['fileName'],
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function download(Request $request): StreamedResponse
    {
        $latestExport = $this->latestExport($request);
        abort_unless(is_array($latestExport), 404);

        return Storage::disk('s3')->download(
            $latestExport['storagePath'],
            $latestExport['fileName'],
        );
    }

    /**
     * @return array{
     *     success: string|null,
     *     error: string|null
     * }
     */
    private function flash(Request $request): array
    {
        return [
            'success' => $request->session()->get('success'),
            'error' => $request->session()->get('error'),
        ];
    }

    /**
     * @return array{
     *     storagePath: string,
     *     fileName: string,
     *     fileSize: int,
     *     generatedAt: string,
     *     version: string,
     *     previewUrl: string,
     *     downloadUrl: string
     * }|null
     */
    private function latestExport(Request $request): ?array
    {
        $latestExport = $this->form1702ExService->latestPage3TemplatePdf((int) $request->user()->id);

        if ($latestExport === null) {
            return null;
        }

        $latestExport['previewUrl'] = route('forms.form1702ex.page-3-template.preview', [
            'v' => $latestExport['version'],
        ]);
        $latestExport['downloadUrl'] = route('forms.form1702ex.page-3-template.download');

        return $latestExport;
    }
}
