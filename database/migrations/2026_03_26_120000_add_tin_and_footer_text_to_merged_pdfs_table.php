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
            $table->string('tin_number')->nullable()->after('source_file_names');
            $table->string('footer_text', 160)->nullable()->after('tin_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_pdfs', function (Blueprint $table) {
            $table->dropColumn(['tin_number', 'footer_text']);
        });
    }
};
