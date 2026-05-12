<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsPaginationPayload;
use App\Models\Client;
use App\Models\Company;
use App\Models\Form1702ExBatchRow;
use App\Services\Form1702ExCompletedEmailService;
use App\Services\Form1702ExService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    use BuildsPaginationPayload;

    private const CLIENTS_PER_PAGE_DEFAULT = 15;

    private const COMPANIES_PER_PAGE = 15;

    public function __construct(
        private readonly Form1702ExCompletedEmailService $form1702ExCompletedEmailService,
        private readonly Form1702ExService $form1702ExService,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $perPage = (int) ($validated['per_page'] ?? self::CLIENTS_PER_PAGE_DEFAULT);
        $page = (int) ($validated['page'] ?? 1);

        $clientsPage = Client::query()
            ->whereBelongsTo($user)
            ->withCount('companies')
            ->withCount([
                'rows as completed_1702_ex_count' => fn ($query) => $this->applyCompleted1702ExScope($query),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        return Inertia::render('Clients', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'indexUrl' => route('clients.index'),
            'filters' => [
                'search' => $search,
                'per_page' => $clientsPage->perPage(),
            ],
            'pagination' => $this->paginationPayload($clientsPage),
            'clients' => collect($clientsPage->items())->map(fn (Client $client): array => [
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
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $rows = Form1702ExBatchRow::query()
            ->with(['company', 'batch'])
            ->where('client_id', $client->id)
            ->whereNotNull('company_id')
            ->whereNull('duplicate_resolution_status')
            ->orderByDesc('generated_at')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();
        $page = (int) ($validated['page'] ?? 1);

        $completedRows = $rows->filter(fn (Form1702ExBatchRow $row): bool => $this->isCompletedRow($row))->values();

        $recipientEmails = $completedRows
            ->map(fn (Form1702ExBatchRow $row): ?string => $this->form1702ExCompletedEmailService->recipientEmail($row))
            ->filter()
            ->unique()
            ->values();

        $companyPage = $this->companyPage($client, $page);
        $pageCompanyIds = collect($companyPage->items())
            ->map(fn (Company $company): int => (int) $company->getKey())
            ->all();
        $pageRows = $rows
            ->filter(fn (Form1702ExBatchRow $row): bool => $row->company_id !== null && in_array((int) $row->company_id, $pageCompanyIds, true))
            ->values();
        $companyGroups = $this->transformCompanyGroups($pageRows, collect($companyPage->items()));

        return Inertia::render('ClientShow', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'clientsUrl' => route('clients.index'),
            'pageUrl' => route('clients.show', ['client' => $client]),
            'client' => [
                'id' => $client->uuid,
                'name' => $client->name,
                'folder' => [
                    'label' => '1702-EX',
                    'companyCount' => $this->companyCount($rows),
                    'completedCount' => $completedRows->count(),
                    'recipientCount' => $recipientEmails->count(),
                    'primaryRecipient' => $recipientEmails->count() === 1
                        ? (string) $recipientEmails->first()
                        : null,
                    'sendUrl' => route('clients.forms.form1702ex.send', ['client' => $client]),
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
                'pagination' => $this->paginationPayload($companyPage),
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
            ->where('receipt_is_temporary', false)
            ->get();

        if ($rows->isEmpty()) {
            return to_route('clients.show', $this->showRouteParameters($request, $client))
                ->with('error', 'No completed 1702-EX files are ready for this client yet.');
        }

        $recipientEmails = $rows
            ->map(fn (Form1702ExBatchRow $row): ?string => $this->form1702ExCompletedEmailService->recipientEmail($row))
            ->filter()
            ->unique()
            ->values();

        if ($recipientEmails->isEmpty()) {
            return to_route('clients.show', $this->showRouteParameters($request, $client))
                ->with('error', 'The completed 1702-EX files for this client do not have a saved recipient email yet.');
        }

        if ($recipientEmails->count() > 1) {
            return to_route('clients.show', $this->showRouteParameters($request, $client))
                ->with('error', 'Grouped send is blocked because this client has multiple recipient emails across its companies.');
        }

        $queuedRecipient = $this->form1702ExCompletedEmailService->queueClientBulk($client, $rows);

        if ($queuedRecipient === null) {
            return to_route('clients.show', $this->showRouteParameters($request, $client))
                ->with('error', 'The grouped client email could not be queued right now.');
        }

        return to_route('clients.show', $this->showRouteParameters($request, $client))
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

    /**
     * @return array{client: Client, page?: int}
     */
    private function showRouteParameters(Request $request, Client $client): array
    {
        $parameters = ['client' => $client];

        if ($request->filled('page')) {
            $parameters['page'] = max(1, (int) $request->input('page'));
        }

        return $parameters;
    }

    /**
     * @param  Collection<int, Form1702ExBatchRow>  $rows
     */
    private function companyCount(Collection $rows): int
    {
        return $rows
            ->pluck('company_id')
            ->filter()
            ->unique()
            ->count();
    }

    private function companyPage(Client $client, int $page): LengthAwarePaginator
    {
        return Company::query()
            ->select('companies.*')
            ->selectRaw('MAX(form_1702_ex_batch_rows.generated_at) as latest_generated_at')
            ->selectRaw('MAX(form_1702_ex_batch_rows.uploaded_at) as latest_uploaded_at')
            ->selectRaw('MAX(form_1702_ex_batch_rows.id) as latest_row_id')
            ->join('form_1702_ex_batch_rows', 'form_1702_ex_batch_rows.company_id', '=', 'companies.id')
            ->where('companies.client_id', $client->id)
            ->whereNull('form_1702_ex_batch_rows.duplicate_resolution_status')
            ->groupBy('companies.id')
            ->orderByRaw('MAX(form_1702_ex_batch_rows.generated_at) DESC')
            ->orderByRaw('MAX(form_1702_ex_batch_rows.uploaded_at) DESC')
            ->orderByRaw('MAX(form_1702_ex_batch_rows.id) DESC')
            ->paginate(self::COMPANIES_PER_PAGE, ['companies.*'], 'page', $page)
            ->withQueryString();
    }

    /**
     * @param  Collection<int, Form1702ExBatchRow>  $rows
     * @param  Collection<int, Company>  $companies
     * @return list<array{
     *     id: string,
     *     name: string,
     *     tin: string,
     *     completedCount: int,
     *     recipientEmails: list<string>,
     *     statusLabel: string,
     *     statusVariant: string,
     *     files: list<array{
     *         id: string,
     *         fileName: string,
     *         generatedAt: string|null,
     *         previewUrl: string|null,
     *         downloadUrl: string|null
     *     }>
     * }>
     */
    private function transformCompanyGroups(Collection $rows, Collection $companies): array
    {
        $rowsByCompanyId = $rows
            ->groupBy('company_id');

        return $companies
            ->map(function (Company $company) use ($rowsByCompanyId): array {
                /** @var Collection<int, Form1702ExBatchRow> $companyRows */
                $companyRows = $rowsByCompanyId->get($company->id, collect())->values();
                [$statusLabel, $statusVariant, $statusClass] = $this->companyStatus($companyRows);

                return [
                    'id' => $company->uuid,
                    'name' => $company->name,
                    'tin' => $company->tin ?? '',
                    'completedCount' => $companyRows->filter(fn (Form1702ExBatchRow $row): bool => $this->isCompletedRow($row))->count(),
                    'recipientEmails' => $companyRows
                        ->map(fn (Form1702ExBatchRow $row): ?string => $this->form1702ExCompletedEmailService->recipientEmail($row))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'statusLabel' => $statusLabel,
                    'statusVariant' => $statusVariant,
                    'statusClass' => $statusClass,
                    'files' => $companyRows
                        ->map(fn (Form1702ExBatchRow $row): array => [
                            'id' => $row->uuid,
                            'fileName' => filled($row->generated_pdf_file_name)
                                ? (string) $row->generated_pdf_file_name
                                : 'Not generated yet',
                            'generatedAt' => $row->generated_at?->toIso8601String(),
                            'previewUrl' => filled($row->generated_pdf_storage_path)
                                ? route('forms.form1702ex.rows.preview', [
                                    'form1702ExBatchRow' => $row,
                                    'v' => $this->form1702ExService->previewVersion($row),
                                ])
                                : null,
                            'downloadUrl' => filled($row->generated_pdf_storage_path)
                                ? route('forms.form1702ex.rows.download', [
                                    'form1702ExBatchRow' => $row,
                                ])
                                : null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    private function applyCompleted1702ExScope($query): void
    {
        $query
            ->whereNull('duplicate_resolution_status')
            ->where('pdf_status', Form1702ExBatchRow::PDF_STATUS_GENERATED)
            ->whereNotNull('generated_pdf_storage_path')
            ->whereNotNull('receipt_storage_path')
            ->whereNotNull('receipt_file_name')
            ->where('receipt_is_temporary', false);
    }

    private function isCompletedRow(Form1702ExBatchRow $row): bool
    {
        return $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_GENERATED
            && filled($row->generated_pdf_storage_path)
            && filled($row->receipt_storage_path)
            && filled($row->receipt_file_name)
            && ! $row->receipt_is_temporary;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Form1702ExBatchRow>  $rows
     * @return array{0: string, 1: string}
     */
    private function companyStatus($rows): array
    {
        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $this->isCompletedRow($row))) {
            return ['Completed', 'outline', 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'];
        }

        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_PROCESSING)) {
            return ['Processing', 'outline', 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300'];
        }

        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_QUEUED)) {
            return ['Queued', 'outline', 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'];
        }

        if ($rows->contains(fn (Form1702ExBatchRow $row): bool => $row->pdf_status === Form1702ExBatchRow::PDF_STATUS_FAILED)) {
            return ['Failed', 'destructive', ''];
        }

        return ['Pending', 'outline', 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-500/30 dark:bg-slate-500/10 dark:text-slate-300'];
    }
}
