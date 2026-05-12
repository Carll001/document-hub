<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_merge_batch_chunk_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doc_merge_batch_id')->constrained('doc_merge_batches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('status', 32)->default('initiated');
            $table->timestamp('expires_at')->index();
            $table->json('manifest_json');
            $table->json('progress_json')->nullable();
            $table->json('assembled_files_json')->nullable();
            $table->timestamps();

            $table->index(['doc_merge_batch_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_merge_batch_chunk_uploads');
    }
};

