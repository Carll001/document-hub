<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (
            Schema::hasTable('merged_pdfs')
            && ! Schema::hasColumn('merged_pdfs', 'doc_merge_batch_id')
        ) {
            Schema::table('merged_pdfs', function (Blueprint $table) {
                $table->foreignId('doc_merge_batch_id')
                    ->nullable()
                    ->after('user_id');
            });

            if (Schema::hasTable('doc_merge_batches')) {
                Schema::table('merged_pdfs', function (Blueprint $table) {
                    $table->foreign('doc_merge_batch_id')
                        ->references('id')
                        ->on('doc_merge_batches')
                        ->nullOnDelete();
                });
            }
        }

        if (
            Schema::hasTable('bulk_merge_failures')
            && ! Schema::hasColumn('bulk_merge_failures', 'doc_merge_batch_id')
        ) {
            Schema::table('bulk_merge_failures', function (Blueprint $table) {
                $table->foreignId('doc_merge_batch_id')
                    ->nullable()
                    ->after('user_id');
            });

            if (Schema::hasTable('doc_merge_batches')) {
                Schema::table('bulk_merge_failures', function (Blueprint $table) {
                    $table->foreign('doc_merge_batch_id')
                        ->references('id')
                        ->on('doc_merge_batches')
                        ->nullOnDelete();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (
            Schema::hasTable('bulk_merge_failures')
            && Schema::hasColumn('bulk_merge_failures', 'doc_merge_batch_id')
        ) {
            Schema::table('bulk_merge_failures', function (Blueprint $table) {
                $table->dropColumn('doc_merge_batch_id');
            });
        }

        if (
            Schema::hasTable('merged_pdfs')
            && Schema::hasColumn('merged_pdfs', 'doc_merge_batch_id')
        ) {
            Schema::table('merged_pdfs', function (Blueprint $table) {
                $table->dropColumn('doc_merge_batch_id');
            });
        }
    }
};
