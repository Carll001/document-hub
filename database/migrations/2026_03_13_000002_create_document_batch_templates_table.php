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
        Schema::create('document_batch_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('year')->nullable();
            $table->string('template_name');
            $table->string('template_path');
            $table->timestamps();

            $table->unique(['document_batch_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_batch_templates');
    }
};
