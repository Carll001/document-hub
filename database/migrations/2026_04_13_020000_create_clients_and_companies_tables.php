<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('name_normalized');
            $table->timestamps();

            $table->unique(['user_id', 'name_normalized']);
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('name_normalized');
            $table->string('tin');
            $table->string('tin_normalized');
            $table->timestamps();

            $table->unique(['user_id', 'tin_normalized']);
            $table->index(['client_id', 'name_normalized']);
        });

        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->foreignId('client_id')
                ->nullable()
                ->after('form_1702_ex_batch_id')
                ->constrained('clients')
                ->nullOnDelete();
            $table->foreignId('company_id')
                ->nullable()
                ->after('client_id')
                ->constrained('companies')
                ->nullOnDelete();
        });

        $this->backfillExistingRows();
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::dropIfExists('companies');
        Schema::dropIfExists('clients');
    }

    private function backfillExistingRows(): void
    {
        $rows = DB::table('form_1702_ex_batch_rows')
            ->join('form_1702_ex_batches', 'form_1702_ex_batches.id', '=', 'form_1702_ex_batch_rows.form_1702_ex_batch_id')
            ->select(
                'form_1702_ex_batch_rows.id',
                'form_1702_ex_batch_rows.payload',
                'form_1702_ex_batches.user_id',
            )
            ->orderBy('form_1702_ex_batch_rows.id')
            ->get();

        $clientIdsByUserAndName = [];
        $companyIdsByUserAndTin = [];
        $timestamp = now();

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            $payload = is_array($payload) ? $payload : [];
            $userId = (int) $row->user_id;

            $clientName = trim((string) ($payload['client_name'] ?? ''));
            if ($clientName === '') {
                $clientName = 'Unassigned Client';
            }

            $clientKey = $userId.'|'.$this->normalizeKey($clientName);

            if (! isset($clientIdsByUserAndName[$clientKey])) {
                $existingClient = DB::table('clients')
                    ->where('user_id', $userId)
                    ->where('name_normalized', $this->normalizeKey($clientName))
                    ->value('id');

                if ($existingClient === null) {
                    $existingClient = DB::table('clients')->insertGetId([
                        'user_id' => $userId,
                        'uuid' => (string) Str::uuid(),
                        'name' => $clientName,
                        'name_normalized' => $this->normalizeKey($clientName),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }

                $clientIdsByUserAndName[$clientKey] = (int) $existingClient;
            }

            $clientId = $clientIdsByUserAndName[$clientKey];
            $companyName = trim((string) ($payload['taxpayer_name'] ?? $payload['registered_name'] ?? ''));
            $tin = preg_replace('/\D+/', '', (string) ($payload['tin'] ?? ''));
            $tin = is_string($tin) ? $tin : '';

            if ($companyName === '' || $tin === '') {
                DB::table('form_1702_ex_batch_rows')
                    ->where('id', $row->id)
                    ->update([
                        'client_id' => $clientId,
                        'company_id' => null,
                    ]);

                continue;
            }

            $companyKey = $userId.'|'.$tin;

            if (! isset($companyIdsByUserAndTin[$companyKey])) {
                $existingCompany = DB::table('companies')
                    ->where('user_id', $userId)
                    ->where('tin_normalized', $tin)
                    ->value('id');

                if ($existingCompany === null) {
                    $existingCompany = DB::table('companies')->insertGetId([
                        'user_id' => $userId,
                        'client_id' => $clientId,
                        'uuid' => (string) Str::uuid(),
                        'name' => $companyName,
                        'name_normalized' => $this->normalizeKey($companyName),
                        'tin' => $tin,
                        'tin_normalized' => $tin,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }

                $companyIdsByUserAndTin[$companyKey] = (int) $existingCompany;
            }

            DB::table('form_1702_ex_batch_rows')
                ->where('id', $row->id)
                ->update([
                    'client_id' => $clientId,
                    'company_id' => $companyIdsByUserAndTin[$companyKey],
                ]);
        }
    }

    private function normalizeKey(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim(mb_strtolower($value)));

        return $normalized !== null && $normalized !== '' ? $normalized : 'unassigned-client';
    }
};
