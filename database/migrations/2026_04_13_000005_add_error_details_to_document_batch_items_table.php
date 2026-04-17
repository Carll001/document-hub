<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_batch_items', function (Blueprint $table): void {
            $table->json('error_details')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('document_batch_items', function (Blueprint $table): void {
            $table->dropColumn('error_details');
        });
    }
};
