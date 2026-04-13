<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailSyncAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmailSyncAccountManagementController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('MailboxAccounts', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'storeUrl' => route('mailbox-accounts.store'),
            'bulkDestroyUrl' => route('mailbox-accounts.destroy-many'),
            'accounts' => $this->accountPayload(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateAccount($request, null, true);

        EmailSyncAccount::query()->create($validated);

        return to_route('mailbox-accounts.index')
            ->with('success', 'Mailbox account created successfully.');
    }

    public function update(Request $request, EmailSyncAccount $emailSyncAccount): RedirectResponse
    {
        $validated = $this->validateAccount($request, $emailSyncAccount, false);

        if (($validated['password'] ?? null) === null) {
            unset($validated['password']);
        }

        $emailSyncAccount->fill($validated)->save();

        return to_route('mailbox-accounts.index')
            ->with('success', "Updated {$emailSyncAccount->label()}.");
    }

    public function destroy(EmailSyncAccount $emailSyncAccount): RedirectResponse
    {
        $label = $emailSyncAccount->label();
        $emailSyncAccount->delete();

        return to_route('mailbox-accounts.index')
            ->with('success', "Deleted {$label}.");
    }

    public function destroyMany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', 'distinct'],
        ]);

        $accountIds = collect($validated['account_ids'])
            ->map(fn (mixed $accountId): int => (int) $accountId)
            ->unique()
            ->values();

        $accounts = EmailSyncAccount::query()
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->get();

        abort_unless($accounts->count() === $accountIds->count(), 404);

        $count = $accounts->count();
        $firstLabel = $accounts->first()?->label();

        $accounts->each->delete();

        return to_route('mailbox-accounts.index')
            ->with(
                'success',
                $count === 1
                    ? "Deleted {$firstLabel}."
                    : "Deleted {$count} mailbox accounts.",
            );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accountPayload(): array
    {
        return EmailSyncAccount::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmailSyncAccount $account): array => [
                'id' => $account->id,
                'displayName' => $account->display_name,
                'username' => $account->username,
                'host' => $account->host,
                'port' => $account->port,
                'encryption' => $account->encryption,
                'mailbox' => $account->mailbox,
                'validateCertificate' => $account->validate_certificate,
                'isActive' => $account->is_active,
                'createdAt' => $account->created_at?->toIso8601String(),
                'updatedAt' => $account->updated_at?->toIso8601String(),
                'syncedEmailCount' => $account->syncedEmails()->count(),
                'updateUrl' => route('mailbox-accounts.update', ['emailSyncAccount' => $account]),
                'deleteUrl' => route('mailbox-accounts.destroy', ['emailSyncAccount' => $account]),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAccount(
        Request $request,
        ?EmailSyncAccount $account,
        bool $passwordRequired,
    ): array {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => $passwordRequired
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'string', Rule::in(['ssl', 'tls', 'none'])],
            'mailbox' => ['required', 'string', 'max:255'],
            'validate_certificate' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        return [
            'display_name' => trim((string) $validated['display_name']),
            'username' => trim((string) $validated['username']),
            'password' => filled($validated['password'] ?? null)
                ? (string) $validated['password']
                : null,
            'host' => trim((string) $validated['host']),
            'port' => (int) $validated['port'],
            'encryption' => trim((string) $validated['encryption']),
            'mailbox' => trim((string) $validated['mailbox']),
            'validate_certificate' => (bool) $validated['validate_certificate'],
            'is_active' => (bool) $validated['is_active'],
        ];
    }
}
