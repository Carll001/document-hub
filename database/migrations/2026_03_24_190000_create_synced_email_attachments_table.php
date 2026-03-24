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
        Schema::create('synced_email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('synced_email_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('storage_path');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamps();

            $table->index('synced_email_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('synced_email_attachments');
    }
};
