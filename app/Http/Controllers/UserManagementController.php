<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\BuildsPaginationPayload;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    use BuildsPaginationPayload;

    private const USERS_PER_PAGE = 25;

    /**
     * Show the paginated user management page.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $perPage = (int) ($validated['per_page'] ?? self::USERS_PER_PAGE);
        $page = (int) ($validated['page'] ?? 1);
        $userPage = $this->userPage($page, $perPage, $search);

        return Inertia::render('Users', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'indexUrl' => route('users.index'),
            'storeUrl' => route('users.store'),
            'bulkDestroyUrl' => route('users.destroy-many'),
            'filters' => [
                'search' => $search,
                'per_page' => $userPage->perPage(),
            ],
            'pagination' => $this->paginationPayload($userPage),
            ...$this->userPagePayload($userPage, $request->user()),
        ]);
    }

    /**
     * Return the next page of users for the management view.
     */
    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $perPage = (int) ($validated['per_page'] ?? self::USERS_PER_PAGE);

        return response()->json(
            $this->userPagePayload(
                $this->userPage((int) ($validated['cursor'] ?? 1), $perPage, $search),
                $request->user(),
            ),
        );
    }

    /**
     * Create a new managed user.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        User::query()->create([
            'name' => trim((string) $validated['name']),
            'email' => trim((string) $validated['email']),
            'role' => 'staff',
            'password' => Hash::make((string) $validated['password']),
        ]);

        return to_route('users.index')
            ->with('success', 'Staff account created successfully.');
    }

    /**
     * Update a managed user.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $manager = $request->user();

        abort_unless($manager->canManageUser($user), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $user->forceFill([
            'name' => trim((string) $validated['name']),
            'email' => trim((string) $validated['email']),
        ]);

        if (filled($validated['password'] ?? null)) {
            $user->password = Hash::make((string) $validated['password']);
        }

        $user->save();

        return to_route('users.index')
            ->with('success', "Updated {$user->name}.");
    }

    /**
     * Delete a managed user.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $manager = $request->user();

        abort_unless($manager->canManageUser($user), 404);

        $deletedUserName = $user->name;

        $user->delete();

        return to_route('users.index')
            ->with('success', "Deleted {$deletedUserName}.");
    }

    /**
     * Delete multiple managed users.
     */
    public function destroyMany(Request $request): RedirectResponse
    {
        $manager = $request->user();

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $userIds = collect($validated['user_ids'])
            ->map(fn (mixed $userId): int => (int) $userId)
            ->unique()
            ->values();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->whereIn('role', [UserRole::Staff, UserRole::Client])
            ->orderBy('id')
            ->get();

        abort_unless($users->count() === $userIds->count(), 404);
        abort_unless(
            $users->every(fn (User $user): bool => $manager->canManageUser($user)),
            404,
        );

        $deletedCount = $users->count();

        $users->each->delete();

        return to_route('users.index')
            ->with(
                'success',
                $deletedCount === 1
                    ? "Deleted {$users->first()->name}."
                    : "Deleted {$deletedCount} users.",
            );
    }

    private function userPage(int $page, int $perPage, string $search = ''): LengthAwarePaginator
    {
        return User::query()
            ->whereIn('role', [UserRole::Staff, UserRole::Client])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($innerQuery) use ($like): void {
                    $innerQuery
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }

    /**
     * @return array{
     *     users: array<int, array<string, mixed>>,
     *     hasMoreUsers: bool,
     *     nextUsersCursor: string|null
     * }
     */
    private function userPagePayload(
        LengthAwarePaginator $userPage,
        User $manager,
    ): array {
        return [
            'users' => collect($userPage->items())
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->value,
                    'roleLabel' => $user->role?->label(),
                    'createdAt' => $user->created_at?->toIso8601String(),
                    'updatedAt' => $user->updated_at?->toIso8601String(),
                    'canEdit' => $manager->canManageUser($user),
                    'updateUrl' => $manager->canManageUser($user)
                        ? route('users.update', ['user' => $user])
                        : null,
                    'deleteUrl' => $manager->canManageUser($user)
                        ? route('users.destroy', ['user' => $user])
                        : null,
                ])
                ->all(),
            'hasMoreUsers' => $userPage->hasMorePages(),
            'nextUsersCursor' => $userPage->hasMorePages()
                ? (string) ($userPage->currentPage() + 1)
                : null,
        ];
    }
}
