<?php

declare(strict_types=1);

use App\Services\EmailSync\BirReceiptAutoMatchService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->collapseDuplicateEmails();
        $this->dropLegacyIndexes();

        Schema::table('synced_emails', function (Blueprint $table): void {
            if (! Schema::hasColumn('synced_emails', 'claimed_by_user_id')) {
                $table->foreignId('claimed_by_user_id')
                    ->nullable()
                    ->after('matched_form_1702_ex_batch_row_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('synced_emails', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable()->after('claimed_by_user_id');
            }
        });

        DB::table('synced_emails')
            ->whereIn('bir_receipt_match_status', [
                BirReceiptAutoMatchService::MATCH_STATUS_PENDING_PDF,
                BirReceiptAutoMatchService::MATCH_STATUS_QUEUED,
                BirReceiptAutoMatchService::MATCH_STATUS_APPLIED,
                BirReceiptAutoMatchService::MATCH_STATUS_FAILED,
            ])
            ->whereNotNull('user_id')
            ->update([
                'claimed_by_user_id' => DB::raw('user_id'),
                'claimed_at' => DB::raw('COALESCE(bir_receipt_applied_at, bir_receipt_queued_at, updated_at, created_at)'),
            ]);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS synced_emails_mailbox_imap_uid_unique ON synced_emails (mailbox, imap_uid)');
        DB::statement('CREATE INDEX IF NOT EXISTS synced_emails_claimed_by_user_id_received_at_index ON synced_emails (claimed_by_user_id, received_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS synced_emails_claimed_by_user_id_bir_receipt_tin_index ON synced_emails (claimed_by_user_id, bir_receipt_tin)');
        DB::statement('CREATE INDEX IF NOT EXISTS synced_emails_claimed_by_user_id_bir_receipt_match_status_index ON synced_emails (claimed_by_user_id, bir_receipt_match_status)');

        if (Schema::hasColumn('synced_emails', 'user_id')) {
            DB::statement('ALTER TABLE synced_emails DROP CONSTRAINT IF EXISTS synced_emails_user_id_foreign');
            Schema::table('synced_emails', function (Blueprint $table): void {
                $table->dropColumn('user_id');
            });
        }
    }

    public function down(): void
    {
        $this->dropSharedIndexes();

        if (! Schema::hasColumn('synced_emails', 'user_id')) {
            Schema::table('synced_emails', function (Blueprint $table): void {
                $table->foreignId('user_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->nullOnDelete();
            });
        }

        DB::table('synced_emails')
            ->whereNull('user_id')
            ->whereNotNull('claimed_by_user_id')
            ->update([
                'user_id' => DB::raw('claimed_by_user_id'),
            ]);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS synced_emails_user_id_mailbox_imap_uid_unique ON synced_emails (user_id, mailbox, imap_uid)');
        DB::statement('CREATE INDEX IF NOT EXISTS synced_emails_user_id_received_at_index ON synced_emails (user_id, received_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS synced_emails_user_id_bir_receipt_tin_index ON synced_emails (user_id, bir_receipt_tin)');
        DB::statement('CREATE INDEX IF NOT EXISTS synced_emails_user_id_bir_receipt_match_status_index ON synced_emails (user_id, bir_receipt_match_status)');

        DB::statement('ALTER TABLE synced_emails DROP CONSTRAINT IF EXISTS synced_emails_claimed_by_user_id_foreign');

        Schema::table('synced_emails', function (Blueprint $table): void {
            if (Schema::hasColumn('synced_emails', 'claimed_by_user_id')) {
                $table->dropColumn('claimed_by_user_id');
            }

            if (Schema::hasColumn('synced_emails', 'claimed_at')) {
                $table->dropColumn('claimed_at');
            }
        });
    }

    private function collapseDuplicateEmails(): void
    {
        $duplicateKeys = DB::table('synced_emails')
            ->select('mailbox', 'imap_uid')
            ->groupBy('mailbox', 'imap_uid')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateKeys as $duplicateKey) {
            /** @var Collection<int, object> $emails */
            $emails = collect(
                DB::table('synced_emails')
                    ->where('mailbox', $duplicateKey->mailbox)
                    ->where('imap_uid', $duplicateKey->imap_uid)
                    ->orderBy('id')
                    ->get()
            );

            $winner = $emails
                ->sortBy(fn (object $email): int => (int) $email->id)
                ->sortByDesc(fn (object $email): int => $this->emailScore($email))
                ->first();

            if ($winner === null) {
                continue;
            }

            foreach ($emails->reject(fn (object $email): bool => (int) $email->id === (int) $winner->id) as $loser) {
                DB::table('synced_email_attachments')
                    ->where('synced_email_id', $loser->id)
                    ->update(['synced_email_id' => $winner->id]);

                DB::table('form_1702_ex_batch_rows')
                    ->where('auto_receipt_synced_email_id', $loser->id)
                    ->update(['auto_receipt_synced_email_id' => $winner->id]);

                DB::table('synced_emails')
                    ->where('id', $winner->id)
                    ->update($this->mergedEmailValues($winner, $loser));

                DB::table('synced_emails')
                    ->where('id', $loser->id)
                    ->delete();
            }
        }
    }

    private function dropLegacyIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS synced_emails_user_id_received_at_index');
        DB::statement('DROP INDEX IF EXISTS synced_emails_user_id_bir_receipt_tin_index');
        DB::statement('DROP INDEX IF EXISTS synced_emails_user_id_bir_receipt_match_status_index');
        DB::statement('DROP INDEX IF EXISTS synced_emails_user_id_mailbox_imap_uid_unique');
        DB::statement('DROP INDEX IF EXISTS synced_emails_mailbox_imap_uid_unique');
    }

    private function dropSharedIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS synced_emails_claimed_by_user_id_received_at_index');
        DB::statement('DROP INDEX IF EXISTS synced_emails_claimed_by_user_id_bir_receipt_tin_index');
        DB::statement('DROP INDEX IF EXISTS synced_emails_claimed_by_user_id_bir_receipt_match_status_index');
        DB::statement('DROP INDEX IF EXISTS synced_emails_mailbox_imap_uid_unique');
    }

    private function emailScore(object $email): int
    {
        return match ((string) $email->bir_receipt_match_status) {
            BirReceiptAutoMatchService::MATCH_STATUS_APPLIED => 70,
            BirReceiptAutoMatchService::MATCH_STATUS_QUEUED => 60,
            BirReceiptAutoMatchService::MATCH_STATUS_PENDING_PDF => 50,
            BirReceiptAutoMatchService::MATCH_STATUS_FAILED => 40,
            BirReceiptAutoMatchService::MATCH_STATUS_UNMATCHED => 30,
            BirReceiptAutoMatchService::MATCH_STATUS_NO_TIN => 20,
            BirReceiptAutoMatchService::MATCH_STATUS_NO_DETAILS => 10,
            default => 0,
        } + ($email->matched_form_1702_ex_batch_row_id !== null ? 5 : 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedEmailValues(object $winner, object $loser): array
    {
        return [
            'message_id' => $winner->message_id ?? $loser->message_id,
            'from_name' => $winner->from_name ?? $loser->from_name,
            'from_email' => $winner->from_email ?? $loser->from_email,
            'subject' => $winner->subject ?? $loser->subject,
            'body_preview' => $winner->body_preview ?? $loser->body_preview,
            'body_text' => $winner->body_text ?? $loser->body_text,
            'body_html' => $winner->body_html ?? $loser->body_html,
            'received_at' => $winner->received_at ?? $loser->received_at,
            'synced_at' => $winner->synced_at ?? $loser->synced_at,
            'bir_receipt_file_name' => $winner->bir_receipt_file_name ?? $loser->bir_receipt_file_name,
            'bir_receipt_date_received_by_bir' => $winner->bir_receipt_date_received_by_bir ?? $loser->bir_receipt_date_received_by_bir,
            'bir_receipt_time_received_by_bir' => $winner->bir_receipt_time_received_by_bir ?? $loser->bir_receipt_time_received_by_bir,
            'bir_receipt_tin' => $winner->bir_receipt_tin ?? $loser->bir_receipt_tin,
            'matched_form_1702_ex_batch_row_id' => $winner->matched_form_1702_ex_batch_row_id ?? $loser->matched_form_1702_ex_batch_row_id,
            'bir_receipt_match_status' => $this->emailScore($winner) >= $this->emailScore($loser)
                ? $winner->bir_receipt_match_status
                : $loser->bir_receipt_match_status,
            'bir_receipt_queued_at' => $winner->bir_receipt_queued_at ?? $loser->bir_receipt_queued_at,
            'bir_receipt_applied_at' => $winner->bir_receipt_applied_at ?? $loser->bir_receipt_applied_at,
            'bir_receipt_match_error' => $winner->bir_receipt_match_error ?? $loser->bir_receipt_match_error,
            'updated_at' => $winner->updated_at ?? $loser->updated_at,
        ];
    }
};
