<?php

declare(strict_types=1);

// App/Http/Controllers/FilingController.php

namespace App\Http\Controllers;

use App\Contracts\Repositories\FilingRepository as FilingRepositoryContract;
use App\Http\Controllers\Concerns\BasePagination;
use App\Http\Requests\Filing\FilingAfsGenerateRequest;
use App\Http\Requests\Filing\FilingAfsOutputListRequest;
use App\Http\Requests\Filing\FilingAfsOutputRegenerateRequest;
use App\Http\Requests\Filing\FilingIndexRequest;
use App\Http\Requests\Filing\FilingOutputsRequest;
use App\Http\Resources\CompanyResource;
use App\Jobs\AfsFiling\GenerateAfsFilingItemJob;
use App\Jobs\Filing\DeleteFilingOutputJob;
use App\Models\AfsFilingItem;
use App\Models\FilingOutput;
use App\Services\Filing\FilingService;
use App\Support\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilingController extends Controller
{
    use BasePagination;

    private const AFS_FILING_WIZARD_SOURCE = 'Filing Wizard AFS';

    public function __construct(
        private readonly FilingService $filingService,
        private readonly FilingRepositoryContract $filingRepository,
    ) {}

    public function index(FilingIndexRequest $request): Response
    {
        $validated = $request->validated();

        $search = trim((string) ($validated['search'] ?? ''));
        $page = (int) ($validated['page'] ?? 1);
        $step = (int) ($validated['step'] ?? 1);
        $selectedCompanyIds = array_values(array_unique(array_map('intval', $validated['companyId'] ?? [])));
        $selectedFilingType = $validated['filingType'] ?? null;
        $selectedCompanies = $this->filingRepository->getCompaniesByIds($selectedCompanyIds);

        $selectedCompanyPayload = CompanyResource::collection($selectedCompanies)->resolve();
        usort($selectedCompanyPayload, function (array $left, array $right) use ($selectedCompanyIds): int {
            $leftPos = array_search((int) $left['id'], $selectedCompanyIds, true);
            $rightPos = array_search((int) $right['id'], $selectedCompanyIds, true);

            return ((int) ($leftPos === false ? PHP_INT_MAX : $leftPos))
                <=> ((int) ($rightPos === false ? PHP_INT_MAX : $rightPos));
        });

        $afsDefaultTemplate = $this->filingRepository->findLatestAfsDefaultTemplate();
        $afsHasTemplate = $afsDefaultTemplate !== null;
        $templateOwnerLabel = $afsHasTemplate
            ? ((string) $afsDefaultTemplate->template_name)
            : 'No template configured';

        $pageResult = $this->filingRepository->paginateCompaniesForFilingIndex($search, $page, 10);

        return Inertia::render('filing/Index', [
            'routes' => [
                'index' => route('filing.index'),
                'afsGenerate' => route('filing.afs.generate'),
                'afsGetorPreview' => route('filing.afs.getor.preview'),
                'afsPresidentPreview' => route('filing.afs.president.preview'),
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
                'completed' => route('filing.completed'),
                'outputs' => route('filing.outputs.index'),
            ],
        ]);
    }

    public function completedFilings(): Response
    {
        return Inertia::render('filing/Completed', [
            'routes' => [
                'index' => route('filing.completed'),
                'myFilings' => route('filing.my-filings'),
                'outputs' => route('filing.outputs.index'),
            ],
        ]);
    }

    public function outputs(FilingOutputsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $search = trim((string) ($validated['search'] ?? ''));
        $formType = $validated['formType'] ?? null;
        $status = trim((string) ($validated['status'] ?? ''));

        $rows = $this->filingService->listOutputs($formType, $status, $search);

        return response()->json([
            'data' => $rows,
            'total' => count($rows),
        ]);
    }

    public function generateAfs(FilingAfsGenerateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $companyIds = array_values(array_unique(array_map('intval', $validated['companyId'] ?? [])));
        $signatureFiles = $request->file('presidentSignature', []);
        $getorSignatureFile = $request->file('getorSignature');
        $overwriteExisting = (bool) ($validated['overwriteExisting'] ?? false);
        $storedGetorSignaturePath = $this->storeGetorSignature($request->user()->getKey(), $getorSignatureFile);
        if (is_string($storedGetorSignaturePath) && trim($storedGetorSignaturePath) !== '') {
            $request->session()->put('filing_afs_last_getor_signature_path', $storedGetorSignaturePath);
        }
        $effectiveGetorSignaturePath = $storedGetorSignaturePath
            ?? $this->resolveSessionGetorSignaturePath($request)
            ?? $this->resolveLastStoredGetorSignaturePath((int) $request->user()->getKey());
        Log::info('AFS generate signatures resolved.', [
            'user_id' => (int) $request->user()->getKey(),
            'selected_companies' => $companyIds,
            'has_uploaded_getor' => $getorSignatureFile instanceof UploadedFile,
            'stored_getor_signature_path' => $storedGetorSignaturePath,
            'effective_getor_signature_path' => $effectiveGetorSignaturePath,
        ]);

        $companies = $this->filingRepository->getCompaniesByIds($companyIds)->sortBy('id')->values();

        if ($companies->isEmpty()) {
            return response()->json([
                'message' => 'No companies selected for AFS generation.',
            ], 422);
        }

        $existingGenerated = FilingOutput::query()
            ->where('form_type', 'afs')
            ->whereIn('company_id', $companies->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all())
            ->whereIn('status', ['generated', 'signed'])
            ->orderByDesc('id')
            ->get()
            ->groupBy('company_id')
            ->map(static fn ($group) => $group->first());

        if (! $overwriteExisting && $existingGenerated->isNotEmpty()) {
            return response()->json([
                'message' => 'Some selected companies already have generated AFS outputs.',
                'code' => 'existing_outputs_found',
                'conflicts' => $existingGenerated->map(static fn (FilingOutput $output): array => [
                    'output_id' => (int) $output->id,
                    'company_id' => (int) $output->company_id,
                    'company_name' => (string) $output->company_name,
                    'status' => (string) $output->status,
                ])->values()->all(),
            ], 409);
        }

        $startingRowNumber = $this->filingRepository->nextAfsRowNumber();
        $queuedCount = 0;

        foreach ($companies as $index => $company) {
            $presidentSignaturePath = $this->storePresidentSignature(
                $request->user()->getKey(),
                $company->id,
                $signatureFiles[(string) $company->id] ?? null,
            );
            if (! is_string($presidentSignaturePath) || trim($presidentSignaturePath) === '') {
                $presidentSignaturePath = $this->resolveLastStoredPresidentSignaturePath((int) $company->id);
            }
            Log::info('AFS company signature input resolved.', [
                'user_id' => (int) $request->user()->getKey(),
                'company_id' => (int) $company->id,
                'has_uploaded_president' => ($signatureFiles[(string) $company->id] ?? null) instanceof UploadedFile,
                'president_signature_path' => $presidentSignaturePath,
            ]);
            /** @var FilingOutput|null $existingOutput */
            $existingOutput = $existingGenerated->get((int) $company->id);
            if ($overwriteExisting && $existingOutput instanceof FilingOutput) {
                $staleOutputs = FilingOutput::query()
                    ->where('form_type', 'afs')
                    ->where('company_id', (int) $company->id)
                    ->where('id', '!=', (int) $existingOutput->id)
                    ->get();
                foreach ($staleOutputs as $staleOutput) {
                    $stalePdfPath = is_string($staleOutput->file_path) ? trim($staleOutput->file_path) : '';
                    if ($stalePdfPath !== '' && DocumentStorage::isValidPath($stalePdfPath) && DocumentStorage::disk()->exists($stalePdfPath)) {
                        DocumentStorage::disk()->delete($stalePdfPath);
                    }
                    $stalePresidentPath = is_string($staleOutput->president_signature_path) ? trim($staleOutput->president_signature_path) : '';
                    if ($stalePresidentPath !== '' && DocumentStorage::isValidPath($stalePresidentPath) && DocumentStorage::disk()->exists($stalePresidentPath)) {
                        DocumentStorage::disk()->delete($stalePresidentPath);
                    }
                    $staleOutput->delete();
                }

                $oldPdfPath = is_string($existingOutput->file_path) ? trim($existingOutput->file_path) : '';
                if ($oldPdfPath !== '' && DocumentStorage::isValidPath($oldPdfPath) && DocumentStorage::disk()->exists($oldPdfPath)) {
                    DocumentStorage::disk()->delete($oldPdfPath);
                }

                $oldPresidentSignaturePath = is_string($existingOutput->president_signature_path) ? trim($existingOutput->president_signature_path) : '';
                $filingOutput = $this->filingService->resetOutputForRegeneration($existingOutput);
                if ($presidentSignaturePath !== null) {
                    if (
                        $oldPresidentSignaturePath !== ''
                        && $oldPresidentSignaturePath !== $presidentSignaturePath
                        && DocumentStorage::isValidPath($oldPresidentSignaturePath)
                        && DocumentStorage::disk()->exists($oldPresidentSignaturePath)
                    ) {
                        DocumentStorage::disk()->delete($oldPresidentSignaturePath);
                    }
                    $filingOutput->president_signature_path = $presidentSignaturePath;
                } else {
                    $presidentSignaturePath = is_string($filingOutput->president_signature_path) && trim($filingOutput->president_signature_path) !== ''
                        ? $filingOutput->president_signature_path
                        : null;
                }
                if (is_string($effectiveGetorSignaturePath) && trim($effectiveGetorSignaturePath) !== '') {
                    $filingOutput->filing_signature = trim($effectiveGetorSignaturePath);
                }
                $this->filingRepository->saveFilingOutput($filingOutput);
            } else {
                $filingOutput = $this->filingRepository->createFilingOutput([
                    'company_id' => (int) $company->id,
                    'company_name' => (string) $company->name,
                    'tin' => (string) $company->tin,
                    'form_type' => 'afs',
                    'status' => 'queued',
                    'file_name' => null,
                    'file_path' => null,
                    'error_message' => null,
                    'president_signature_path' => $presidentSignaturePath,
                    'filing_signature' => $effectiveGetorSignaturePath,
                ]);
            }

            $item = $this->filingRepository->createAfsFilingItem([
                'user_id' => (int) $request->user()->getKey(),
                'row_number' => $startingRowNumber + $index,
                'row_data' => $this->filingService->buildAfsRowData(
                    $company,
                    (int) $filingOutput->id,
                    $presidentSignaturePath,
                    $effectiveGetorSignaturePath,
                ),
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

        $rows = $this->filingService->listAfsOutputs($companyIds, $search);

        return response()->json([
            'data' => $rows,
            'total' => count($rows),
        ]);
    }

    public function afsGetorSignaturePreview(Request $request): StreamedResponse|BinaryFileResponse
    {
        $path = $this->resolveSessionGetorSignaturePathFromRequest($request)
            ?? $this->resolveLastStoredGetorSignaturePath((int) $request->user()->getKey());

        abort_unless(is_string($path) && $path !== '' && DocumentStorage::disk()->exists($path), 404);

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return DocumentStorage::disk()->response($path, null, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function afsPresidentSignaturePreview(Request $request): StreamedResponse|BinaryFileResponse
    {
        $companyId = (int) $request->query('companyId', 0);
        abort_unless($companyId > 0, 404);

        $path = $this->resolveLastStoredPresidentSignaturePath($companyId);
        abort_unless(is_string($path) && $path !== '' && DocumentStorage::disk()->exists($path), 404);

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return DocumentStorage::disk()->response($path, null, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function afsOutputPreview(FilingOutput $item): StreamedResponse|BinaryFileResponse
    {
        $this->assertAfsOutputOwnership($item);

        $pdfPath = (string) ($item->file_path ?? '');
        abort_unless($this->filingService->outputFileExists($item), 404);

        return DocumentStorage::disk()->response($pdfPath, $this->filingService->afsOutputFileName($item), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->filingService->afsOutputFileName($item).'"',
        ]);
    }

    public function afsOutputDownload(FilingOutput $item): StreamedResponse|BinaryFileResponse
    {
        $this->assertAfsOutputOwnership($item);

        $pdfPath = (string) ($item->file_path ?? '');
        abort_unless($this->filingService->outputFileExists($item), 404);

        return DocumentStorage::disk()->download($pdfPath, $this->filingService->afsOutputFileName($item));
    }

    public function afsOutputDelete(FilingOutput $item): JsonResponse
    {
        $this->assertAfsOutputOwnership($item);

        if (in_array((string) $item->status, ['processing', 'deleting'], true)) {
            return response()->json([
                'message' => 'This output cannot be deleted right now.',
            ], 422);
        }

        $item = $this->filingService->queueOutputForDeletion($item);

        DeleteFilingOutputJob::dispatch((int) $item->id);

        return response()->json([
            'message' => 'Output deletion queued.',
            'status' => 'deleting',
        ], 202);
    }

    public function afsOutputRegenerate(FilingAfsOutputRegenerateRequest $request, FilingOutput $item): JsonResponse
    {
        $this->assertAfsOutputOwnership($item);

        if (in_array((string) $item->status, ['processing', 'deleting'], true)) {
            return response()->json([
                'message' => 'This output cannot be regenerated right now.',
            ], 422);
        }

        $company = $this->filingRepository->findCompanyById((int) $item->company_id);
        if ($company === null) {
            return response()->json([
                'message' => 'Company for this output was not found.',
            ], 422);
        }

        $item = $this->filingService->resetOutputForRegeneration($item);

        $afsItem = $this->filingRepository->createAfsFilingItem([
            'user_id' => (int) $request->user()->getKey(),
            'row_number' => $this->filingRepository->nextAfsRowNumber(),
            'row_data' => $this->filingService->buildAfsRowData(
                $company,
                (int) $item->id,
                is_string($item->president_signature_path ?? null) ? $item->president_signature_path : null,
                null,
            ),
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

    private function storePresidentSignature(int|string $userId, int|string $companyId, mixed $file): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        return $file->store(
            "afs_filing/{$userId}/wizard-pres-signatures/{$companyId}",
            DocumentStorage::diskName(),
        );
    }

    private function storeGetorSignature(int|string $userId, mixed $file): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        return $file->store(
            "afs_filing/{$userId}/wizard-getor-signature",
            DocumentStorage::diskName(),
        );
    }

    private function resolveLastStoredGetorSignaturePath(int $userId): ?string
    {
        $latestItems = AfsFilingItem::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->limit(100)
            ->get();

        foreach ($latestItems as $item) {
            $rowData = is_array($item->row_data) ? $item->row_data : [];
            $path = $rowData['__getor_signature_path'] ?? null;
            if (! is_string($path)) {
                continue;
            }

            $resolved = trim($path);
            if ($resolved === '') {
                continue;
            }

            if (! DocumentStorage::isValidPath($resolved)) {
                continue;
            }

            if (DocumentStorage::disk()->exists($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveLastStoredPresidentSignaturePath(int $companyId): ?string
    {
        $path = FilingOutput::query()
            ->where('form_type', 'afs')
            ->where('company_id', $companyId)
            ->whereNotNull('president_signature_path')
            ->orderByDesc('id')
            ->value('president_signature_path');

        if (! is_string($path)) {
            return null;
        }

        $resolved = trim($path);
        if ($resolved === '' || ! DocumentStorage::isValidPath($resolved)) {
            return null;
        }

        return DocumentStorage::disk()->exists($resolved) ? $resolved : null;
    }

    private function resolveSessionGetorSignaturePath(FilingAfsGenerateRequest $request): ?string
    {
        $path = $request->session()->get('filing_afs_last_getor_signature_path');
        if (! is_string($path)) {
            return null;
        }

        $resolved = trim($path);
        if ($resolved === '' || ! DocumentStorage::isValidPath($resolved)) {
            return null;
        }

        return DocumentStorage::disk()->exists($resolved) ? $resolved : null;
    }

    private function resolveSessionGetorSignaturePathFromRequest(Request $request): ?string
    {
        $path = $request->session()->get('filing_afs_last_getor_signature_path');
        if (! is_string($path)) {
            return null;
        }

        $resolved = trim($path);
        if ($resolved === '' || ! DocumentStorage::isValidPath($resolved)) {
            return null;
        }

        return DocumentStorage::disk()->exists($resolved) ? $resolved : null;
    }
}
