<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('form_1702_ex_file_name_prefix', 120)->nullable()->after('email_auto_sync_enabled');
            $table->string('form_1702_ex_footer_source_path', 500)->nullable()->after('form_1702_ex_file_name_prefix');
            $table->string('form_1702_ex_footer_printed_date', 64)->nullable()->after('form_1702_ex_footer_source_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'form_1702_ex_file_name_prefix',
                'form_1702_ex_footer_source_path',
                'form_1702_ex_footer_printed_date',
            ]);
        });
    }
};
