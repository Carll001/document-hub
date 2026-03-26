<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('bulk_merge_failures')) {
            return;
        }

        $hasInputMode = Schema::hasColumn('bulk_merge_failures', 'input_mode');
        $hasInputLabel = Schema::hasColumn('bulk_merge_failures', 'input_label');
        $hasGroupLabel = Schema::hasColumn('bulk_merge_failures', 'group_label');

        if (! $hasInputMode || ! $hasInputLabel || ! $hasGroupLabel) {
            Schema::table('bulk_merge_failures', function (Blueprint $table) use ($hasInputMode, $hasInputLabel, $hasGroupLabel) {
                if (! $hasInputMode) {
                    $table->string('input_mode', 20)->nullable()->after('user_id');
                }

                if (! $hasInputLabel) {
                    $table->text('input_label')->nullable()->after('input_mode');
                }

                if (! $hasGroupLabel) {
                    $table->string('group_label')->nullable()->after('input_label');
                }
            });
        }

        $hasLegacyArchiveFileName = Schema::hasColumn('bulk_merge_failures', 'archive_file_name');
        $hasLegacyFolderName = Schema::hasColumn('bulk_merge_failures', 'folder_name');

        DB::table('bulk_merge_failures')
            ->orderBy('id')
            ->get()
            ->each(function (object $row) use ($hasLegacyArchiveFileName, $hasLegacyFolderName): void {
                $updates = [];

                if (! isset($row->input_mode) || $row->input_mode === null || $row->input_mode === '') {
                    $updates['input_mode'] = 'zip';
                }

                if (! isset($row->input_label) || $row->input_label === null || $row->input_label === '') {
                    $updates['input_label'] = $hasLegacyArchiveFileName && isset($row->archive_file_name)
                        ? $row->archive_file_name
                        : ($row->output_file_name ?? 'Bulk merge');
                }

                if (! isset($row->group_label) || $row->group_label === null || $row->group_label === '') {
                    $updates['group_label'] = $hasLegacyFolderName && isset($row->folder_name)
                        ? $row->folder_name
                        : ($row->output_file_name ?? 'Unknown PDF');
                }

                if ($updates !== []) {
                    DB::table('bulk_merge_failures')
                        ->where('id', $row->id)
                        ->update($updates);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('bulk_merge_failures')) {
            return;
        }

        Schema::table('bulk_merge_failures', function (Blueprint $table) {
            $columnsToDrop = [];

            foreach (['input_mode', 'input_label', 'group_label'] as $column) {
                if (Schema::hasColumn('bulk_merge_failures', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
