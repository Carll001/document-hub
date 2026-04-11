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
            $table->date('receipt_acceptance_start_date')
                ->nullable()
                ->after('footer_printed_date');

            $table->index(['user_id', 'receipt_acceptance_start_date']);
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batches', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'receipt_acceptance_start_date']);
            $table->dropColumn('receipt_acceptance_start_date');
        });
    }
};
