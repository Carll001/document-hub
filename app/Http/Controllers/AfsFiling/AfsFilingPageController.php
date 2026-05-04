<?php

declare(strict_types=1);

namespace App\Http\Controllers\AfsFiling;

use App\Http\Controllers\Controller;
use App\Http\Requests\AfsFiling\AfsFilingPageCompletedRequest;
use App\Http\Requests\AfsFiling\AfsFilingPageIndexRequest;
use App\Http\Resources\AfsFiling\AfsFilingItemResource;
use App\Models\AfsFilingItem;
use App\Models\DocumentGeneratorTemplate;
use App\Models\User;
use App\Services\AfsFiling\AfsFilingSignatureService;
use App\Services\AfsFiling\AfsFilingImportStateService;
use App\Services\DocumentGeneratorCompletedExportService;
use App\Support\DocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AfsFilingPageController extends Controller
{
    public function __construct(
        private readonly AfsFilingSignatureService $signatureService,
        private readonly AfsFilingImportStateService $importStateService,
    ) {}

    public function index(AfsFilingPageIndexRequest $request): Response
    {
        $validated = $request->validated();

        $sort = (string) ($validated['sort'] ?? 'uploadedAt');
        $sortBy = match ($sort) {
            'uploadedAt' => 'created_at',
            'generatedAt' => 'updated_at',
            'pdfStatus' => 'status',
            'sourceRowNumber' => 'row_number',
            default => $sort,
        };

        $direction = (string) ($validated['direction'] ?? 'desc');
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';

        /** @var User $user */
        $user = $request->user();
        $signatureEnabled = (bool) config('services.document_generator.signature_enabled', true);

        return Inertia::render('forms/afs/Index', [
            'initialItems' => $this->itemsPayload($user, [
                'page' => (int) ($validated['page'] ?? 1),
                'per_page' => (int) ($validated['per_page'] ?? 15),
                'sort_by' => $sortBy,
                'sort_direction' => $direction,
                'status' => $validated['status'] ?? null,
                'company_search' => $search !== '' ? $search : null,
                'unsigned_only' => true,
            ]),
            'initialFilters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'status' => (string) ($validated['status'] ?? 'all'),
                'per_page' => (int) ($validated['per_page'] ?? 15),
            ],
            'initialSignature' => $signatureEnabled ? $this->signatureService->payload($user) : ['signature' => null],
            'initialMapping' => $this->globalTemplateMappingPayload(),
            'initialImportState' => $this->importStateService->getState((int) $user->getKey()),
            'openSettings' => (bool) ($validated['open_settings'] ?? false),
            'signatureEnabled' => $signatureEnabled,
        ]);
    }

    public function templateMapping(Request $request): RedirectResponse
    {
        return redirect()->route('afs-filing.index', ['open_settings' => 1]);
    }

    public function completed(AfsFilingPageCompletedRequest $request, DocumentGeneratorCompletedExportService $completedExportService): Response
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('forms/afs/Completed', [
            'initialItems' => $this->itemsPayload($user, [
                'page' => (int) ($validated['page'] ?? 1),
                'per_page' => (int) ($validated['per_page'] ?? 15),
                'sort_by' => (string) ($validated['sort_by'] ?? 'updated_at'),
                'sort_direction' => (string) ($validated['sort_direction'] ?? 'desc'),
                'company_search' => isset($validated['company_search']) ? trim((string) $validated['company_search']) : '',
                'completed_only' => true,
            ]),
            'initialExportState' => $completedExportService->getState((int) $user->getKey()),
        ]);
    }

    public function updateGlobalDefaultTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_file' => ['required', 'file', 'mimes:docx'],
        ]);

        $file = $validated['template_file'];
        $path = $file->store('afs_filing/global-templates', DocumentStorage::diskName());

        $template = DocumentGeneratorTemplate::query()->updateOrCreate(
            ['year' => null],
            [
                'template_name' => $file->getClientOriginalName(),
                'template_path' => $path,
            ],
        );

        return response()->json($this->globalTemplateMappingPayload());
    }

    public function storeGlobalTemplate(Request $request): JsonResponse
    {
        abort(404);
    }

    public function updateGlobalTemplate(Request $request, DocumentGeneratorTemplate $template): JsonResponse
    {
        abort(404);
    }

    public function destroyGlobalTemplate(DocumentGeneratorTemplate $template): JsonResponse
    {
        abort(404);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function itemsPayload(User $user, array $filters): array
    {
        $query = AfsFilingItem::query()->where('user_id', (int) $user->getKey());

        if (($filters['unsigned_only'] ?? false) === true) {
            $query->whereNull('signature_applied_at');
        }

        if (($filters['completed_only'] ?? false) === true) {
            $query->where('status', 'signed')->whereNotNull('signature_applied_at');
        }

        $statusFilter = is_string($filters['status'] ?? null) ? trim((string) $filters['status']) : '';
        if ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        if (is_string($filters['company_search'] ?? null) && trim((string) $filters['company_search']) !== '') {
            $search = mb_strtolower(trim((string) $filters['company_search']));
            $query->whereRaw('LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, "$.COMPANY")), "")) LIKE ?', ["%{$search}%"]);
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDirection = (string) ($filters['sort_direction'] ?? 'desc');
        $perPage = (int) ($filters['per_page'] ?? 15);

        if ($statusFilter === '' && (($filters['completed_only'] ?? false) !== true)) {
            $query->orderByRaw("
                CASE status
                    WHEN 'failed' THEN 0
                    WHEN 'signing' THEN 1
                    WHEN 'deleting' THEN 2
                    WHEN 'processing' THEN 3
                    WHEN 'docx_done' THEN 4
                    WHEN 'queued' THEN 5
                    WHEN 'generated' THEN 6
                    WHEN 'signed' THEN 7
                    ELSE 99
                END ASC
            ");
        }

        $paginator = $query
            ->orderBy($sortBy, $sortDirection === 'asc' ? 'asc' : 'desc')
            ->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'current_page' => $paginator->currentPage(),
            'data' => AfsFilingItemResource::collection($paginator->getCollection())->resolve(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function globalTemplateMappingPayload(): array
    {
        $defaultTemplate = DocumentGeneratorTemplate::query()
            ->whereNull('year')
            ->latest('id')
            ->first();

        $yearTemplates = DocumentGeneratorTemplate::query()
            ->whereNotNull('year')
            ->orderBy('year')
            ->get();

        return [
            'default_template' => $defaultTemplate ? $this->globalTemplatePayload($defaultTemplate) : null,
            'year_templates' => $yearTemplates->map(fn (DocumentGeneratorTemplate $template): array => $this->globalTemplatePayload($template))->values()->all(),
        ];
    }

    private function globalTemplatePayload(DocumentGeneratorTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'year' => $template->year,
            'template_name' => (string) $template->template_name,
            'template_path' => (string) $template->template_path,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
