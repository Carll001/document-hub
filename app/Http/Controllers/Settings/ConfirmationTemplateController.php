<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ConfirmationTemplateController extends Controller
{
    public function edit(): Response
    {
        $usesFpdiTemplate = is_file($this->activeReceiptTemplatePath());

        return Inertia::render('settings/ConfirmationTemplate', [
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'receiptTemplate' => [
                'alignmentUrl' => route('forms.form1702ex.receipt-template.show'),
                'updateUrl' => route('confirmation-template.update'),
                'activePdfUrl' => asset(
                    $usesFpdiTemplate
                        ? 'form-assets/1702-ex/template-receipt-fpdi.pdf'
                        : 'form-assets/1702-ex/template-receipt.pdf'
                ),
                'activePdfPath' => $usesFpdiTemplate
                    ? 'public/form-assets/1702-ex/template-receipt-fpdi.pdf'
                    : 'public/form-assets/1702-ex/template-receipt.pdf',
                'fallbackPdfPath' => 'public/form-assets/1702-ex/template-receipt.pdf',
                'usesFpdiTemplate' => $usesFpdiTemplate,
                'schemaPath' => 'resources/forms/templates/1702-ex/receipt.schema.json',
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'receipt_template' => ['required', 'file', 'mimetypes:application/pdf', 'max:20480'],
        ]);

        $uploadedTemplate = $validated['receipt_template'];
        abort_unless($uploadedTemplate instanceof UploadedFile, 422);

        $sourcePath = $uploadedTemplate->getRealPath();

        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            throw new RuntimeException('The uploaded receipt PDF could not be read.');
        }

        $targetPath = $this->activeReceiptTemplatePath();

        File::ensureDirectoryExists(dirname($targetPath));

        if (! File::copy($sourcePath, $targetPath)) {
            throw new RuntimeException('The receipt PDF could not be replaced.');
        }

        return to_route('confirmation-template.edit')
            ->with('success', 'Confirmation receipt PDF updated.');
    }

    private function activeReceiptTemplatePath(): string
    {
        return public_path('form-assets/1702-ex/template-receipt-fpdi.pdf');
    }
}
