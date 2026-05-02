<?php

declare(strict_types=1);

// App/Http/Controllers/FilingController.php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BasePagination;
use App\Http\Requests\Filing\FilingAfsGenerateRequest;
use App\Http\Requests\Filing\FilingAfsOutputListRequest;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\Filing\FilingOutputResource;
use App\Jobs\Filing\DeleteFilingOutputJob;
use App\Jobs\AfsFiling\GenerateAfsFilingItemJob;
use App\Models\AfsFilingItem;
use App\Models\Company;
use App\Models\DocumentGeneratorTemplate;
use App\Models\FilingOutput;
use App\Support\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class FilingController extends Controller
{
    use BasePagination;
    private const AFS_FILING_WIZARD_SOURCE = 'Filing Wizard AFS';

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'step' => ['nullable', 'integer', 'min:1', 'max:4'],
            'companyId' => ['nullable', 'array'],
            'companyId.*' => ['integer', 'exists:companies,id'],
            'filingType' => ['nullable', 'string', 'in:afs,1702ex'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $page = (int) ($validated['page'] ?? 1);
        $step = (int) ($validated['step'] ?? 1);
        $selectedCompanyIds = array_values(array_unique(array_map('intval', $validated['companyId'] ?? [])));
        $selectedFilingType = $validated['filingType'] ?? null;
        $selectedCompanies = Company::query()
            ->whereIn('id', $selectedCompanyIds)
            ->get();

        $selectedCompanyPayload = CompanyResource::collection($selectedCompanies)->resolve();
        usort($selectedCompanyPayload, function (array $left, array $right) use ($selectedCompanyIds): int {
            $leftPos = array_search((int) $left['id'], $selectedCompanyIds, true);
            $rightPos = array_search((int) $right['id'], $selectedCompanyIds, true);

            return ((int) ($leftPos === false ? PHP_INT_MAX : $leftPos))
                <=> ((int) ($rightPos === false ? PHP_INT_MAX : $rightPos));
        });

        $query = Company::query();

        $afsDefaultTemplate = DocumentGeneratorTemplate::query()
            ->whereNull('year')
            ->latest('updated_at')
            ->first();
        $afsHasTemplate = $afsDefaultTemplate !== null;
        $templateOwnerLabel = $afsHasTemplate
            ? ((string) $afsDefaultTemplate->template_name)
            : 'No template configured';

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $like = '%' . $search . '%';
                $searchQuery->where('name', 'like', $like)
                    ->orWhere('tin', 'like', $like);
            });
        }

        $pageResult = $query->orderBy('name', 'asc')
            ->paginate(10, ['*'], 'page', $page)
            ->withQueryString();

        return Inertia::render('filing/Index', [
            'routes' => [
                'index' => route('filing.index'),
                'afsGenerate' => route('filing.afs.generate'),
                'afsOutputs' => route('filing.afs.outputs.index'),
            ],
            'companies' => [
                'data' => CompanyResource::collection($pageResult)->resolve(),
                'pagination' => $this->basePagination($pageResult),
            ],
            'filters' => [
                'search' => $search,
                'perPage' => 10,
            ],
            'currentStep' => $step,
            'selectedCompanyIds' => $selectedCompanyIds,
            'selectedFilingType' => $selectedFilingType,
            'selectedCompanies' => $selectedCompanyPayload,
            'filingTypeAvailability' => [
                'afs' => [
                    'hasTemplate' => $afsHasTemplate,
                    'ownerLabel' => $templateOwnerLabel,
                ],
                '1702ex' => [
                    'hasTemplate' => true,
                    'ownerLabel' => 'System template configured',
                ],
            ],
        ]);
    }

    public function myFilings(): Response
    {
        return Inertia::render('filing/MyFilings', [
            'routes' => [
                'index' => route('filing.my-filings'),
                'outputs' => route('filing.outputs.index'),
            ],
        ]);
    }

    public function outputs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'formType' => ['nullable', 'string', 'in:afs,1702ex'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $formType = $validated['formType'] ?? null;
        $status = trim((string) ($validated['status'] ?? ''));

        $rows = FilingOutput::query()
            ->when($formType !== null, fn ($query) => $query->where('form_type', $formType))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->get();

        $rows = collect(FilingOutputResource::collection($rows)->resolve())->values();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(
                fn (array $row): bool => str_contains(mb_strtolower((string) $row['name']), $needle)
                    || str_contains(mb_strtolower((string) $row['tin']), $needle)
                    || str_contains(mb_strtolower((string) $row['status']), $needle)
                    || str_contains(mb_strtolower((string) $row['form_type']), $needle)
            )->values();
        }

        return response()->json([
            'data' => $rows->all(),
            'total' => $rows->count(),
        ]);
    }

    public function generateAfs(FilingAfsGenerateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $companyIds = array_values(array_unique(array_map('intval', $validated['companyId'] ?? [])));

        $companies = Company::query()
            ->whereIn('id', $companyIds)
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            return response()->json([
                'message' => 'No companies selected for AFS generation.',
            ], 422);
        }

        $currentMaxRowNumber = (int) (AfsFilingItem::query()->max('row_number') ?? 0);
        $queuedCount = 0;

        foreach ($companies as $index => $company) {
            $rowData = is_array($company->data) ? $company->data : [];
            $rowData['company'] = (string) $company->name;
            $rowData['company name'] = (string) $company->name;
            $rowData['registered name'] = (string) $company->name;
            $rowData['tin'] = (string) $company->tin;
            $rowData['__company_id'] = (string) $company->id;
            $rowData['__flow'] = 'filing_step4_afs';

            $filingOutput = FilingOutput::query()->create([
                'company_id' => (int) $company->id,
                'company_name' => (string) $company->name,
                'tin' => (string) $company->tin,
                'form_type' => 'afs',
                'status' => 'queued',
                'file_name' => null,
                'file_path' => null,
                'error_message' => null,
            ]);

            $rowData['__filing_output_id'] = (string) $filingOutput->id;

            $item = AfsFilingItem::query()->create([
                'user_id' => (int) $request->user()->getKey(),
                'row_number' => $currentMaxRowNumber + $index + 1,
                'row_data' => $rowData,
                'status' => 'queued',
                'source_excel_name' => self::AFS_FILING_WIZARD_SOURCE,
            ]);

            GenerateAfsFilingItemJob::dispatch((int) $item->id);
            $queuedCount++;
        }

        return response()->json([
            'message' => "Queued {$queuedCount} AFS generation task(s).",
            'queued_count' => $queuedCount,
        ], 202);
    }

    public function afsOutputs(FilingAfsOutputListRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $companyIds = array_values(array_unique(array_map('intval', $validated['companyId'] ?? [])));
        $search = trim((string) ($validated['search'] ?? ''));

        $rows = FilingOutput::query()
            ->where('form_type', 'afs')
            ->when($companyIds !== [], fn ($query) => $query->whereIn('company_id', $companyIds))
            ->latest('id')
            ->get();

        $rows = collect(FilingOutputResource::collection($rows)->resolve())->values();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = $rows->filter(
                fn (array $row): bool => str_contains(mb_strtolower((string) $row['name']), $needle)
                    || str_contains(mb_strtolower((string) $row['tin']), $needle)
                    || str_contains(mb_strtolower((string) $row['status']), $needle)
            )->values();
        }

        return response()->json([
            'data' => $rows->all(),
            'total' => $rows->count(),
        ]);
    }

    public function afsOutputPreview(FilingOutput $item): StreamedResponse|BinaryFileResponse
    {
        $this->assertAfsOutputOwnership($item);

        $pdfPath = (string) ($item->file_path ?? '');
        abort_unless($pdfPath !== '' && DocumentStorage::disk()->exists($pdfPath), 404);

        return DocumentStorage::disk()->response($pdfPath, $this->afsOutputFileName($item), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->afsOutputFileName($item).'"',
        ]);
    }

    public function afsOutputDownload(FilingOutput $item): StreamedResponse|BinaryFileResponse
    {
        $this->assertAfsOutputOwnership($item);

        $pdfPath = (string) ($item->file_path ?? '');
        abort_unless($pdfPath !== '' && DocumentStorage::disk()->exists($pdfPath), 404);

        return DocumentStorage::disk()->download($pdfPath, $this->afsOutputFileName($item));
    }

    public function afsOutputDelete(FilingOutput $item): JsonResponse
    {
        $this->assertAfsOutputOwnership($item);

        if (in_array((string) $item->status, ['processing', 'deleting'], true)) {
            return response()->json([
                'message' => 'This output cannot be deleted right now.',
            ], 422);
        }

        $item->status = 'deleting';
        $item->save();

        DeleteFilingOutputJob::dispatch((int) $item->id);

        return response()->json([
            'message' => 'Output deletion queued.',
            'status' => 'deleting',
        ], 202);
    }

    public function afsOutputRegenerate(Request $request, FilingOutput $item): JsonResponse
    {
        $this->assertAfsOutputOwnership($item);

        if (in_array((string) $item->status, ['processing', 'deleting'], true)) {
            return response()->json([
                'message' => 'This output cannot be regenerated right now.',
            ], 422);
        }

        $company = Company::query()->find($item->company_id);
        if (! $company instanceof Company) {
            return response()->json([
                'message' => 'Company for this output was not found.',
            ], 422);
        }

        $currentMaxRowNumber = (int) (AfsFilingItem::query()->max('row_number') ?? 0);
        $rowData = is_array($company->data) ? $company->data : [];
        $rowData['company'] = (string) $company->name;
        $rowData['company name'] = (string) $company->name;
        $rowData['registered name'] = (string) $company->name;
        $rowData['tin'] = (string) $company->tin;
        $rowData['__company_id'] = (string) $company->id;
        $rowData['__flow'] = 'filing_step4_afs';
        $rowData['__filing_output_id'] = (string) $item->id;

        $item->status = 'queued';
        $item->error_message = null;
        $item->file_path = null;
        $item->file_name = null;
        $item->save();

        $afsItem = AfsFilingItem::query()->create([
            'user_id' => (int) $request->user()->getKey(),
            'row_number' => $currentMaxRowNumber + 1,
            'row_data' => $rowData,
            'status' => 'queued',
            'source_excel_name' => self::AFS_FILING_WIZARD_SOURCE,
        ]);

        GenerateAfsFilingItemJob::dispatch((int) $afsItem->id);

        return response()->json([
            'message' => 'Regeneration queued.',
            'status' => 'queued',
        ], 202);
    }

    private function assertAfsOutputOwnership(FilingOutput $item): void
    {
        abort_unless((string) $item->form_type === 'afs', 404);
    }

    private function afsOutputFileName(FilingOutput $item): string
    {
        $saved = trim((string) ($item->file_name ?? ''));
        if ($saved !== '') {
            return $saved;
        }

        $company = trim((string) $item->company_name);
        $base = $company !== '' ? $company : 'afs-output-'.$item->id;

        $normalized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $normalized = is_string($normalized) ? trim($normalized, '-._') : '';

        return ($normalized !== '' ? $normalized : 'afs-output-'.$item->id).'.pdf';
    }
}
