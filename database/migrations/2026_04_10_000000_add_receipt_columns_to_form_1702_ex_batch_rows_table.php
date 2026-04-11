<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->string('receipt_file_name')->nullable()->after('generated_at');
            $table->string('receipt_storage_path')->nullable()->after('receipt_file_name');
            $table->unsignedBigInteger('receipt_file_size')->nullable()->after('receipt_storage_path');
            $table->string('receipt_job_status', 20)->nullable()->after('receipt_file_size');
            $table->text('receipt_job_error')->nullable()->after('receipt_job_status');

            $table->index(['form_1702_ex_batch_id', 'receipt_job_status']);
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->dropIndex(['form_1702_ex_batch_id', 'receipt_job_status']);
            $table->dropColumn([
                'receipt_file_name',
                'receipt_storage_path',
                'receipt_file_size',
                'receipt_job_status',
                'receipt_job_error',
            ]);
        });
    }
};
