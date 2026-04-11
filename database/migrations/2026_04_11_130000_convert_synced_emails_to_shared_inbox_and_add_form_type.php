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
        DB::transaction(function (): void {
            DB::statement('
                DELETE FROM synced_emails duplicate
                USING synced_emails original
                WHERE duplicate.mailbox = original.mailbox
                  AND duplicate.imap_uid = original.imap_uid
                  AND duplicate.id > original.id
            ');

            Schema::table('synced_emails', function (Blueprint $table): void {
                $table->string('bir_receipt_form_type', 64)
                    ->nullable()
                    ->after('bir_receipt_tin');
            });

            DB::statement('ALTER TABLE synced_emails DROP CONSTRAINT IF EXISTS synced_emails_user_id_foreign');
            DB::statement('ALTER TABLE synced_emails ALTER COLUMN user_id DROP NOT NULL');
            DB::statement('UPDATE synced_emails SET user_id = NULL');

            DB::statement('ALTER TABLE synced_emails DROP CONSTRAINT IF EXISTS synced_emails_user_id_mailbox_imap_uid_unique');
            DB::statement('DROP INDEX IF EXISTS synced_emails_user_id_received_at_index');
            DB::statement('DROP INDEX IF EXISTS synced_emails_user_id_bir_receipt_tin_index');
            DB::statement('DROP INDEX IF EXISTS synced_emails_user_id_bir_receipt_match_status_index');

            DB::statement('CREATE UNIQUE INDEX synced_emails_mailbox_imap_uid_unique ON synced_emails (mailbox, imap_uid)');
            DB::statement('CREATE INDEX synced_emails_received_at_index ON synced_emails (received_at)');
            DB::statement('CREATE INDEX synced_emails_bir_receipt_tin_index ON synced_emails (bir_receipt_tin)');
            DB::statement('CREATE INDEX synced_emails_bir_receipt_match_status_index ON synced_emails (bir_receipt_match_status)');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('DROP INDEX IF EXISTS synced_emails_mailbox_imap_uid_unique');
            DB::statement('DROP INDEX IF EXISTS synced_emails_received_at_index');
            DB::statement('DROP INDEX IF EXISTS synced_emails_bir_receipt_tin_index');
            DB::statement('DROP INDEX IF EXISTS synced_emails_bir_receipt_match_status_index');

            Schema::table('synced_emails', function (Blueprint $table): void {
                $table->dropColumn('bir_receipt_form_type');
            });
        });
    }
};
