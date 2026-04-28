<?php

declare(strict_types=1);

namespace App\Http\Controllers\AfsFiling;

use App\Http\Controllers\Controller;
use App\Models\AfsFilingItem;
use App\Models\DocumentGeneratorTemplate;
use App\Models\User;
use App\Services\AfsFiling\AfsFilingSignatureService;
use App\Services\DocumentGeneratorCompletedExportService;
use App\Support\DocumentStorage;
use App\Support\FormFieldAliasResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AfsFilingPageController extends Controller
{
    public function __construct(
        private readonly AfsFilingSignatureService $signatureService,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:uploadedAt,generatedAt,pdfStatus,sourceRowNumber,created_at,updated_at,status,row_number'],
            'direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,deleting,docx_done,pdf_done,failed'],
            'open_settings' => ['nullable', 'boolean'],
        ]);

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

        return Inertia::render('DocumentGenerator', [
            'initialItems' => $this->itemsPayload($user, [
                'page' => (int) ($validated['page'] ?? 1),
                'per_page' => (int) ($validated['per_page'] ?? 25),
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
                'per_page' => (int) ($validated['per_page'] ?? 25),
            ],
            'initialSignature' => $signatureEnabled ? $this->signatureService->payload($user) : ['signature' => null],
            'initialMapping' => $this->globalTemplateMappingPayload(),
            'openSettings' => (bool) ($validated['open_settings'] ?? false),
            'signatureEnabled' => $signatureEnabled,
        ]);
    }

    public function templateMapping(Request $request): RedirectResponse
    {
        return redirect()->route('afs-filing.index', ['open_settings' => 1]);
    }

    public function completed(Request $request, DocumentGeneratorCompletedExportService $completedExportService): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('GeneratedFiles', [
            'initialItems' => $this->itemsPayload($user, [
                'page' => (int) $request->integer('page', 1),
                'per_page' => (int) $request->integer('per_page', 25),
                'sort_by' => (string) $request->input('sort_by', 'updated_at'),
                'sort_direction' => (string) $request->input('sort_direction', 'desc'),
                'company_search' => $request->string('company_search')->toString(),
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
            $query->where('status', 'pdf_done')->whereNotNull('signature_applied_at');
        }

        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        if (is_string($filters['company_search'] ?? null) && trim((string) $filters['company_search']) !== '') {
            $search = mb_strtolower(trim((string) $filters['company_search']));
            $query->whereRaw('LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, "$.COMPANY")), "")) LIKE ?', ["%{$search}%"]);
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDirection = (string) ($filters['sort_direction'] ?? 'desc');
        $perPage = (int) ($filters['per_page'] ?? 25);

        $paginator = $query
            ->orderBy($sortBy, $sortDirection === 'asc' ? 'asc' : 'desc')
            ->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'current_page' => $paginator->currentPage(),
            'data' => $paginator->getCollection()->map(fn (AfsFilingItem $item): array => $this->itemPayload($item))->values()->all(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function itemPayload(AfsFilingItem $item): array
    {
        $rowData = is_array($item->row_data) ? $item->row_data : [];
        $company = FormFieldAliasResolver::resolveCompany($rowData, FormFieldAliasResolver::FORM_AFS);

        return [
            'id' => (int) $item->id,
            'row_number' => (int) $item->row_number,
            'company' => is_string($company) && trim($company) !== '' ? $company : '-',
            'tin' => FormFieldAliasResolver::resolveTin($rowData, FormFieldAliasResolver::FORM_AFS),
            'status' => (string) $item->status,
            'row_data' => $rowData,
            'docx_available' => is_string($item->docx_path) && $item->docx_path !== '' && DocumentStorage::disk()->exists($item->docx_path),
            'pdf_available' => is_string($item->pdf_path) && $item->pdf_path !== '' && DocumentStorage::disk()->exists($item->pdf_path),
            'signature_applied' => $item->signature_applied_at !== null,
            'signature_applied_at' => $item->signature_applied_at?->toIso8601String(),
            'error_message' => $item->error_message,
            'source_excel_name' => $item->source_excel_name,
            'template_name' => $item->template_name,
            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
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
