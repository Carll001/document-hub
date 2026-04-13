<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sync_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('display_name');
            $table->string('username');
            $table->text('password');
            $table->string('host');
            $table->unsignedInteger('port')->default(993);
            $table->string('encryption', 20)->default('ssl');
            $table->string('mailbox')->default('INBOX');
            $table->boolean('validate_certificate')->default(true);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('synced_emails', function (Blueprint $table): void {
            if (! Schema::hasColumn('synced_emails', 'email_sync_account_id')) {
                $table->foreignId('email_sync_account_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('email_sync_accounts')
                    ->nullOnDelete();
            }
        });

        DB::statement('DROP INDEX IF EXISTS synced_emails_mailbox_imap_uid_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS synced_emails_email_sync_account_id_mailbox_imap_uid_unique ON synced_emails (email_sync_account_id, mailbox, imap_uid)');
        DB::statement('CREATE INDEX IF NOT EXISTS synced_emails_email_sync_account_id_received_at_index ON synced_emails (email_sync_account_id, received_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS synced_emails_email_sync_account_id_mailbox_imap_uid_unique');
        DB::statement('DROP INDEX IF EXISTS synced_emails_email_sync_account_id_received_at_index');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS synced_emails_mailbox_imap_uid_unique ON synced_emails (mailbox, imap_uid)');

        Schema::table('synced_emails', function (Blueprint $table): void {
            if (Schema::hasColumn('synced_emails', 'email_sync_account_id')) {
                $table->dropConstrainedForeignId('email_sync_account_id');
            }
        });

        Schema::dropIfExists('email_sync_accounts');
    }
};
