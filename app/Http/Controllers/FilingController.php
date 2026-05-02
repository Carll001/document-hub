<?php

declare(strict_types=1);

// App/Http/Controllers/FilingController.php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BasePagination;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\DocumentGeneratorTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FilingController extends Controller
{
    use BasePagination;

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
            ->paginate(5, ['*'], 'page', $page)
            ->withQueryString();

        return Inertia::render('filing/Index', [
            'routes' => [
                'index' => route('filing.index'),
            ],
            'companies' => [
                'data' => CompanyResource::collection($pageResult)->resolve(),
                'pagination' => $this->basePagination($pageResult),
            ],
            'filters' => [
                'search' => $search,
                'perPage' => 5,
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
}
