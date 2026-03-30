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
        if (Schema::hasTable('doc_merge_batch_source_files')) {
            return;
        }

        Schema::create('doc_merge_batch_source_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_merge_batch_id');
            $table->string('page_folder_name', 120);
            $table->unsignedInteger('page_folder_number');
            $table->string('display_name');
            $table->string('storage_path');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('match_key');
            $table->string('group_label');
            $table->timestamps();

            $table->unique(
                ['doc_merge_batch_id', 'page_folder_number', 'match_key'],
                'doc_merge_batch_source_files_folder_match_unique',
            );
            $table->index(
                ['doc_merge_batch_id', 'page_folder_number', 'display_name'],
                'doc_merge_batch_source_files_folder_display_index',
            );
        });

        if (Schema::hasTable('doc_merge_batches')) {
            Schema::table('doc_merge_batch_source_files', function (Blueprint $table) {
                $table->foreign('doc_merge_batch_id')
                    ->references('id')
                    ->on('doc_merge_batches')
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_merge_batch_source_files');
    }
};
