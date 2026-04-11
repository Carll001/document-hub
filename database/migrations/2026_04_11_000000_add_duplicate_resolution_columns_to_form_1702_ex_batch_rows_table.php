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
            $table->string('duplicate_resolution_status', 32)
                ->nullable()
                ->after('auto_receipt_error');
            $table->foreignId('duplicate_of_form_1702_ex_batch_row_id')
                ->nullable()
                ->after('duplicate_resolution_status')
                ->constrained('form_1702_ex_batch_rows')
                ->nullOnDelete();
            $table->timestamp('duplicate_resolved_at')
                ->nullable()
                ->after('duplicate_of_form_1702_ex_batch_row_id');

            $table->index(['form_1702_ex_batch_id', 'duplicate_resolution_status']);
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->dropIndex(['form_1702_ex_batch_id', 'duplicate_resolution_status']);
            $table->dropConstrainedForeignId('duplicate_of_form_1702_ex_batch_row_id');
            $table->dropColumn([
                'duplicate_resolution_status',
                'duplicate_resolved_at',
            ]);
        });
    }
};
