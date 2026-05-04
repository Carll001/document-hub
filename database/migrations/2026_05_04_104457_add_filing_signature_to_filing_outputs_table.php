<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filing_outputs', function (Blueprint $table): void {
            $table->string('filing_signature')->nullable()->after('president_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('filing_outputs', function (Blueprint $table): void {
            $table->dropColumn('filing_signature');
        });
    }
};

