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
            $table->string('completed_email_auto_hash', 64)
                ->nullable()
                ->after('auto_receipt_error');
            $table->string('completed_email_auto_recipient')
                ->nullable()
                ->after('completed_email_auto_hash');
            $table->timestamp('completed_email_auto_queued_at')
                ->nullable()
                ->after('completed_email_auto_recipient');

            $table->index(['form_1702_ex_batch_id', 'completed_email_auto_queued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->dropIndex(['form_1702_ex_batch_id', 'completed_email_auto_queued_at']);
            $table->dropColumn([
                'completed_email_auto_hash',
                'completed_email_auto_recipient',
                'completed_email_auto_queued_at',
            ]);
        });
    }
};
