<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merged_pdfs', function (Blueprint $table) {
            $table->string('receipt_file_name')->nullable()->after('source_file_names');
            $table->string('receipt_storage_path')->nullable()->after('receipt_file_name');
            $table->unsignedBigInteger('receipt_file_size')->nullable()->after('receipt_storage_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_pdfs', function (Blueprint $table) {
            $table->dropColumn([
                'receipt_file_name',
                'receipt_storage_path',
                'receipt_file_size',
            ]);
        });
    }
};
