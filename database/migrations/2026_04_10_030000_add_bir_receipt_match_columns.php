<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('synced_emails', function (Blueprint $table): void {
            $table->string('bir_receipt_file_name')->nullable()->after('body_html');
            $table->string('bir_receipt_date_received_by_bir', 120)->nullable()->after('bir_receipt_file_name');
            $table->string('bir_receipt_time_received_by_bir', 120)->nullable()->after('bir_receipt_date_received_by_bir');
            $table->string('bir_receipt_tin', 32)->nullable()->after('bir_receipt_time_received_by_bir');
            $table->foreignId('matched_form_1702_ex_batch_row_id')
                ->nullable()
                ->after('bir_receipt_tin')
                ->constrained('form_1702_ex_batch_rows')
                ->nullOnDelete();
            $table->string('bir_receipt_match_status', 32)->nullable()->after('matched_form_1702_ex_batch_row_id');
            $table->timestamp('bir_receipt_queued_at')->nullable()->after('bir_receipt_match_status');
            $table->timestamp('bir_receipt_applied_at')->nullable()->after('bir_receipt_queued_at');
            $table->text('bir_receipt_match_error')->nullable()->after('bir_receipt_applied_at');

            $table->index(['user_id', 'bir_receipt_tin']);
            $table->index(['user_id', 'bir_receipt_match_status']);
        });

        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->foreignId('auto_receipt_synced_email_id')
                ->nullable()
                ->after('receipt_job_error')
                ->constrained('synced_emails')
                ->nullOnDelete();
            $table->string('auto_receipt_status', 32)->nullable()->after('auto_receipt_synced_email_id');
            $table->text('auto_receipt_error')->nullable()->after('auto_receipt_status');

            $table->index(['form_1702_ex_batch_id', 'auto_receipt_status']);
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->dropIndex(['form_1702_ex_batch_id', 'auto_receipt_status']);
            $table->dropConstrainedForeignId('auto_receipt_synced_email_id');
            $table->dropColumn([
                'auto_receipt_status',
                'auto_receipt_error',
            ]);
        });

        Schema::table('synced_emails', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'bir_receipt_tin']);
            $table->dropIndex(['user_id', 'bir_receipt_match_status']);
            $table->dropConstrainedForeignId('matched_form_1702_ex_batch_row_id');
            $table->dropColumn([
                'bir_receipt_file_name',
                'bir_receipt_date_received_by_bir',
                'bir_receipt_time_received_by_bir',
                'bir_receipt_tin',
                'bir_receipt_match_status',
                'bir_receipt_queued_at',
                'bir_receipt_applied_at',
                'bir_receipt_match_error',
            ]);
        });
    }
};
