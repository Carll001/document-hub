<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_sync_accounts', function (Blueprint $table): void {
            $table->string('processing_status', 32)->nullable()->after('is_active');
            $table->string('processing_action', 64)->nullable()->after('processing_status');
            $table->string('processing_run_uuid', 36)->nullable()->after('processing_action');
            $table->text('processing_error')->nullable()->after('processing_run_uuid');
            $table->dateTime('processing_started_at')->nullable()->after('processing_error');
            $table->string('last_sync_run_uuid', 36)->nullable()->after('processing_started_at');
            $table->string('last_sync_action', 64)->nullable()->after('last_sync_run_uuid');
            $table->unsignedInteger('last_sync_fetched_count')->nullable()->after('last_sync_action');
            $table->unsignedInteger('last_sync_created_count')->nullable()->after('last_sync_fetched_count');
            $table->unsignedInteger('last_sync_updated_count')->nullable()->after('last_sync_created_count');
            $table->dateTime('last_sync_completed_at')->nullable()->after('last_sync_updated_count');

            $table->index(['is_active', 'processing_status']);
            $table->index('processing_run_uuid');
            $table->index('last_sync_run_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('email_sync_accounts', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'processing_status']);
            $table->dropIndex(['processing_run_uuid']);
            $table->dropIndex(['last_sync_run_uuid']);
            $table->dropColumn([
                'processing_status',
                'processing_action',
                'processing_run_uuid',
                'processing_error',
                'processing_started_at',
                'last_sync_run_uuid',
                'last_sync_action',
                'last_sync_fetched_count',
                'last_sync_created_count',
                'last_sync_updated_count',
                'last_sync_completed_at',
            ]);
        });
    }
};
