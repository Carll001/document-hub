<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DocumentGeneratorTemplate;
use App\Support\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'in:template_name,updated_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'perPage' => ['nullable', 'integer', 'in:10,25,50,100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $page = (int) ($validated['page'] ?? 1);
        $sort = (string) ($validated['sort'] ?? 'updated_at');
        $direction = (string) ($validated['direction'] ?? 'desc');
        $perPage = (int) ($validated['perPage'] ?? 10);

        $query = DocumentGeneratorTemplate::query();

        if ($search !== '') {
            $query->where('template_name', 'like', '%' . $search . '%');
        }

        $sortColumn = $sort === 'template_name' ? 'template_name' : 'updated_at';
        $sortDirection = $direction === 'asc' ? 'asc' : 'desc';

        $pageResult = $query
            ->orderBy($sortColumn, $sortDirection)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        $templates = collect($pageResult->items())
            ->map(function (DocumentGeneratorTemplate $template): array {
                return [
                    'id' => (int) $template->id,
                    'name' => (string) $template->template_name,
                    'form' => 'AFS',
                    'description' => $template->year === null
                        ? 'Default AFS template'
                        : 'AFS template for specific year',
                    'status' => 'Active',
                    'last_modified' => $template->updated_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('template/Index', [
            'templates' => [
                'data' => $templates,
                'pagination' => [
                    'current_page' => $pageResult->currentPage(),
                    'last_page' => $pageResult->lastPage(),
                    'per_page' => $pageResult->perPage(),
                    'total' => $pageResult->total(),
                    'from' => $pageResult->firstItem() ?? 0,
                    'to' => $pageResult->lastItem() ?? 0,
                ],
            ],
            'filters' => [
                'search' => $search,
                'sort' => $sortColumn,
                'direction' => $sortDirection,
                'perPage' => $perPage,
            ],
            'routes' => [
                'index' => route('template.index'),
                'store' => route('template.store'),
                'bulkDelete' => route('template.destroy-many'),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'filing_type' => ['required', 'string', 'in:afs,1702ex'],
            'template_file' => ['required', 'file', 'max:15360'],
        ]);

        if ($validated['filing_type'] === 'afs') {
            $request->validate([
                'template_file' => ['required', 'file', 'mimes:docx', 'max:15360'],
            ]);
        } else {
            return to_route('template.index')
                ->with('error', '1702EX template upload is not enabled yet.');
        }

        $path = $validated['template_file']->store(
            'document-generator/global-templates',
            DocumentStorage::diskName(),
        );

        DocumentGeneratorTemplate::query()->updateOrCreate(
            ['year' => null],
            [
                'template_name' => trim((string) $validated['name']),
                'template_path' => $path,
            ],
        );

        return to_route('template.index')
            ->with('success', 'Template uploaded successfully.');
    }

    public function data(DocumentGeneratorTemplate $template): JsonResponse
    {
        return response()->json([
            'id' => (int) $template->id,
            'name' => (string) $template->template_name,
            'year' => $template->year,
            'form' => 'AFS',
            'template_path' => (string) $template->template_path,
            'created_at' => $template->created_at?->toDateTimeString(),
            'updated_at' => $template->updated_at?->toDateTimeString(),
        ], 200, [], JSON_PRETTY_PRINT);
    }

    public function destroy(DocumentGeneratorTemplate $template): RedirectResponse
    {
        $path = trim((string) $template->template_path);
        if ($path !== '') {
            DocumentStorage::disk()->delete($path);
        }

        $template->delete();

        return to_route('template.index')
            ->with('success', 'Template deleted.');
    }

    public function destroyMany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:document_generator_templates,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        $templates = DocumentGeneratorTemplate::query()
            ->whereIn('id', $ids)
            ->get();

        foreach ($templates as $template) {
            $path = trim((string) $template->template_path);
            if ($path !== '') {
                DocumentStorage::disk()->delete($path);
            }
        }

        DocumentGeneratorTemplate::query()
            ->whereIn('id', $ids)
            ->delete();

        return to_route('template.index')
            ->with('success', 'Selected templates deleted.');
    }
}
