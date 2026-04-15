<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_1702_ex_batches', function (Blueprint $table): void {
            $table->string('import_status', 32)
                ->nullable()
                ->after('receipt_acceptance_start_date');
            $table->text('import_error')
                ->nullable()
                ->after('import_status');
            $table->string('import_source_path', 500)
                ->nullable()
                ->after('import_error');
            $table->string('import_source_name', 255)
                ->nullable()
                ->after('import_source_path');
            $table->dateTime('import_completed_at')
                ->nullable()
                ->after('import_source_name');

            $table->index(['user_id', 'import_status']);
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batches', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'import_status']);
            $table->dropColumn([
                'import_status',
                'import_error',
                'import_source_path',
                'import_source_name',
                'import_completed_at',
            ]);
        });
    }
};
