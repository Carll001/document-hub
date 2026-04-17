<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Mail\ClientCredentialsEmail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Form1702ExBatch;
use App\Models\Form1702ExBatchRow;
use App\Models\User;
use App\Services\EmailSync\BirReceiptAutoMatchService;
use App\Support\Form1702ExRecipientEmailNormalizer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Form1702ExBatchService
{
    private const UNASSIGNED_CLIENT_NAME = 'Unassigned Client';
    private const STORE_IMPORT_CHUNK_SIZE = 200;

    public function __construct(
        private readonly BirReceiptAutoMatchService $birReceiptAutoMatchService,
        private readonly Form1702ExRecipientEmailNormalizer $recipientEmailNormalizer,
    ) {
    }

    public function createBatch(User $user, string $name): Form1702ExBatch
    {
        $normalizedName = $this->normalizeBatchName($name);

        if ($normalizedName === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter a batch name.',
            ]);
        }

        return Form1702ExBatch::query()->create([
            'user_id' => $user->id,
            'name' => $normalizedName,
        ]);
    }

    /**
     * @param  array{
     *     sourceName: string,
     *     sourceType: string,
     *     importedAt: string,
     *     rows: list<array{rowNumber: int, payload: array<string, mixed>, recipientEmail?: string|null}>
     * }  $import
     * @return Collection<int, Form1702ExBatchRow>
     */
    public function storeImport(Form1702ExBatch $batch, array $import, bool $replaceExisting): Collection
    {
        $rows = collect($import['rows'] ?? [])
            ->filter(static fn (mixed $row): bool => is_array($row) && is_array($row['payload'] ?? null))
            ->values();

        if ($rows->isEmpty()) {
            throw new RuntimeException('The uploaded file did not contain any importable rows.');
        }

        $uploadedAt = Carbon::parse((string) ($import['importedAt'] ?? now()->toIso8601String()));
        $sourceName = (string) ($import['sourceName'] ?? '1702-ex-import');
        $sourceType = (string) ($import['sourceType'] ?? 'csv');

        return DB::transaction(function () use (
            $batch,
            $replaceExisting,
            $rows,
            $uploadedAt,
            $sourceName,
            $sourceType,
        ): Collection {
            if ($replaceExisting) {
                $batch->rows()->get()->each->delete();
            }

            /** @var Collection<int, Form1702ExBatchRow> $existingRows */
            $existingRows = Form1702ExBatchRow::query()
                ->whereHas('batch', fn ($query) => $query->where('user_id', $batch->user_id))
                ->get();

            /** @var Collection<int, Form1702ExBatchRow> $createdRows */
            $createdRows = collect();
            $tinsToSync = [];
            $clientCache = [];
            $companyCache = [];
            $newClientCredentialsByClientId = [];
            $credentialRecipientsByClientId = [];

            foreach ($rows->chunk(self::STORE_IMPORT_CHUNK_SIZE) as $chunk) {
                foreach ($chunk as $row) {
                    $payload = $row['payload'];
                    $recipientEmail = $this->recipientEmailNormalizer->normalize(
                        is_string($row['recipientEmail'] ?? null) ? $row['recipientEmail'] : null,
                    );
                    [$client, $company] = $this->resolveClientAndCompany($batch, $payload, $clientCache, $companyCache);
                    if ($client instanceof Client) {
                        $clientCredentials = $this->ensureClientLoginUser($client, $payload);

                        if ($clientCredentials !== null) {
                            $newClientCredentialsByClientId[$client->id] = $clientCredentials;
                        }

                        if ($recipientEmail !== null) {
                            $credentialRecipientsByClientId[$client->id] ??= [];
                            $credentialRecipientsByClientId[$client->id][$recipientEmail] = true;
                        }
                    }
                    $normalizedTin = $this->normalizeTin($payload['tin'] ?? null);
                    $receiptOwner = $normalizedTin !== null
                        ? $existingRows->first(fn (Form1702ExBatchRow $existingRow): bool => $this->rowOwnsReceiptForTin($existingRow, $normalizedTin))
                        : null;

                    $attributes = [
                        'form_1702_ex_batch_id' => $batch->id,
                        'client_id' => $client?->id,
                        'company_id' => $company?->id,
                        'source_name' => $sourceName,
                        'source_type' => $sourceType,
                        'source_row_number' => (int) ($row['rowNumber'] ?? 0),
                        'uploaded_at' => $uploadedAt,
                        'payload' => array_replace($payload, [
                            'receipt_acceptance_start_date' => $batch->receipt_acceptance_start_date?->toDateString(),
                        ]),
                        'completed_email_recipient' => $recipientEmail,
                        'generated_pdf_file_name' => null,
                        'generated_pdf_storage_path' => null,
                        'generated_pdf_file_size' => null,
                        'generated_at' => null,
                        'receipt_file_name' => null,
                        'receipt_storage_path' => null,
                        'receipt_file_size' => null,
                        'receipt_job_status' => null,
                        'receipt_job_error' => null,
                    ];

                    if ($receiptOwner instanceof Form1702ExBatchRow) {
                        $attributes = array_replace($attributes, [
                            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_FAILED,
                            'pdf_error' => 'Skipped duplicate TIN because an earlier row already has a receipt.',
                            'duplicate_resolution_status' => Form1702ExBatchRow::DUPLICATE_RESOLUTION_SKIPPED,
                            'duplicate_of_form_1702_ex_batch_row_id' => $receiptOwner->id,
                            'duplicate_resolved_at' => now(),
                        ]);
                    } else {
                        $attributes = array_replace($attributes, [
                            'pdf_status' => Form1702ExBatchRow::PDF_STATUS_QUEUED,
                            'pdf_error' => null,
                        ]);
                    }

                    $createdRows->push(
                        Form1702ExBatchRow::query()->create($attributes),
                    );

                    $existingRows->push($createdRows->last());

                    if ($normalizedTin !== null && ! ($receiptOwner instanceof Form1702ExBatchRow)) {
                        $tinsToSync[] = $normalizedTin;
                    }
                }
            }

            $this->birReceiptAutoMatchService->syncStoredEmailsForTins($tinsToSync);
            $this->dispatchNewClientCredentialEmails(
                $newClientCredentialsByClientId,
                $credentialRecipientsByClientId,
            );

            return $createdRows;
        });
    }

    private function normalizeBatchName(string $name): string
    {
        return (string) Str::of($name)->squish()->trim();
    }

    private function normalizeTin(mixed $tin): ?string
    {
        $digits = preg_replace('/\D+/', '', trim((string) $tin));
        $digits = is_string($digits) ? $digits : '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Client|null, 1: Company|null}
     */
    private function resolveClientAndCompany(
        Form1702ExBatch $batch,
        array $payload,
        array &$clientCache,
        array &$companyCache,
    ): array
    {
        $clientName = $this->normalizeName($payload['client_name'] ?? null) ?? self::UNASSIGNED_CLIENT_NAME;
        $clientNameKey = $this->normalizeNameKey($clientName);

        if (isset($clientCache[$clientNameKey]) && $clientCache[$clientNameKey] instanceof Client) {
            $client = $clientCache[$clientNameKey];
        } else {
            $client = Client::query()->firstOrCreate(
                [
                    'user_id' => $batch->user_id,
                    'name_normalized' => $clientNameKey,
                ],
                [
                    'name' => $clientName,
                ],
            );

            $clientCache[$clientNameKey] = $client;
        }

        $companyTin = $this->normalizeTin($payload['tin'] ?? null);
        $companyName = $this->normalizeName($payload['taxpayer_name'] ?? $payload['registered_name'] ?? null);

        if ($companyTin === null || $companyName === null) {
            return [$client, null];
        }

        if (isset($companyCache[$companyTin]) && $companyCache[$companyTin] instanceof Company) {
            $company = $companyCache[$companyTin];
        } else {
            $company = Company::query()
                ->where('user_id', $batch->user_id)
                ->where('tin_normalized', $companyTin)
                ->first();
            $companyCache[$companyTin] = $company;
        }

        if (! $company instanceof Company) {
            $company = Company::query()->create([
                'user_id' => $batch->user_id,
                'client_id' => $client->id,
                'name' => $companyName,
                'name_normalized' => $this->normalizeNameKey($companyName),
                'tin' => $companyTin,
                'tin_normalized' => $companyTin,
            ]);
            $companyCache[$companyTin] = $company;

            return [$client, $company];
        }

        if ($company->client_id !== $client->id) {
            $existingClientName = $company->client?->name ?? self::UNASSIGNED_CLIENT_NAME;

            if ($existingClientName === self::UNASSIGNED_CLIENT_NAME && $client->name !== self::UNASSIGNED_CLIENT_NAME) {
                $company->forceFill([
                    'client_id' => $client->id,
                    'name' => $companyName,
                    'name_normalized' => $this->normalizeNameKey($companyName),
                ])->save();

                $companyCache[$companyTin] = $company->fresh() ?? $company;

                return [$client, $companyCache[$companyTin]];
            }

            throw ValidationException::withMessages([
                'spreadsheet' => sprintf(
                    'TIN %s is already linked to client "%s". Use the same client_name for that company.',
                    $companyTin,
                    $existingClientName,
                ),
            ]);
        }

        if ($company->name !== $companyName) {
            $company->forceFill([
                'name' => $companyName,
                'name_normalized' => $this->normalizeNameKey($companyName),
            ])->save();
        }

        $companyCache[$companyTin] = $company;

        return [$client, $company];
    }

    private function normalizeName(mixed $value): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeNameKey(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private function rowOwnsReceiptForTin(Form1702ExBatchRow $row, string $normalizedTin): bool
    {
        if ($row->isSkippedDuplicate()) {
            return false;
        }

        $payload = is_array($row->payload) ? $row->payload : [];

        return $this->normalizeTin($payload['tin'] ?? null) === $normalizedTin
            && filled($row->receipt_storage_path)
            && filled($row->receipt_file_name)
            && ! $row->receipt_is_temporary;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{loginEmail: string, plainPassword: string}|null
     */
    private function ensureClientLoginUser(Client $client, array $payload): ?array
    {
        $client->loadMissing('loginUser');

        if ($client->loginUser instanceof User) {
            return null;
        }

        $sourceClientName = trim((string) ($payload['client_name'] ?? $client->name));

        if ($sourceClientName === '' || $client->name === self::UNASSIGNED_CLIENT_NAME) {
            return null;
        }

        $loginEmail = $this->baseClientLoginEmail($sourceClientName);

        if (User::query()->where('email', $loginEmail)->exists()) {
            Log::warning('Client login provisioning skipped because login email already exists.', [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'login_email' => $loginEmail,
            ]);

            return null;
        }

        $plainPassword = (string) Str::password(16);
        $loginUser = User::query()->create([
            'name' => $client->name,
            'email' => $loginEmail,
            'password' => Hash::make($plainPassword),
            'role' => UserRole::Client->value,
            'email_verified_at' => now(),
        ]);

        $client->forceFill([
            'login_user_id' => $loginUser->id,
        ])->save();

        return [
            'loginEmail' => $loginEmail,
            'plainPassword' => $plainPassword,
        ];
    }

    private function baseClientLoginEmail(string $clientName): string
    {
        $baseLocalPart = mb_strtolower(preg_replace('/\s+/u', '', $clientName) ?? '');
        $baseLocalPart = preg_replace('/[^a-z0-9._-]+/i', '', $baseLocalPart) ?? '';

        if ($baseLocalPart === '') {
            $baseLocalPart = 'client';
        }

        return "{$baseLocalPart}@analytica.ph";
    }

    /**
     * @param  array<int, array{loginEmail: string, plainPassword: string}>  $newClientCredentialsByClientId
     * @param  array<int, array<string, bool>>  $credentialRecipientsByClientId
     */
    private function dispatchNewClientCredentialEmails(
        array $newClientCredentialsByClientId,
        array $credentialRecipientsByClientId,
    ): void {
        if ($newClientCredentialsByClientId === []) {
            return;
        }

        $clients = Client::query()
            ->whereIn('id', array_keys($newClientCredentialsByClientId))
            ->get()
            ->keyBy('id');
        $loginUrl = route('login');

        foreach ($newClientCredentialsByClientId as $clientId => $credentials) {
            $client = $clients->get($clientId);

            if (! $client instanceof Client) {
                continue;
            }

            $recipientEmails = array_keys($credentialRecipientsByClientId[$clientId] ?? []);

            if ($recipientEmails === []) {
                Log::warning('Client login user created without credential recipients from import rows.', [
                    'client_id' => $clientId,
                    'client_name' => $client->name,
                    'login_email' => $credentials['loginEmail'],
                ]);

                continue;
            }

            foreach ($recipientEmails as $recipientEmail) {
                try {
                    Mail::to($recipientEmail)->queue(
                        (new ClientCredentialsEmail(
                            $client,
                            $credentials['loginEmail'],
                            $credentials['plainPassword'],
                            $loginUrl,
                        ))->afterCommit(),
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }
    }
}
