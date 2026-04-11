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
            $table->string('file_name_prefix', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batches', function (Blueprint $table): void {
            $table->dropColumn('file_name_prefix');
        });
    }
};
