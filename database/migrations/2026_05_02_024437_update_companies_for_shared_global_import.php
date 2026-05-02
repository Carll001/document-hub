<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique('companies_user_id_tin_normalized_unique');
            $table->dropForeign(['client_id']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->change();
            $table->string('address')->nullable()->after('tin_normalized');
            $table->boolean('imported_via_excel')->default(false)->after('address');
        });

        $this->deduplicateCompaniesByNameAndTin();

        Schema::table('companies', function (Blueprint $table): void {
            $table->unique(['name_normalized', 'tin_normalized'], 'companies_name_tin_normalized_unique');
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique('companies_name_tin_normalized_unique');
            $table->dropForeign(['client_id']);
            $table->dropColumn(['address', 'imported_via_excel']);
            $table->foreignId('client_id')->nullable(false)->change();
            $table->unique(['user_id', 'tin_normalized'], 'companies_user_id_tin_normalized_unique');
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    private function deduplicateCompaniesByNameAndTin(): void
    {
        $duplicates = DB::table('companies')
            ->select('name_normalized', 'tin_normalized', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('name_normalized', 'tin_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $duplicateIds = DB::table('companies')
                ->where('name_normalized', $duplicate->name_normalized)
                ->where('tin_normalized', $duplicate->tin_normalized)
                ->where('id', '!=', $duplicate->keep_id)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($duplicateIds === []) {
                continue;
            }

            DB::table('form_1702_ex_batch_rows')
                ->whereIn('company_id', $duplicateIds)
                ->update(['company_id' => (int) $duplicate->keep_id]);

            DB::table('companies')
                ->whereIn('id', $duplicateIds)
                ->delete();
        }
    }
};
