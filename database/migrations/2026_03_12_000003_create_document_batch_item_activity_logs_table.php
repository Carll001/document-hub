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
        Schema::create('document_batch_item_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_batch_item_id')->constrained('document_batch_items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->text('summary');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['document_batch_id', 'created_at']);
            $table->index(['document_batch_item_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_batch_item_activity_logs');
    }
};
