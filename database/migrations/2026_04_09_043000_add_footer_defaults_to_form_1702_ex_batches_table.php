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
            $table->string('footer_source_path', 500)
                ->nullable()
                ->after('file_name_prefix');
            $table->string('footer_printed_date', 64)
                ->nullable()
                ->after('footer_source_path');
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batches', function (Blueprint $table): void {
            $table->dropColumn([
                'footer_source_path',
                'footer_printed_date',
            ]);
        });
    }
};
