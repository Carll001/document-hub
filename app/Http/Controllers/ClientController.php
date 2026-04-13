<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Form1702ExBatchRow;
use App\Services\Form1702ExCompletedEmailService;
use App\Services\Form1702ExService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function __construct(
        private readonly Form1702ExCompletedEmailService $form1702ExCompletedEmailService,
        private readonly Form1702ExService $form1702ExService,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $clients = Client::query()
            ->whereBelongsTo($user)
            ->withCount('companies')
            ->withCount([
                'rows as completed_1702_ex_count' => fn ($query) => $this->applyCompleted1702ExScope($query),
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('Clients', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'clients' => $clients->map(fn (Client $client): array => [
                'id' => $client->uuid,
                'name' => $client->name,
                'companyCount' => (int) $client->companies_count,
                'completed1702ExCount' => (int) $client->completed_1702_ex_count,
                'showUrl' => route('clients.show', ['client' => $client]),
            ])->all(),
        ]);
    }

    public function show(Request $request, Client $client): Response
    {
        $this->ensureAccessibleClient($request, $client);

        $rows = Form1702ExBatchRow::query()
            ->with(['company', 'batch'])
            ->where('client_id', $client->id)
            ->whereNotNull('company_id')
            ->whereNull('duplicate_resolution_status')
            ->orderByDesc('generated_at')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();

        $completedRows = $rows->filter(fn (Form1702ExBatchRow $row): bool => $this->isCompletedRow($row))->values();

        $recipientEmails = $completedRows
            ->map(fn (Form1702ExBatchRow $row): ?string => $this->form1702ExCompletedEmailService->recipientEmail($row))
            ->filter()
            ->unique()
            ->values();

        $companyGroups = $rows
            ->groupBy('company_id')
            ->map(function ($rows) {
                /** @var Form1702ExBatchRow $firstRow */
                $firstRow = $rows->first();
                $company = $firstRow->company;
                [$statusLabel, $statusVariant] = $this->companyStatus($rows);

                return [
                    'id' => $company?->uuid ?? 'company-'.$firstRow->id,
                    'name' => $company?->name ?? 'Unassigned Company',
                    'tin' => $company?->tin ?? '',
                    'completedCount' => $rows->filter(fn (Form1702ExBatchRow $row): bool => $this->isCompletedRow($row))->count(),
                    'recipientEmails' => $rows
                        ->map(fn (Form1702ExBatchRow $row): ?string => $this->form1702ExCompletedEmailService->recipientEmail($row))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'statusLabel' => $statusLabel,
                    'statusVariant' => $statusVariant,
                    'files' => $rows->map(fn (Form1702ExBatchRow $row): array => [
                        'id' => $row->uuid,
                    'fileName' => filled($row->generated_pdf_file_name)
                        ? (string) $row->generated_pdf_file_name
                        : 'Not generated yet',
                    'generatedAt' => $row->generated_at?->toIso8601String(),
                    'previewUrl' => filled($row->generated_pdf_storage_path)
                        ? route('forms.1702-ex.rows.preview', [
                            'form1702ExBatchRow' => $row,
                            'v' => $this->form1702ExService->previewVersion($row),
                        ])
                        : null,
                    'downloadUrl' => filled($row->generated_pdf_storage_path)
                        ? route('forms.1702-ex.rows.download', [
                            'form1702ExBatchRow' => $row,
                        ])
                            : null,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('ClientShow', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'clientsUrl' => route('clients.index'),
            'client' => [
                'id' => $client->uuid,
                'name' => $client->name,
                'folder' => [
                    'label' => '1702-EX',
                    'companyCount' => count($companyGroups),
                    'completedCount' => $completedRows->count(),
                    'recipientCount' => $recipientEmails->count(),
                    'primaryRecipient' => $recipientEmails->count() === 1
                        ? (string) $recipientEmails->first()
                        : null,
                    'sendUrl' => route('clients.forms.1702-ex.send', ['client' => $client]),
                    'canSend' => $completedRows->isNotEmpty() && $recipientEmails->count() === 1,
                    'warning' => $completedRows->isEmpty()
                        ? 'No completed 1702-EX files are ready for this client yet.'
                        : ($recipientEmails->count() > 1
                            ? 'This client has multiple recipient emails across its companies, so grouped sending is blocked until they match.'
                            : ($recipientEmails->isEmpty()
                                ? 'This client has completed files but no saved recipient email yet.'
                                : null)),
                ],
                'companies' => $companyGroups,
            ],
        ]);
    }

    public function send1702Ex(Request $request, Client $client): RedirectResponse
    {
        $this->ensureAccessibleClient($request, $client);

        $rows = Form1702ExBatchRow::query()
            ->with('company')
            ->where('client_id', $client->id)
            ->whereNotNull('company_id')
            ->whereNull('duplicate_resolution_status')
            ->where('pdf_status', Form1702ExBatchRow::PDF_STATUS_GENERATED)
            ->whereNotNull('generated_pdf_storage_path')
            ->whereNotNull('receipt_storage_path')
            ->whereNotNull('receipt_file_name')
            ->get();

        if ($rows->isEmpty()) {
            return to_route('clients.show', ['client' => $client])
                ->with('error', 'No completed 1702-EX files are ready for this client yet.');
        }

        $recipientEmails = $rows
            ->map(fn (Form1702ExBatchRow $row): ?string => $this->form1702ExCompletedEmailService->recipientEmail($row))
            ->filter()
            ->unique()
            ->values();

        if ($recipientEmails->isEmpty()) {
            return to_route('clients.show', ['client' => $client])
                ->with('error', 'The completed 1702-EX files for this client do not have a saved recipient email yet.');
        }

        if ($recipientEmails->count() > 1) {
            return to_route('clients.show', ['client' => $client])
                ->with('error', 'Grouped send is blocked because this client has multiple recipient emails across its companies.');
        }

        $queuedRecipient = $this->form1702ExCompletedEmailService->queueClientBulk($client, $rows);

        if ($queuedRecipient === null) {
            return to_route('clients.show', ['client' => $client])
                ->with('error', 'The grouped client email could not be queued right now.');
        }

        return to_route('clients.show', ['client' => $client])
            ->with('success', sprintf(
                'Queued %d completed 1702-EX file(s) for %s to %s.',
                $rows->count(),
                $client->name,
                $queuedRecipient,
            ));
    }

    private function ensureAccessibleClient(Request $request, Client $client): void
    {
        $client->loadMissing('user');

        abort_unless($client->user->is($request->user()), 404);
    }

    private function applyCompleted1702ExScope($query): void
    {
        $query
            ->whereNull('duplicate_resolution_status')
            ->where('pdf_status', Form1702ExBatchRow::PDF_STATUS_GENERATED)
            ->whereNotNull('generated_pdf_storage_path')
            ->whereNotNull('receipt_storage_path')
            ->whereNotNull('receipt_file_name');
    }

    private function isCompletedRow(Form1702ExBatchRow $row): bool
    {
        return $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_GENERATED
            && filled($row->generated_pdf_storage_path)
            && filled($row->receipt_storage_path)
            && filled($row->receipt_file_name);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Form1702ExBatchRow>  $rows
     * @return array{0: string, 1: string}
     */
    private function companyStatus($rows): array
    {
        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $this->isCompletedRow($row))) {
            return ['Completed', 'secondary'];
        }

        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_PROCESSING)) {
            return ['Processing', 'outline'];
        }

        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_QUEUED)) {
            return ['Queued', 'outline'];
        }

        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_FAILED)) {
            return ['Failed', 'destructive'];
        }

        return ['Pending', 'outline'];
    }
}
