<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->addUuidColumn('doc_merge_batches');
        $this->addUuidColumn('merged_pdfs');
        $this->addUuidColumn('bulk_merge_failures');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropUuidColumn('bulk_merge_failures');
        $this->dropUuidColumn('merged_pdfs');
        $this->dropUuidColumn('doc_merge_batches');
    }

    private function addUuidColumn(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'uuid')) {
            Schema::table($table, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });
        }

        DB::table($table)
            ->orderBy('id')
            ->select(['id', 'uuid'])
            ->chunkById(200, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    if (filled($row->uuid)) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        if (! Schema::hasIndex($table, "{$table}_uuid_unique")) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }
    }

    private function dropUuidColumn(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $indexName = "{$table}_uuid_unique";

            if (Schema::hasIndex($table, $indexName)) {
                $blueprint->dropUnique($indexName);
            }

            $blueprint->dropColumn('uuid');
        });
    }
};
