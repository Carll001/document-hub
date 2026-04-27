<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afs_filing_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('row_data');
            $table->string('status')->default('queued');
            $table->string('docx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_details')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('signature_applied_at')->nullable();
            $table->string('source_excel_name')->nullable();
            $table->string('template_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'row_number']);
        });

        Schema::create('afs_filing_item_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('afs_filing_item_id')->constrained('afs_filing_items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->text('summary');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['afs_filing_item_id', 'created_at']);
        });

        if (Schema::hasTable('document_batch_items') && Schema::hasTable('document_batches')) {
            DB::table('document_batch_items as i')
                ->join('document_batches as b', 'b.id', '=', 'i.document_batch_id')
                ->orderBy('i.id')
                ->select([
                    'i.id',
                    'b.user_id',
                    'i.row_number',
                    'i.row_data',
                    'i.status',
                    'i.docx_path',
                    'i.pdf_path',
                    'i.error_message',
                    'i.error_details',
                    'i.started_at',
                    'i.completed_at',
                    'i.signature_applied_at',
                    'b.source_excel_name',
                    'b.template_name',
                    'i.created_at',
                    'i.updated_at',
                    'i.deleted_at',
                ])
                ->chunk(500, function ($rows): void {
                    $payload = [];

                    foreach ($rows as $row) {
                        $payload[] = [
                            'id' => (int) $row->id,
                            'user_id' => (int) $row->user_id,
                            'row_number' => (int) $row->row_number,
                            'row_data' => $row->row_data,
                            'status' => (string) $row->status,
                            'docx_path' => $row->docx_path,
                            'pdf_path' => $row->pdf_path,
                            'error_message' => $row->error_message,
                            'error_details' => $row->error_details,
                            'started_at' => $row->started_at,
                            'completed_at' => $row->completed_at,
                            'signature_applied_at' => $row->signature_applied_at,
                            'source_excel_name' => $row->source_excel_name,
                            'template_name' => $row->template_name,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                            'deleted_at' => $row->deleted_at,
                        ];
                    }

                    if ($payload !== []) {
                        DB::table('afs_filing_items')->insert($payload);
                    }
                });
        }

        if (Schema::hasTable('document_batch_item_activity_logs')) {
            DB::table('document_batch_item_activity_logs')
                ->orderBy('id')
                ->select(['document_batch_item_id', 'user_id', 'action', 'summary', 'details', 'created_at', 'updated_at'])
                ->chunk(500, function ($rows): void {
                    $payload = [];

                    foreach ($rows as $row) {
                        if (! DB::table('afs_filing_items')->where('id', (int) $row->document_batch_item_id)->exists()) {
                            continue;
                        }

                        $payload[] = [
                            'afs_filing_item_id' => (int) $row->document_batch_item_id,
                            'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                            'action' => (string) $row->action,
                            'summary' => (string) $row->summary,
                            'details' => $row->details,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                    }

                    if ($payload !== []) {
                        DB::table('afs_filing_item_activity_logs')->insert($payload);
                    }
                });
        }

        $this->syncPrimaryKeySequence('afs_filing_items');
        $this->syncPrimaryKeySequence('afs_filing_item_activity_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('afs_filing_item_activity_logs');
        Schema::dropIfExists('afs_filing_items');
    }

    private function syncPrimaryKeySequence(string $table): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), true)"
            );

            return;
        }

        if ($driver === 'mysql') {
            $nextId = ((int) DB::table($table)->max('id')) + 1;
            DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = {$nextId}");
        }
    }
};
