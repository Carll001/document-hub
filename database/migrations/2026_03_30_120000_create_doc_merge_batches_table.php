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
        if (Schema::hasTable('doc_merge_batches')) {
            return;
        }

        Schema::create('doc_merge_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name', 120);
            $table->timestamp('last_processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        if (Schema::hasTable('users')) {
            Schema::table('doc_merge_batches', function (Blueprint $table) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_merge_batches');
    }
};
