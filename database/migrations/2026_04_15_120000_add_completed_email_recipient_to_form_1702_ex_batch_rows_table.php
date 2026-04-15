<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->string('completed_email_recipient')
                ->nullable()
                ->after('completed_email_auto_recipient');
        });
    }

    public function down(): void
    {
        Schema::table('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->dropColumn('completed_email_recipient');
        });
    }
};
