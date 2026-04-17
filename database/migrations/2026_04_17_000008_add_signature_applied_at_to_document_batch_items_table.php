<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_batch_items', function (Blueprint $table): void {
            $table->timestamp('signature_applied_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_batch_items', function (Blueprint $table): void {
            $table->dropColumn('signature_applied_at');
        });
    }
};

