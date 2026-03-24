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
            $table->longText('body_html')->nullable()->after('body_text');
        });

        Schema::table('synced_email_attachments', function (Blueprint $table) {
            $table->string('content_id')->nullable()->after('content_type');
            $table->boolean('is_inline')->default(false)->after('content_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('synced_email_attachments', function (Blueprint $table) {
            $table->dropColumn(['content_id', 'is_inline']);
        });

        Schema::table('synced_emails', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
