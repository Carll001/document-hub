<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_generator_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('processed_signature_path');
            $table->string('original_signature_path')->nullable();
            $table->string('anchor', 32)->default('bottom_right');
            $table->decimal('offset_x', 8, 2)->default(0);
            $table->decimal('offset_y', 8, 2)->default(0);
            $table->decimal('width', 8, 2)->default(40);
            $table->decimal('height', 8, 2)->default(16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_generator_signatures');
    }
};

