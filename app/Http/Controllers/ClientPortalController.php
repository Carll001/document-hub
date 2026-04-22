<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Form1702ExBatchRow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientPortalController extends Controller
{
    public function files(Request $request): Response
    {
        $client = $this->resolveAuthenticatedClient($request);

        abort_unless($client instanceof Client, 404);

        $rows = Form1702ExBatchRow::query()
            ->where('client_id', $client->id)
            ->whereNull('duplicate_resolution_status')
            ->where('pdf_status', Form1702ExBatchRow::PDF_STATUS_GENERATED)
            ->whereNotNull('generated_pdf_storage_path')
            ->whereNotNull('receipt_storage_path')
            ->whereNotNull('receipt_file_name')
            ->where('receipt_is_temporary', false)
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('client/Files', [
            'client' => [
                'name' => $client->name,
            ],
            'rows' => $rows->map(function (Form1702ExBatchRow $row): array {
                $payload = is_array($row->payload) ? $row->payload : [];

                return [
                    'id' => $row->uuid,
                    'fileName' => $row->generated_pdf_file_name ?? 'Completed file',
                    'taxpayerName' => (string) ($payload['taxpayer_name'] ?? $payload['registered_name'] ?? 'Taxpayer'),
                    'generatedAt' => $row->generated_at?->toIso8601String(),
                    'previewUrl' => route('client.files.preview', [
                        'form1702ExBatchRow' => $row,
                    ]),
                    'downloadUrl' => route('client.files.download', [
                        'form1702ExBatchRow' => $row,
                    ]),
                ];
            })->all(),
        ]);
    }

    public function preview(Request $request, Form1702ExBatchRow $form1702ExBatchRow): StreamedResponse
    {
        $row = $this->resolveAccessibleCompletedRow($request, $form1702ExBatchRow);

        abort_unless(
            filled($row->generated_pdf_storage_path)
            && Storage::disk('s3')->exists((string) $row->generated_pdf_storage_path),
            404,
        );

        return Storage::disk('s3')->response(
            (string) $row->generated_pdf_storage_path,
            (string) ($row->generated_pdf_file_name ?? '1702-ex.pdf'),
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.($row->generated_pdf_file_name ?? '1702-ex.pdf').'"',
            ],
        );
    }

    public function download(Request $request, Form1702ExBatchRow $form1702ExBatchRow): BinaryFileResponse
    {
        $row = $this->resolveAccessibleCompletedRow($request, $form1702ExBatchRow);

        abort_unless(
            filled($row->generated_pdf_storage_path)
            && Storage::disk('s3')->exists((string) $row->generated_pdf_storage_path),
            404,
        );

        return Storage::disk('s3')->download(
            (string) $row->generated_pdf_storage_path,
            (string) ($row->generated_pdf_file_name ?? '1702-ex.pdf'),
        );
    }

    private function resolveAuthenticatedClient(Request $request): ?Client
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return Client::query()
            ->where('login_user_id', $user->id)
            ->first();
    }

    private function resolveAccessibleCompletedRow(Request $request, Form1702ExBatchRow $row): Form1702ExBatchRow
    {
        $client = $this->resolveAuthenticatedClient($request);

        abort_unless($client instanceof Client, 404);

        $row->loadMissing('client');

        abort_unless(
            $row->client_id === $client->id
            && ! $row->isSkippedDuplicate()
            && $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_GENERATED
            && filled($row->generated_pdf_storage_path)
            && filled($row->receipt_storage_path)
            && filled($row->receipt_file_name)
            && ! $row->receipt_is_temporary,
            404,
        );

        return $row;
    }
}
