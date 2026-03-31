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
        Schema::table('doc_merge_batches', function (Blueprint $table): void {
            $table->string('processing_status')->nullable()->after('last_processed_at');
            $table->string('processing_error')->nullable()->after('processing_status');
        });

        Schema::table('merged_pdfs', function (Blueprint $table): void {
            $table->string('receipt_job_status')->nullable()->after('receipt_file_size');
            $table->string('receipt_job_error')->nullable()->after('receipt_job_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_pdfs', function (Blueprint $table): void {
            $table->dropColumn([
                'receipt_job_status',
                'receipt_job_error',
            ]);
        });

        Schema::table('doc_merge_batches', function (Blueprint $table): void {
            $table->dropColumn([
                'processing_status',
                'processing_error',
            ]);
        });
    }
};
