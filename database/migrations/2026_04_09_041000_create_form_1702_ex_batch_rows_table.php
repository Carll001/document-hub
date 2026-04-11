<?php

declare(strict_types=1);

use App\Models\Form1702ExBatchRow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_1702_ex_batch_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_1702_ex_batch_id')
                ->constrained('form_1702_ex_batches')
                ->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('source_name');
            $table->string('source_type', 16);
            $table->unsignedInteger('source_row_number');
            $table->timestamp('uploaded_at');
            $table->json('payload');
            $table->string('pdf_status', 20)->default(Form1702ExBatchRow::PDF_STATUS_QUEUED);
            $table->text('pdf_error')->nullable();
            $table->string('generated_pdf_file_name')->nullable();
            $table->string('generated_pdf_storage_path')->nullable();
            $table->unsignedBigInteger('generated_pdf_file_size')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['form_1702_ex_batch_id', 'uploaded_at']);
            $table->index(['form_1702_ex_batch_id', 'pdf_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_1702_ex_batch_rows');
    }
};
