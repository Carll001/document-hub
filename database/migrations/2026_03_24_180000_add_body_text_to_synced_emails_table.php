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
        Schema::table('synced_emails', function (Blueprint $table) {
            $table->longText('body_text')->nullable()->after('body_preview');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('synced_emails', function (Blueprint $table) {
            $table->dropColumn('body_text');
        });
    }
};
