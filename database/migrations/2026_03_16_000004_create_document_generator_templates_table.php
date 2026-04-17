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
        Schema::create('document_generator_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('year')->nullable();
            $table->string('template_name');
            $table->string('template_path');
            $table->timestamps();

            $table->unique('year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_generator_templates');
    }
};
